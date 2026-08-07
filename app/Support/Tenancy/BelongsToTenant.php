<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use App\Modules\Platform\Models\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Layer 2 of tenant isolation: the Eloquent global scope and the auto-fill on create
 * ([ADR 0002](../../../docs/adr/0002-single-db-tenancy-rls.md)).
 *
 * Every tenant-owned model uses this trait. `php artisan tenancy:check` fails the
 * build when a model backed by a table with a `tenant_id` column does not.
 *
 * This layer exists for ergonomics and for defence in depth, **not** as the security
 * boundary — RLS is. That ordering matters: it means a forgotten `->withoutGlobalScopes()`
 * is a bug rather than a breach.
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function (self $model): void {
            // Only fill when the caller has not set it deliberately. A Platform-module
            // process legitimately creates rows for a tenant other than "current".
            if ($model->getAttribute('tenant_id') === null) {
                $model->setAttribute('tenant_id', app(TenantContext::class)->idOrFail());
            }
        });
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        /** @var BelongsTo<Tenant, $this> */
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Drop the Eloquent scope for one query.
     *
     * **Platform module only**, and every call site must carry a comment saying why
     * (golden rule 1). Note what this does and does not do: it removes the *Eloquent*
     * filter, so the query still runs under RLS and still sees only the current
     * tenant's rows. To genuinely read across tenants you also need the context set to
     * each one in turn — there is no single query that returns everybody's data, and
     * that is deliberate.
     *
     * @return Builder<covariant \Illuminate\Database\Eloquent\Model>
     */
    public static function withoutTenancy(): Builder
    {
        return static::query()->withoutGlobalScope(TenantScope::class);
    }
}
