<?php

declare(strict_types=1);

namespace App\Modules\Identity\Models;

use App\Support\Audit\Redactor;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Spatie\Activitylog\Models\Activity as BaseActivity;

/**
 * Tenant-aware, secret-free audit record.
 *
 * ## Tenancy
 *
 * spatie's model knows nothing about tenancy, so every entry it wrote arrived with a
 * null `tenant_id`. Under the RLS policy on `activity_log` that is not a silent
 * mis-attribution — it is a rejected INSERT, because a row with no tenant fails the
 * policy's WITH CHECK while a tenant context is active. The isolation layer caught it
 * before any data was written, which is exactly the behaviour we wanted from it.
 *
 * So the tenant is stamped here, at the one place every activity row passes through.
 *
 * `tenant_id` stays nullable on purpose: central actions (a platform admin suspending
 * a shop, an impersonation starting) genuinely belong to no tenant, and the null-
 * tolerant policy keeps those visible only in the central context.
 *
 * ## Secrets
 *
 * The same "one place every row passes through" is why redaction lives here rather
 * than in the viewer. A guard in the controller would protect the reader while the
 * secret still sat in the table, readable by a database console, a backup, a support
 * export, or the next screen somebody writes over this data. Masking on the way in
 * means the clear value is never stored at all.
 *
 * **Both payload columns, and that is not belt-and-braces.** spatie v5 splits what v4
 * kept together: the automatic before/after that `LogsActivity` writes goes to
 * `attribute_changes`, and only a hand-written `->withProperties([...])` reaches
 * `properties`. Guarding `properties` alone — the obvious reading of the column names,
 * and what this file did when it was written — would mask nothing at all for exactly
 * the models most likely to carry a secret, since an audited *model* writes down the
 * first column and never the second.
 *
 * {@see Redactor} for how the secret list is derived rather than maintained.
 */
final class Activity extends BaseActivity
{
    protected static function booted(): void
    {
        self::addGlobalScope('tenant', self::scopeToCurrentTenant(...));

        self::creating(function (self $activity): void {
            if ($activity->getAttribute('tenant_id') === null) {
                $activity->setAttribute('tenant_id', app(TenantContext::class)->id());
            }

            $activity->redactPayload('attribute_changes');
            $activity->redactPayload('properties');
        });
    }

    /**
     * Confine every read to the current context, the way `BelongsToTenant` does.
     *
     * ## This is not the security boundary, and it is not redundant either
     *
     * RLS already enforces exactly this, and would still enforce it if this scope were
     * deleted — the policy is ANDed into the query by Postgres regardless. What the
     * scope buys is a predicate the *planner* can use.
     *
     * The policy on this table is the null-tolerant kind, because the table holds
     * central rows (a platform admin suspending a shop) beside tenant ones. Written
     * either as `IS NOT DISTINCT FROM` or as an OR over `current_setting()`, it is
     * something Postgres cannot reduce to a plain equality at plan time: the first
     * form is not indexable at all, and the second forces a BitmapOr that reads every
     * row of the tenant's history and sorts it before the LIMIT can apply.
     *
     * The application does not have that problem, because at the moment the query is
     * built it already knows which of the two cases it is in. So it says so, and the
     * planner gets `tenant_id = 4` — an ordinary indexable equality that walks
     * `(tenant_id, created_at)` backwards and stops after fifty rows.
     *
     * Measured on 1.8M rows, fifty shops, a year of history:
     *
     * | query                                   | plan               | time     |
     * |-----------------------------------------|--------------------|----------|
     * | RLS alone, `IS NOT DISTINCT FROM`       | Parallel Seq Scan  | 55.8 ms  |
     * | RLS alone, OR form                      | BitmapOr + sort    | 112 ms   |
     * | RLS **and** this scope                  | Index Scan Backward| 0.6 ms   |
     *
     * `BelongsToTenant` is not used instead because its scope and its auto-fill both
     * assume a tenant is always present — `idOrFail()` would throw on the central
     * rows this table exists to hold.
     *
     * @param  Builder<self>  $query
     */
    private static function scopeToCurrentTenant(Builder $query): void
    {
        $tenantId = app(TenantContext::class)->id();

        // Table-qualified: an audit query that joins is still a query about one shop,
        // and an unqualified `tenant_id` would be ambiguous the first time one does.
        $tenantId === null
            ? $query->whereNull('activity_log.tenant_id')
            : $query->where('activity_log.tenant_id', $tenantId);
    }

    /**
     * Replace any secret of the subject model with a mask, in place, before insert.
     *
     * Keyed on `subject_type` because that is what names the model whose `$hidden`
     * and encrypted casts define what a secret is here. A row with no subject — a
     * bare `activity()->log('…')` — has no model to ask, and is left alone: there is
     * no declaration anywhere that would tell us which of its keys were sensitive,
     * and inventing one would be the hand-maintained list the Redactor exists to
     * avoid.
     */
    private function redactPayload(string $column): void
    {
        /** @var Collection<string, mixed>|null $payload */
        $payload = $this->getAttribute($column);

        if ($payload === null || $payload->isEmpty()) {
            return;
        }

        /** @var class-string|null $subjectType */
        $subjectType = $this->getAttribute('subject_type');

        $redacted = app(Redactor::class)->redact($payload->toArray(), $subjectType);

        $this->setAttribute($column, new Collection($redacted));
    }
}
