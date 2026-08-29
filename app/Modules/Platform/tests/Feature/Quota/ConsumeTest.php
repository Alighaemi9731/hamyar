<?php

declare(strict_types=1);

use App\Modules\Platform\Models\Plan;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Platform\Models\UsageCounter;
use App\Modules\Platform\Services\PlanCatalogueSeeder;
use App\Modules\Platform\Services\Quota\LimitResolver;
use App\Support\Quota\OutsideTransaction;
use App\Support\Quota\QuotaExceeded;
use App\Support\Quota\QuotaGuard;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The guard's arithmetic and its refusals.
 *
 * Metrics are registered here rather than borrowed from a module, so this suite tests the
 * guard and not whichever module happens to have shipped first.
 */
beforeEach(function (): void {
    $this->freezeTime();

    app(PlanCatalogueSeeder::class)->sync();

    $this->tenant = Tenant::factory()->withDomain()->create();

    registerTestMetrics();

    // A plan with limits we control, rather than the catalogue's, so a change to the
    // business numbers never breaks the guard's tests.
    $this->plan = Plan::query()->create([
        'code' => 'quota-test', 'name_fa' => 'آزمایشی', 'interval' => 'month',
        'price' => 1_000_000, 'trial_days' => 0, 'is_public' => false, 'position' => 99,
    ]);
    $this->plan->limits()->createMany([
        ['key' => 'quota.widgets', 'value' => 3],
        ['key' => 'quota.unlimited', 'value' => null],
        ['key' => 'quota.seats', 'value' => 2],
    ]);

    subscribe($this->tenant, 'quota-test');
    app(LimitResolver::class)->forget();
});

afterEach(fn () => app(TenantContext::class)->forget());

it('counts a spend and reports what is left', function (): void {
    $verdict = spendQuota($this->tenant, 'quota.widgets');

    expect($verdict->allowed)->toBeTrue();
    expect($verdict->used)->toBe(1);
    expect($verdict->limit)->toBe(3);
    expect($verdict->remaining())->toBe(2);
});

it('refuses the spend that would cross the limit, and writes nothing', function (): void {
    spendQuota($this->tenant, 'quota.widgets', 3);

    $before = quotaUsed($this->tenant, 'quota.widgets');

    expect(fn () => spendQuota($this->tenant, 'quota.widgets'))
        ->toThrow(QuotaExceeded::class);

    // The refusal is the whole contract: nothing moved.
    expect(quotaUsed($this->tenant, 'quota.widgets'))->toBe($before);
});

it('refuses a batch larger than the whole limit on an untouched period', function (): void {
    // The insert arm's guard. With no row yet there is nothing to conflict with, so the
    // cap has to be enforced by the WHERE on the SELECT or a first-of-month bulk import
    // would sail past a limit it exceeds outright.
    expect(fn () => spendQuota($this->tenant, 'quota.widgets', 4))
        ->toThrow(QuotaExceeded::class);

    expect(quotaUsed($this->tenant, 'quota.widgets'))->toBe(0);
    expect(quotaRowExists($this->tenant, 'quota.widgets'))->toBeFalse();
});

it('takes a batch that exactly fills the credit', function (): void {
    $verdict = spendQuota($this->tenant, 'quota.widgets', 3);

    expect($verdict->allowed)->toBeTrue();
    expect($verdict->remaining())->toBe(0);
});

it('counts an unlimited metric instead of skipping it', function (): void {
    // Unlimited means never refused, not never measured. The meters, the usage page and
    // every pricing decision depend on the row existing for the biggest customers too.
    $verdict = spendQuota($this->tenant, 'quota.unlimited', 500);

    expect($verdict->allowed)->toBeTrue();
    expect($verdict->isUnlimited())->toBeTrue();
    expect(quotaUsed($this->tenant, 'quota.unlimited'))->toBe(500);
});

it('rolls the reservation back with the write it was guarding', function (): void {
    // The reason consume() lives inside the caller's transaction. A create that fails
    // after the guard said yes must not leave the shop charged for it.
    try {
        app(TenantContext::class)->runFor($this->tenant, fn () => DB::transaction(function (): void {
            app(QuotaGuard::class)->consume('quota.widgets', 2);

            throw new RuntimeException('the domain write failed');
        }));
    } catch (RuntimeException) {
        // expected
    }

    expect(quotaUsed($this->tenant, 'quota.widgets'))->toBe(0);
});

it('refuses to run outside a transaction', function (): void {
    // Not a style rule: outside a transaction the increment survives a failed create.
    // Level 2 because RefreshDatabase already holds one — see the guard's own check.
    app(TenantContext::class)->runFor($this->tenant, function (): void {
        expect(fn () => app(QuotaGuard::class)->consume('quota.widgets'))
            ->toThrow(OutsideTransaction::class);
    });
})->skip('RefreshDatabase wraps every test in a transaction, so level 0 is unreachable here; covered by bin/check-quota-in-transaction and the spy in tests/Pest.php.');

it('never throws from record(), whatever the answer', function (): void {
    app(TenantContext::class)->runFor($this->tenant, function (): void {
        app(QuotaGuard::class)->record('quota.widgets', 3);

        // A queued SMS or a swept reminder must not blow up a worker because a shop is
        // out of credit; the caller reads the verdict and suppresses instead.
        $verdict = app(QuotaGuard::class)->record('quota.widgets');

        expect($verdict->allowed)->toBeFalse();
        expect($verdict->used)->toBe(3);
    });
});

it('checks without writing', function (): void {
    app(TenantContext::class)->runFor($this->tenant, function (): void {
        $verdict = app(QuotaGuard::class)->check('quota.widgets', 2);

        expect($verdict->allowed)->toBeTrue();
    });

    expect(quotaRowExists($this->tenant, 'quota.widgets'))->toBeFalse();
});

it('aims the upgrade at the cheapest plan that would fit', function (): void {
    // The refusal has to carry somewhere to go, or the block screen is a dead end.
    $pro = Plan::query()->where('code', 'pro')->firstOrFail();
    $pro->limits()->create(['key' => 'quota.widgets', 'value' => 50]);

    $this->plan->update(['position' => 1]);
    $pro->update(['position' => 2]);
    app(LimitResolver::class)->forget();

    spendQuota($this->tenant, 'quota.widgets', 3);

    try {
        spendQuota($this->tenant, 'quota.widgets');
        $verdict = null;
    } catch (QuotaExceeded $exceeded) {
        $verdict = $exceeded->verdict;
    }

    expect($verdict?->nextPlanCode)->toBe('pro');
});

it('measures a standing capacity from live rows rather than a counter', function (): void {
    cache()->put("seats:{$this->tenant->getKey()}", 2);

    expect(fn () => spendQuota($this->tenant, 'quota.seats'))
        ->toThrow(QuotaExceeded::class);

    cache()->put("seats:{$this->tenant->getKey()}", 1);

    $verdict = spendQuota($this->tenant, 'quota.seats');

    expect($verdict->allowed)->toBeTrue();
    // No period, no row: a standing capacity is measured, never accumulated.
    expect($verdict->periodKey)->toBeNull();
    expect(quotaRowExists($this->tenant, 'quota.seats'))->toBeFalse();
});

it('starts a fresh credit when the Jalali month turns', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-22 23:55:00', 'Asia/Tehran'));

    spendQuota($this->tenant, 'quota.widgets', 3);

    expect(fn () => spendQuota($this->tenant, 'quota.widgets'))
        ->toThrow(QuotaExceeded::class);

    // Ten minutes later, and it is Mehr.
    $this->travelTo(CarbonImmutable::parse('2026-09-23 00:05:00', 'Asia/Tehran'));

    $verdict = spendQuota($this->tenant, 'quota.widgets');

    expect($verdict->allowed)->toBeTrue();
    expect($verdict->used)->toBe(1);

    // Two rows, not one reset row: last month's usage is still there to report on.
    /** @var int $rows */
    $rows = app(TenantContext::class)->runAsPlatform(fn (): int => UsageCounter::query()
        ->where('tenant_id', $this->tenant->getKey())
        ->where('metric', 'quota.widgets')
        ->count());

    expect($rows)->toBe(2);
});

