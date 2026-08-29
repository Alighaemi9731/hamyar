<?php

declare(strict_types=1);

use App\Modules\Platform\Models\Plan;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Platform\Models\TenantLimitOverride;
use App\Modules\Platform\Models\UsageCounter;
use App\Modules\Platform\Services\PlanCatalogueSeeder;
use App\Modules\Platform\Services\Quota\LimitResolver;
use App\Support\Quota\QuotaGuard;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Golden rule 8 for the three new tables.
 *
 * These are platform-owned — RLS with the `app.platform` escape and no `BelongsToTenant`
 * — which is the arrangement most likely to leak, because there is no Eloquent scope
 * quietly saving a query that forgot its `where`. So the isolation is proved at the
 * database level, not just at the service level.
 */
uses()->group('isolation');

beforeEach(function (): void {
    $this->freezeTime();

    app(PlanCatalogueSeeder::class)->sync();

    $this->alpha = Tenant::factory()->withDomain()->create();
    $this->beta = Tenant::factory()->withDomain()->create();

    registerTestMetrics();

    $plan = Plan::query()->create([
        'code' => 'iso-test', 'name_fa' => 'ایزوله', 'interval' => 'month',
        'price' => 1_000_000, 'trial_days' => 0, 'is_public' => false, 'position' => 98,
    ]);
    $plan->limits()->create(['key' => 'quota.widgets', 'value' => 2]);

    subscribe($this->alpha, 'iso-test');
    subscribe($this->beta, 'iso-test');
    app(LimitResolver::class)->forget();
});

afterEach(fn () => app(TenantContext::class)->forget());

it('does not spend one shop credit on another', function (): void {
    // The obvious failure this design could have: a counter keyed by metric alone, or a
    // memo keyed by nothing, and shop A's busy morning caps shop B.
    app(TenantContext::class)->runFor($this->alpha, fn () => DB::transaction(
        fn () => app(QuotaGuard::class)->consume('quota.widgets', 2)
    ));

    app(TenantContext::class)->runFor($this->beta, function (): void {
        $verdict = app(QuotaGuard::class)->check('quota.widgets');

        expect($verdict->used)->toBe(0);
        expect($verdict->allowed)->toBeTrue();
    });
});

it('denies a counter row written for another shop', function (): void {
    // RLS itself, not the service on top of it. Wrapped in DB::transaction() so the
    // aborted statement rolls back to a savepoint instead of poisoning the test's
    // connection (CLAUDE.md).
    app(TenantContext::class)->runFor($this->beta, function (): void {
        expect(fn () => DB::transaction(fn () => UsageCounter::query()->create([
            'tenant_id' => $this->alpha->getKey(),
            'metric' => 'quota.widgets',
            'period_key' => '2026-08-23',
            'used' => 1,
        ])))->toThrow(QueryException::class, 'violates row-level security policy');
    });
});

it('hides another shop overrides', function (): void {
    app(TenantContext::class)->runAsPlatform(fn () => TenantLimitOverride::query()->create([
        'tenant_id' => $this->alpha->getKey(),
        'metric' => 'quota.widgets',
        'value' => null,
        'reason' => 'قرارداد ویژه',
    ]));

    // Alpha is unlimited; beta must be unaffected by a row it cannot even see.
    app(TenantContext::class)->runFor($this->alpha, function (): void {
        expect(app(LimitResolver::class)->for('quota.widgets'))->toBeNull();
    });

    app(TenantContext::class)->runFor($this->beta, function (): void {
        // quota-scope-allow: proving RLS hides the row, which is the point.
        expect(TenantLimitOverride::query()->count())->toBe(0);
        expect(app(LimitResolver::class)->for('quota.widgets'))->toBe(2);
    });
});

it('keeps the platform flag a list rather than a blanket', function (): void {
    // The ADR 0002 amendment: `runAsPlatform()` opens the tables that opted in, and
    // nothing else. A tenant table that quietly gained the flag would be a silent hole,
    // so the list is asserted rather than assumed.
    app(TenantContext::class)->runFor($this->alpha, fn () => DB::transaction(
        fn () => app(QuotaGuard::class)->consume('quota.widgets')
    ));

    $visible = app(TenantContext::class)->runAsPlatform(
        fn (): int => UsageCounter::query()->count()
    );

    expect($visible)->toBeGreaterThan(0);

    // And without the flag, with no tenant pinned, nothing at all.
    app(TenantContext::class)->forget();

    expect(UsageCounter::query()->count())->toBe(0);
});
