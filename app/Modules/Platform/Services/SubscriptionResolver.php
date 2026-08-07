<?php

declare(strict_types=1);

namespace App\Modules\Platform\Services;

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
 * `subscriptions` is not tenant-scoped by RLS (it is the platform's record, not the
 * shop's), so lookups here are explicit `where tenant_id` rather than implicit.
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
     * Module codes the current tenant may use.
     *
     * An unsubscribed or lapsed tenant gets an EMPTY list, not the core modules. The
     * gating layer fails closed for the same reason RLS does: the safe failure is
     * "nothing works and someone complains", not "everything is free".
     *
     * @return list<string>
     */
    public function grantedModuleCodes(): array
    {
        $subscription = $this->current();

        if (! $subscription instanceof Subscription || ! $subscription->isUsable()) {
            return [];
        }

        return $subscription->grantedModuleCodes();
    }

    public function grants(string $moduleCode): bool
    {
        return in_array($moduleCode, $this->grantedModuleCodes(), true);
    }

    /**
     * A plan limit's value, or null for unlimited / no subscription.
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

    private function forTenantId(int $tenantId): ?Subscription
    {
        if (array_key_exists($tenantId, $this->cache)) {
            return $this->cache[$tenantId];
        }

        $subscription = Subscription::query()
            ->with(['plan.modules', 'plan.limits', 'addons.module'])
            ->where('tenant_id', $tenantId)
            // A tenant can accumulate canceled rows over time; the live one is the
            // most recent.
            ->orderByDesc('id')
            ->first();

        return $this->cache[$tenantId] = $subscription;
    }
}
