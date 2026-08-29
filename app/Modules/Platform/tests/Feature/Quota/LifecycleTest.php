<?php

declare(strict_types=1);

use App\Modules\Platform\Models\Plan;
use App\Modules\Platform\Models\Subscription;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Platform\Models\UsageCounter;
use App\Modules\Platform\Services\PlanCatalogueSeeder;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;

/**
 * `subscriptions:expire` and `quota:prune` — the two scheduled commands, and the states
 * that had been modelled since Phase 2 with nothing writing them.
 */
beforeEach(function (): void {
    $this->freezeTime();

    app(PlanCatalogueSeeder::class)->sync();
    registerTestMetrics();

    $this->tenant = Tenant::factory()->withDomain()->create();

    $this->free = Plan::query()->create([
        'code' => 'free-plan', 'name_fa' => 'رایگان', 'interval' => 'month',
        'price' => 0, 'trial_days' => 0, 'is_public' => true, 'position' => 1,
    ]);

    config()->set('hamyar.quota.fallback_plan', 'free-plan');
});

afterEach(fn () => app(TenantContext::class)->forget());

it('moves an ended paid period into grace rather than stopping it dead', function (): void {
    // `grace_ends_at` has been read by isUsable() since Phase 2 and written by nothing, so
    // there was no grace period at all — an active row simply stopped being usable the
    // second its period ended.
    $subscription = subscribe($this->tenant, 'pro', [
        'current_period_start' => now()->subMonth(),
        'current_period_end' => now()->subHour(),
    ]);

    $this->artisan('subscriptions:expire', ['--days' => 3])->assertSuccessful();

    $fresh = fresh($subscription);

    expect($fresh->status)->toBe(Subscription::STATUS_PAST_DUE);
    expect($fresh->grace_ends_at?->greaterThan(CarbonImmutable::now()))->toBeTrue();
    // Still usable: an Iranian gateway outage must not lock a shop out of its own till.
    expect($fresh->isUsable())->toBeTrue();
});

it('cancels once grace runs out, and still does not lock the shop out', function (): void {
    $subscription = subscribe($this->tenant, 'pro', [
        'status' => Subscription::STATUS_PAST_DUE,
        'current_period_end' => now()->subDays(5),
        'grace_ends_at' => now()->subHour(),
    ]);

    $this->artisan('subscriptions:expire')->assertSuccessful();

    $fresh = fresh($subscription);

    expect($fresh->status)->toBe(Subscription::STATUS_CANCELED);
    expect($fresh->canceled_at)->not->toBeNull();
    expect($fresh->isUsable())->toBeFalse();
});

it('leaves a free subscription alone for ever', function (): void {
    // A zero-price plan is not late; it is free. Marking it past_due would ask a shop to
    // pay nothing, and cancelling it would evict every shop that never intended to pay.
    $subscription = subscribe($this->tenant, 'free-plan', [
        'current_period_start' => now()->subYear(),
        'current_period_end' => now()->subMonths(6),
    ]);

    $this->artisan('subscriptions:expire')->assertSuccessful();

    expect(fresh($subscription)->status)->toBe(Subscription::STATUS_ACTIVE);
});

it('is safe to run twice', function (): void {
    // A scheduler is a thing that runs twice.
    $subscription = subscribe($this->tenant, 'pro', [
        'current_period_end' => now()->subHour(),
    ]);

    $this->artisan('subscriptions:expire', ['--days' => 3])->assertSuccessful();
    $graceAfterFirst = fresh($subscription)->grace_ends_at;

    $this->artisan('subscriptions:expire', ['--days' => 3])->assertSuccessful();

    // The second run must not extend the grace it granted on the first.
    expect(fresh($subscription)->grace_ends_at?->toIso8601String())
        ->toBe($graceAfterFirst?->toIso8601String());
});

it('prunes counter rows for long-past periods and keeps recent ones', function (): void {
    app(TenantContext::class)->runAsPlatform(function (): void {
        UsageCounter::query()->create([
            'tenant_id' => $this->tenant->getKey(),
            'metric' => 'quota.widgets',
            'period_key' => CarbonImmutable::now()->subDays(500)->toDateString(),
            'used' => 7,
        ]);

        UsageCounter::query()->create([
            'tenant_id' => $this->tenant->getKey(),
            'metric' => 'quota.widgets',
            'period_key' => CarbonImmutable::now()->subDays(30)->toDateString(),
            'used' => 3,
        ]);
    });

    $this->artisan('quota:prune', ['--days' => 400])->assertSuccessful();

    /** @var list<int> $remaining */
    $remaining = app(TenantContext::class)->runAsPlatform(fn (): array => UsageCounter::query()
        ->where('tenant_id', $this->tenant->getKey())
        ->pluck('used')
        ->all());

    expect($remaining)->toBe([3]);
    // The health line, from the day the table shipped: an unscheduled sweep in this repo
    // has always been discovered by its absence rather than its output.
    expect(cache()->get('quota.pruned_at'))->not->toBeNull();
});

function fresh(Subscription $subscription): Subscription
{
    /** @var Subscription $reloaded */
    $reloaded = app(TenantContext::class)->runAsPlatform(
        fn (): Subscription => Subscription::query()->whereKey($subscription->getKey())->firstOrFail()
    );

    return $reloaded;
}
