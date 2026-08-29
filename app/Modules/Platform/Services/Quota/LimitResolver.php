<?php

declare(strict_types=1);

namespace App\Modules\Platform\Services\Quota;

use App\Modules\Platform\Models\Plan;
use App\Modules\Platform\Models\Subscription;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Platform\Models\TenantLimitOverride;
use App\Modules\Platform\Services\SubscriptionResolver;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository as Cache;
use RuntimeException;
use Throwable;

/**
 * What this shop's limit for this metric actually is, right now.
 *
 * ## The order, and why each step is where it is
 *
 * 1. **A live override** wins over everything. It is the manual escape every B2B product
 *    needs, and a negotiated deal that a plan change could silently undo would be worse
 *    than no override at all.
 * 2. Otherwise **the plan of the usable subscription**, or — when there is none, or it has
 *    lapsed — the fallback plan from config. A lapsed shop is never locked out; it falls to
 *    the free rung and keeps working (DECISION GATE 6, item 4).
 * 3. Then that plan's `plan_limits` row. Absent means unlimited for that plan, so a module
 *    can ship a new metric without a data migration; the panel shows the gap in red and
 *    `quota:audit` lists it.
 *
 * There is no trial step. The free plan replaced the trial at the gate, so a shop's limits
 * are its plan's limits from its first minute.
 *
 * ## Failing closed on a missing fallback
 *
 * If `config('hamyar.quota.fallback_plan')` names no plan, this **throws**. The lenient
 * reading — treat it as unlimited — would hand every lapsed shop everything, failing open
 * in the one layer whose whole job is to fail closed, and it would do it silently. In tests
 * it would also let a suite that forgot its subscription fixture pass unmetered, which is
 * how a create path that never calls `consume()` stays green for ever.
 *
 * ## Caching (ADR 0012)
 *
 * Two layers, both led by the tenant id. A per-process memo keyed by tenant, validated
 * against `tenants.entitlement_version`; and the version itself in the shared cache so a
 * second process sees a plan change without waiting for a deploy. Every bump is
 * **write-through** — the writer updates the column and puts the new version in the cache
 * in the same breath — because a cached version with a TTL would make "a Filament edit
 * applies on the next request" true only on average.
 *
 * When the cache is unavailable the resolver re-reads the version from the database rather
 * than trusting its memo. Slower and correct beats fast and stale, for a number that
 * decides whether a shop can take a sale.
 */
final class LimitResolver
{
    /** @var array<int, array{version: int, limits: array<string, int|null>, plan: string}> */
    private array $memo = [];

    public function __construct(
        private readonly TenantContext $context,
        private readonly SubscriptionResolver $subscriptions,
        private readonly Cache $cache,
    ) {}

    /**
     * The effective limit for the CURRENT tenant, or null for unlimited.
     */
    public function for(string $metric): ?int
    {
        return $this->forTenant($this->context->idOrFail(), $metric);
    }

    /**
     * The effective limit for a named tenant, or null for unlimited.
     */
    public function forTenant(int $tenantId, string $metric): ?int
    {
        $resolved = $this->resolve($tenantId);

        // A metric with no row on this plan is unlimited on this plan — deliberately
        // lenient, so shipping a metric never needs a data migration. The panel and
        // `quota:audit` are what stop that being permanent.
        return $resolved['limits'][$metric] ?? null;
    }

    /**
     * The plan code whose limits are in force — the shop's own, or the fallback it has
     * lapsed to.
     */
    public function effectivePlanCode(int $tenantId): string
    {
        return $this->resolve($tenantId)['plan'];
    }

    /**
     * Every effective limit for a tenant, keyed by metric.
     *
     * @return array<string, int|null>
     */
    public function allFor(int $tenantId): array
    {
        return $this->resolve($tenantId)['limits'];
    }

    /**
     * The cheapest public plan that would fit `$needed` of `$metric`, or null when the
     * shop is already on the most generous one.
     *
     * Ordered by `position` rather than price, because position is what the pricing page
     * shows as the ladder and the two must not disagree about which rung is "next".
     */
    public function nextPlanFor(int $tenantId, string $metric, int $needed): ?string
    {
        $currentCode = $this->effectivePlanCode($tenantId);
        $seenCurrent = false;

        /** @var list<Plan> $plans */
        $plans = Plan::query()->with('limits')->where('is_public', true)->orderBy('position')->get()->all();

        foreach ($plans as $plan) {
            if ($plan->code === $currentCode) {
                $seenCurrent = true;

                continue;
            }

            // Only ever suggest UP the ladder. Without this a shop on the top rung that
            // hits a standing capacity would be told to "upgrade" to the free plan.
            if (! $seenCurrent) {
                continue;
            }

            $limit = $plan->limit($metric);

            if ($limit === null || $limit >= $needed) {
                return $plan->code;
            }
        }

        return null;
    }

    /**
     * Drop the memo — after a plan change, an override, or in tests.
     */
    public function forget(?int $tenantId = null): void
    {
        if ($tenantId === null) {
            $this->memo = [];

            return;
        }

        unset($this->memo[$tenantId]);
    }

    /**
     * Bump a tenant's entitlement version, write-through to the cache.
     *
     * Called by every writer that can change what a shop is allowed: an activated
     * subscription, a scheduled downgrade, an override, a plan-limit edit.
     */
    public function bump(int $tenantId): void
    {
        $version = (int) Tenant::query()->whereKey($tenantId)->value('entitlement_version') + 1;

        Tenant::query()->whereKey($tenantId)->update(['entitlement_version' => $version]);

        // Write-through, not invalidate-and-hope: another process reading a moment later
        // must see the NEW number, or "a panel edit applies on the next request" is only
        // true on average.
        $this->putVersion($tenantId, $version);

        $this->forget($tenantId);
    }

    /**
     * @return array{version: int, limits: array<string, int|null>, plan: string}
     */
    private function resolve(int $tenantId): array
    {
        $version = $this->version($tenantId);
        $memo = $this->memo[$tenantId] ?? null;

        if ($memo !== null && $memo['version'] === $version) {
            return $memo;
        }

        $plan = $this->effectivePlan($tenantId);

        $limits = [];

        foreach ($plan->limits as $limit) {
            $limits[$limit->key] = $limit->value;
        }

        // Overrides last, so they beat the plan — including making something unlimited
        // that the plan caps.
        foreach ($this->overridesFor($tenantId) as $metric => $value) {
            $limits[$metric] = $value;
        }

        return $this->memo[$tenantId] = ['version' => $version, 'limits' => $limits, 'plan' => $plan->code];
    }

    /**
     * The plan whose limits apply: the usable subscription's, else the configured
     * fallback.
     */
    private function effectivePlan(int $tenantId): Plan
    {
        $subscription = $this->subscriptions->forTenantId($tenantId);

        if ($subscription instanceof Subscription && $subscription->isUsable()) {
            $subscription->loadMissing('plan.limits');

            return $subscription->plan;
        }

        $code = config()->string('hamyar.quota.fallback_plan');

        $plan = Plan::query()->with('limits')->where('code', $code)->first();

        if (! $plan instanceof Plan) {
            // Fail closed and loudly. See the class docblock: the lenient reading gives
            // every lapsed shop unlimited everything, and says nothing while doing it.
            throw new RuntimeException(
                "Quota fallback plan [{$code}] does not exist. Every shop without a usable "
                .'subscription depends on it; set hamyar.quota.fallback_plan to a plan that exists.'
            );
        }

        return $plan;
    }

    /**
     * @return array<string, int|null>
     */
    private function overridesFor(int $tenantId): array
    {
        $now = CarbonImmutable::now();

        // Explicit tenant scope: this table is platform-owned, so there is no global
        // scope to lean on and RLS is the only other guard.
        $rows = $this->context->runAsPlatform(
            static fn (): array => TenantLimitOverride::query()->where('tenant_id', $tenantId)->get()->all()
        );

        $overrides = [];

        foreach ($rows as $row) {
            if ($row->isLive($now)) {
                $overrides[$row->metric] = $row->value;
            }
        }

        return $overrides;
    }

    private function version(int $tenantId): int
    {
        $key = $this->versionKey($tenantId);

        try {
            $cached = $this->cache->get($key);
        } catch (Throwable) {
            // Cache down: read through rather than trusting a memo whose version we
            // cannot confirm.
            $cached = null;
        }

        if (is_int($cached)) {
            return $cached;
        }

        $version = (int) (Tenant::query()->whereKey($tenantId)->value('entitlement_version') ?? 1);

        $this->putVersion($tenantId, $version);

        return $version;
    }

    private function putVersion(int $tenantId, int $version): void
    {
        try {
            $this->cache->put($this->versionKey($tenantId), $version, now()->addHours(6));
        } catch (Throwable) {
            // A cache we cannot write to costs a query per request, not correctness.
        }
    }

    /**
     * Tenant id leads the key (ADR 0012).
     */
    private function versionKey(int $tenantId): string
    {
        return "t:{$tenantId}:entitlement-version";
    }
}
