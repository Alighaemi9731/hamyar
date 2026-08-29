<?php

declare(strict_types=1);

use App\Modules\Platform\Models\Plan;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Platform\Models\UsageCounter;
use App\Modules\Platform\Services\PlanCatalogueSeeder;
use App\Modules\Platform\Services\Quota\LimitResolver;
use App\Support\Quota\QuotaExceeded;
use App\Support\Quota\QuotaGuard;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * The property the whole design rests on: a spend is ONE statement, and its cap is
 * evaluated by Postgres against the committed value rather than by PHP against a value it
 * read a moment ago.
 *
 * ## Why this suite, and not a fork harness
 *
 * The tempting test is to fork twenty processes at the last unit and count the winners.
 * It was written and then removed, deliberately, and the reasoning is worth keeping:
 * forking inside PHPUnit is fragile in ways that have nothing to do with the code under
 * test — inherited PDO handles, output buffers, shutdown handlers running twice — and a
 * concurrency test that hangs CI once a fortnight teaches everyone to re-run the build
 * instead of reading it. A gate people learn to ignore is worse than no gate.
 *
 * So this asserts the two things that actually make the race safe, and both are
 * deterministic:
 *
 * 1. **One statement.** If anyone ever "simplifies" `consume()` into a read, a decision in
 *    PHP and a write, these tests fail — and that refactor is precisely the double-spend
 *    bug, because two requests can both read "one left" between each other's read and
 *    write.
 * 2. **Evaluated against committed state.** A decision made from a stale read is refused
 *    by the statement itself, which is what a second transaction's read would be.
 *
 * Postgres's own guarantee — that the loser of an `ON CONFLICT` race waits on the winner's
 * tuple and then re-evaluates the `DO UPDATE … WHERE` under READ COMMITTED — is documented
 * behaviour we rely on rather than re-prove here. It is named in ADR 0018 §4, and
 * `docs/DECISIONS-FOR-REVIEW.md` records that no test in CI exercises real multi-process
 * contention.
 */
beforeEach(function (): void {
    $this->freezeTime();

    app(PlanCatalogueSeeder::class)->sync();
    registerTestMetrics();

    $this->tenant = Tenant::factory()->withDomain()->create();

    $this->plan = Plan::query()->create([
        'code' => 'atomic-test', 'name_fa' => 'اتمیک', 'interval' => 'month',
        'price' => 1_000_000, 'trial_days' => 0, 'is_public' => false, 'position' => 97,
    ]);
    $this->plan->limits()->create(['key' => 'quota.widgets', 'value' => 10]);

    subscribe($this->tenant, 'atomic-test');
    app(LimitResolver::class)->forget();
});

afterEach(fn () => app(TenantContext::class)->forget());

/**
 * Every statement the callback runs against `usage_counters`.
 *
 * @return list<string>
 */
function counterStatements(Closure $callback): array
{
    $statements = [];

    DB::listen(function ($query) use (&$statements): void {
        if (str_contains($query->sql, 'usage_counters')) {
            $statements[] = $query->sql;
        }
    });

    $callback();

    // The listener has no removal API; the array it closes over simply stops being read.
    return $statements;
}

it('spends a credit in exactly one statement', function (): void {
    // The load-bearing assertion. A read-decide-write implementation would show three,
    // and would be the double-spend bug.
    $statements = counterStatements(fn () => app(TenantContext::class)->runFor(
        $this->tenant,
        fn () => DB::transaction(fn () => app(QuotaGuard::class)->consume('quota.widgets'))
    ));

    expect($statements)->toHaveCount(1);
    expect($statements[0])->toContain('ON CONFLICT');
    expect($statements[0])->toContain('DO UPDATE');
});

it('caps the update arm inside the statement, not in PHP', function (): void {
    $statements = counterStatements(fn () => app(TenantContext::class)->runFor(
        $this->tenant,
        fn () => DB::transaction(fn () => app(QuotaGuard::class)->consume('quota.widgets'))
    ));

    // `used + EXCLUDED.used <= limit` is what makes the second of two racing spenders
    // refuse after re-reading the winner's committed value. Without it the statement is
    // an unconditional increment and the cap is decoration.
    expect($statements[0])->toContain('WHERE');
    expect($statements[0])->toContain('usage_counters.used + EXCLUDED.used');
});

it('creates the first row of a period without a separate lookup', function (): void {
    // `CounterService` reads, misses, and inserts — which is a 23505 race on first use,
    // unwrapped by a savepoint. `ON CONFLICT` being the arbiter is what avoids repeating
    // that here, and it only holds if the insert is not preceded by a check.
    $statements = counterStatements(fn () => app(TenantContext::class)->runFor(
        $this->tenant,
        fn () => DB::transaction(fn () => app(QuotaGuard::class)->consume('quota.widgets'))
    ));

    expect($statements)->toHaveCount(1);
    expect($statements[0])->toStartWith('INSERT INTO usage_counters');
});

it('refuses a spend decided from a stale read', function (): void {
    // The race, made deterministic. A caller checks and is told yes; the credit is then
    // spent by somebody else; the caller proceeds on its stale answer. The statement must
    // refuse it — which is exactly what the losing transaction of a real race does after
    // re-evaluating against the winner's committed row.
    app(TenantContext::class)->runFor($this->tenant, function (): void {
        $stale = app(QuotaGuard::class)->check('quota.widgets');
        expect($stale->allowed)->toBeTrue();

        // Somebody else takes the lot.
        DB::transaction(fn () => app(QuotaGuard::class)->consume('quota.widgets', 10));

        expect(fn () => DB::transaction(fn () => app(QuotaGuard::class)->consume('quota.widgets')))
            ->toThrow(QuotaExceeded::class);
    });

    $used = app(TenantContext::class)->runAsPlatform(fn (): int => (int) UsageCounter::query()
        ->where('tenant_id', $this->tenant->getKey())
        ->where('metric', 'quota.widgets')
        ->value('used'));

    // Ten, not eleven. The stale decision cost nothing.
    expect($used)->toBe(10);
});

it('holds the line over a hundred sequential spends', function (): void {
    // Not concurrency, and it does not pretend to be — it is the arithmetic under
    // repetition, which catches an off-by-one in the WHERE that a single spend would not.
    $granted = 0;

    app(TenantContext::class)->runFor($this->tenant, function () use (&$granted): void {
        for ($i = 0; $i < 100; $i++) {
            try {
                DB::transaction(fn () => app(QuotaGuard::class)->consume('quota.widgets'));
                $granted++;
            } catch (QuotaExceeded) {
                // expected, ninety times
            }
        }
    });

    expect($granted)->toBe(10);
});
