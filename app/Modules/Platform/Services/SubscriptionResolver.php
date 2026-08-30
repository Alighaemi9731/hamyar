<?php

declare(strict_types=1);

namespace App\Modules\Platform\Services;

use App\Modules\Platform\Models\Module;
use App\Modules\Platform\Models\Subscription;
use App\Modules\Platform\Models\Tenant;
use App\Support\Tenancy\TenantContext;

/**
 * The current tenant's subscription, and what it grants.
 *
 * Resolved once per request and memoised: the feature checks below run on every route
 * and every nav render, and re-querying the plan/module graph each time would put a
 * handful of joins on the hot path of a POS screen.
 *
 * `subscriptions` is RLS-protected but deliberately carries no `BelongsToTenant` scope
 * (ADR 0002 amendment), so lookups here are an explicit `where tenant_id` rather than an
 * implicit one. The database still refuses to return another shop's row.
 */
final class SubscriptionResolver
{
    /** @var array<int, Subscription|null> */
    private array $cache = [];

    public function __construct(private readonly TenantContext $context) {}

    public function current(): ?Subscription
    {
        $tenantId = $this->context->id();

        if ($tenantId === null) {
            return null;
        }

        return $this->forTenantId($tenantId);
    }

    public function forTenant(Tenant $tenant): ?Subscription
    {
        /** @var int $id */
        $id = $tenant->getKey();

        return $this->forTenantId($id);
    }

    /**
     * Module codes this shop may use — which, since DECISION GATE 6, is every module we
     * have switched on.
     *
     * ## Why this no longer consults the subscription
     *
     * It used to return the plan's modules, and an empty list for a lapsed shop — gating
     * that failed closed, on the reasoning that "nothing works and someone complains" beats
     * "everything is free". That reasoning was right for a product where a plan bought
     * modules. It is wrong for one where every module is open and a plan buys *quantity*:
     * a shop whose payment is three days late would find every screen 403ing, conclude the
     * software is broken, and be right to.
     *
     * What fails closed now is the quota layer, which is the layer that can: a lapsed shop
     * reads everything, keeps its own data, and creates nothing beyond the free rung's
     * credits (`LimitResolver`). The module switch remains — as a platform kill-switch for
     * something we have turned off for everybody, which is what ADR 0011 needs for Moadian.
     *
     * @return list<string>
     */
    public function grantedModuleCodes(): array
    {
        return Module::enabledCodes();
    }

    public function grants(string $moduleCode): bool
    {
        return in_array($moduleCode, $this->grantedModuleCodes(), true);
    }

    /**
     * A plan limit's value, or null for unlimited / no subscription.
     *
     * @deprecated Phase 12 — use `App\Modules\Platform\Services\Quota\LimitResolver`,
     *             which also applies per-tenant overrides and the fallback plan a lapsed
     *             shop drops to. This reads the subscribed plan and nothing else, so it is
     *             right only for a shop whose subscription is usable. Kept while the
     *             remaining callers migrate.
     */
    public function limit(string $key): ?int
    {
        $subscription = $this->current();

        if (! $subscription instanceof Subscription) {
            return null;
        }

        return $subscription->plan->limit($key);
    }

    /**
     * Drop the memo — used after a plan change, and by tests.
     */
    public function forget(): void
    {
        $this->cache = [];
    }

    /**
     * The live subscription for a tenant id, memoised.
     *
     * Public since Phase 12: `LimitResolver` needs the same lookup and has an id rather
     * than a model — it is called from `consume()`, on the write path, where fetching a
     * `Tenant` just to pass it in would be a query to avoid a query.
     */
    public function forTenantId(int $tenantId): ?Subscription
    {
        if (array_key_exists($tenantId, $this->cache)) {
            return $this->cache[$tenantId];
        }

        $subscription = Subscription::query()
            ->with(['plan.limits'])
            ->where('tenant_id', $tenantId)
            // A tenant can accumulate canceled rows over time; the live one is the
            // most recent.
            ->orderByDesc('id')
            ->first();

        return $this->cache[$tenantId] = $subscription;
    }
}
