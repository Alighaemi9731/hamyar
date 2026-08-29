<?php

declare(strict_types=1);

use App\Modules\Platform\Models\Plan;
use App\Modules\Platform\Models\Subscription;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Platform\Models\TenantLimitOverride;
use App\Modules\Platform\Services\PlanCatalogueSeeder;
use App\Modules\Platform\Services\Quota\LimitResolver;
use App\Modules\Platform\Services\SubscriptionResolver;
use App\Support\Tenancy\TenantContext;

/**
 * Which number applies to this shop, and what happens when the answer changes underneath a
 * process that has already memoised it.
 */
beforeEach(function (): void {
    $this->freezeTime();

    app(PlanCatalogueSeeder::class)->sync();
    registerTestMetrics();

    $this->tenant = Tenant::factory()->withDomain()->create();

    $this->small = Plan::query()->create([
        'code' => 'small', 'name_fa' => 'کوچک', 'interval' => 'month',
        'price' => 0, 'trial_days' => 0, 'is_public' => true, 'position' => 1,
    ]);
    $this->small->limits()->create(['key' => 'quota.widgets', 'value' => 5]);

    $this->large = Plan::query()->create([
        'code' => 'large', 'name_fa' => 'بزرگ', 'interval' => 'month',
        'price' => 5_000_000, 'trial_days' => 0, 'is_public' => true, 'position' => 2,
    ]);
    $this->large->limits()->create(['key' => 'quota.widgets', 'value' => 500]);

    config()->set('hamyar.quota.fallback_plan', 'small');

    $this->resolver = function (): LimitResolver {
        $resolver = app(LimitResolver::class);
        $resolver->forget();

        return $resolver;
    };
});

afterEach(fn () => app(TenantContext::class)->forget());

it('reads the limit off the subscribed plan', function (): void {
    subscribe($this->tenant, 'large');
    app(SubscriptionResolver::class)->forget();

    expect(($this->resolver)()->forTenant(idOf($this->tenant), 'quota.widgets'))->toBe(500);
});

it('falls back to the free plan when a subscription lapses', function (): void {
    // The gate's item 4: a shop that stops paying is never locked out, it drops to the
    // free rung's credits and keeps working.
    subscribe($this->tenant, 'large', [
        'status' => Subscription::STATUS_CANCELED,
        'current_period_end' => now()->subDay(),
    ]);
    app(SubscriptionResolver::class)->forget();

    expect(($this->resolver)()->forTenant(idOf($this->tenant), 'quota.widgets'))->toBe(5);
    expect(($this->resolver)()->effectivePlanCode(idOf($this->tenant)))->toBe('small');
});

it('falls back for a shop with no subscription at all', function (): void {
    expect(($this->resolver)()->forTenant(idOf($this->tenant), 'quota.widgets'))->toBe(5);
});

it('THROWS when the fallback plan does not exist', function (): void {
    // Fails closed and loudly. The lenient reading — treat a missing fallback as
    // unlimited — hands every lapsed shop everything and says nothing about it, which is
    // failing open in the one layer whose whole job is the opposite.
    config()->set('hamyar.quota.fallback_plan', 'no-such-plan');

    expect(fn (): ?int => ($this->resolver)()->forTenant(idOf($this->tenant), 'quota.widgets'))
        ->toThrow(RuntimeException::class, 'no-such-plan');
});

it('treats a metric with no row as unlimited on that plan', function (): void {
    // Lenient on purpose: a module can ship a new metric without a data migration. The
    // panel's red row and `quota:audit` are what stop that being permanent.
    subscribe($this->tenant, 'small');
    app(SubscriptionResolver::class)->forget();

    expect(($this->resolver)()->forTenant(idOf($this->tenant), 'quota.unlimited'))->toBeNull();
});

it('lets an override beat the plan, in both directions', function (): void {
    subscribe($this->tenant, 'small');
    app(SubscriptionResolver::class)->forget();

    app(TenantContext::class)->runAsPlatform(fn (): TenantLimitOverride => TenantLimitOverride::query()->create([
        'tenant_id' => $this->tenant->getKey(),
        'metric' => 'quota.widgets',
        'value' => 50,
        'reason' => 'قرارداد ویژه',
    ]));

    expect(($this->resolver)()->forTenant(idOf($this->tenant), 'quota.widgets'))->toBe(50);
});

it('ignores an override that has expired', function (): void {
    // Kept rather than deleted: "this shop had fifty until Mehr, and why" is the question
    // support asks, and a deleted row answers it with silence.
    subscribe($this->tenant, 'small');
    app(SubscriptionResolver::class)->forget();

    app(TenantContext::class)->runAsPlatform(fn (): TenantLimitOverride => TenantLimitOverride::query()->create([
        'tenant_id' => $this->tenant->getKey(),
        'metric' => 'quota.widgets',
        'value' => 50,
        'reason' => 'مهاجرت داده',
        'expires_at' => now()->subHour(),
    ]));

    expect(($this->resolver)()->forTenant(idOf($this->tenant), 'quota.widgets'))->toBe(5);
});

it('makes a shop unlimited with a null override', function (): void {
    subscribe($this->tenant, 'small');
    app(SubscriptionResolver::class)->forget();

    app(TenantContext::class)->runAsPlatform(fn (): TenantLimitOverride => TenantLimitOverride::query()->create([
        'tenant_id' => $this->tenant->getKey(),
        'metric' => 'quota.widgets',
        'value' => null,
        'reason' => 'مشتری سازمانی',
    ]));

    expect(($this->resolver)()->forTenant(idOf($this->tenant), 'quota.widgets'))->toBeNull();
});

it('re-resolves a warm memo after the entitlement version moves', function (): void {
    // The ADR 0012 failure mode, one level up: a memo nothing invalidates means a shop
    // upgrades and a long-lived worker keeps refusing its work for as long as it lives.
    subscribe($this->tenant, 'small');
    app(SubscriptionResolver::class)->forget();

    $resolver = ($this->resolver)();

    expect($resolver->forTenant(idOf($this->tenant), 'quota.widgets'))->toBe(5);

    app(TenantContext::class)->runAsPlatform(fn (): TenantLimitOverride => TenantLimitOverride::query()->create([
        'tenant_id' => $this->tenant->getKey(),
        'metric' => 'quota.widgets',
        'value' => 99,
        'reason' => 'ارتقا دستی',
    ]));

    // Without the bump the memo is still authoritative — which is correct, and is why
    // every writer must bump.
    expect($resolver->forTenant(idOf($this->tenant), 'quota.widgets'))->toBe(5);

    $resolver->bump(idOf($this->tenant));

    expect($resolver->forTenant(idOf($this->tenant), 'quota.widgets'))->toBe(99);
});

it('points an exhausted shop at the next rung up, never down', function (): void {
    subscribe($this->tenant, 'small');
    app(SubscriptionResolver::class)->forget();

    expect(($this->resolver)()->nextPlanFor(idOf($this->tenant), 'quota.widgets', 6))->toBe('large');

    // And a shop already on the top rung has nowhere to be sent — the block screen must
    // say "contact support", not offer a downgrade as an upgrade.
    subscribe($this->tenant, 'large');
    app(SubscriptionResolver::class)->forget();

    expect(($this->resolver)()->nextPlanFor(idOf($this->tenant), 'quota.widgets', 501))->toBeNull();
});
