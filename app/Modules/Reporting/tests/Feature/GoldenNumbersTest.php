<?php

declare(strict_types=1);

use App\Modules\CRM\Models\Account;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Platform\Services\PlanCatalogueSeeder;
use App\Modules\Platform\Services\SubscriptionResolver;
use App\Modules\Platform\Services\TenantProvisioner;
use App\Modules\Reporting\Services\ReportPeriod;
use App\Modules\Reporting\Services\SalesReports;
use App\Modules\Treasury\Services\AccountBalances;
use App\Modules\Treasury\Services\DailyClose;
use App\Modules\Treasury\Services\ProfitAndLoss;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Database\Seeders\CrazyMonthSeeder;

/**
 * The Phase 9 Definition of Done: every report agrees with the Phase 7 scenario.
 *
 * ## Why the crazy month rather than a fixture built for reporting
 *
 * A report tested against data assembled to make the report look right proves only that the
 * assembler and the reporter agree. The crazy month was built for a different purpose — it
 * reconciles the ledger — and it contains the awkward cases on purpose: a bounced cheque
 * re-presented and collected, an endorsed cheque still outstanding, a PSP charge, rent
 * through the recurring generator.
 *
 * So these figures are **golden numbers**: pinned literals that must not move. If a report
 * changes what it returns, this file fails, and the failure is the point. Anybody changing
 * a figure here must either be fixing a bug the reconciliation missed or be wrong.
 *
 * ## They are cross-checked against a second source
 *
 * Where possible each number is asserted against a figure computed by different code — the
 * P&L against the daily close, the report summary against `ProfitEngine`. A golden number
 * that only agrees with itself is a snapshot test wearing better clothes.
 */
beforeEach(function (): void {
    app(PlanCatalogueSeeder::class)->sync();

    $this->tenant = Tenant::factory()->withDomain()->create(['slug' => 'demo']);

    subscribe($this->tenant, 'pro');
    app(SubscriptionResolver::class)->forget();
    app(TenantProvisioner::class)->seedRoles($this->tenant);

    inTenantContext($this->tenant, function (): void {
        User::factory()->create()->assignRole('Owner');
        Warehouse::factory()->create(['is_sellable' => true, 'is_default' => true]);
    });

    (new CrazyMonthSeeder)->run();

    $this->period = ReportPeriod::of(
        CarbonImmutable::parse(CrazyMonthSeeder::MONTH_START),
        CarbonImmutable::parse(CrazyMonthSeeder::MONTH_END),
    );

    $this->inTenant = fn (Closure $callback): mixed => inTenantContext($this->tenant, $callback);
});

afterEach(fn () => app(TenantContext::class)->forget());

/* ============================ THE GOLDEN NUMBERS ============================ */

it('reports the same operating costs the P&L does', function (): void {
    ($this->inTenant)(function (): void {
        $pnl = app(ProfitAndLoss::class)->forPeriod($this->period->from, $this->period->to);

        // Rent 120,000,000 + PSP cut 850,000 + bounce fee 300,000. Pinned: if this moves,
        // either a cost stopped being counted or one is being counted twice.
        expect($pnl['operating_costs'])->toBe(121_150_000);
    });
});

it('makes every P&L breakdown row sum to its own headline', function (): void {
    ($this->inTenant)(function (): void {
        $pnl = app(ProfitAndLoss::class)->forPeriod($this->period->from, $this->period->to);

        $summed = 0;

        foreach ($pnl['expense_breakdown'] as $row) {
            $summed += $row['amount'];
        }

        // Not a golden number — an invariant. It holds whatever the scenario grows into,
        // which is why it sits beside the pinned figure rather than instead of it.
        expect($summed)->toBe($pnl['operating_costs']);
    });
});

it('agrees with the daily close about what the shop holds', function (): void {
    ($this->inTenant)(function (): void {
        $close = app(DailyClose::class)->for($this->period->to);
        $balances = app(AccountBalances::class);

        // Two code paths, one answer. A shopkeeper comparing the treasury page against the
        // close is the first person to find out when they diverge.
        foreach ($close['accounts'] as $row) {
            /** @var Account $account */
            $account = Account::query()->findOrFail($row['id']);

            expect($row['closing'])->toBe($balances->balanceOf($account));
        }
    });
});

it('closes the books on the opening figure, whatever happened in between', function (): void {
    ($this->inTenant)(function (): void {
        $balances = app(AccountBalances::class);
        $ledger = app(App\Modules\CRM\Services\LedgerService::class);

        $total = 0;
        $openings = 0;

        foreach (Account::query()->get() as $account) {
            $total += $balances->balanceOf($account);
            $openings += $account->opening_balance;
        }

        foreach (App\Modules\CRM\Models\Party::query()->get() as $party) {
            $total += $ledger->partyBalance($party);
            $openings += $party->opening_balance;
        }

        // The Phase 7 invariant, re-asserted from the reporting side: if a report ever
        // disagrees with this, the report is wrong.
        expect($total)->toBe($openings);
    });
});

it('reports sales totals identical to the profit engine', function (): void {
    ($this->inTenant)(function (): void {
        $summary = app(SalesReports::class)->summary($this->period);
        $engine = app(App\Modules\Sales\Services\ProfitEngine::class)
            ->forPeriod($this->period->from, $this->period->to);

        // The report composes the engine rather than re-deriving, so this asserts the
        // composition rather than the arithmetic — which is the failure that would
        // actually happen.
        expect($summary['revenue'])->toBe($engine['revenue'])
            ->and($summary['cost'])->toBe($engine['cost'])
            ->and($summary['profit'])->toBe($engine['profit']);
    });
});

it('sums the daily breakdown to the same revenue as the summary', function (): void {
    ($this->inTenant)(function (): void {
        $daily = app(SalesReports::class)->daily($this->period);
        $summary = app(SalesReports::class)->summary($this->period);

        $revenue = 0;

        foreach ($daily as $row) {
            $revenue += is_numeric($row['revenue'] ?? null) ? (int) $row['revenue'] : 0;
        }

        // The daily rows exclude returns and the summary nets them, so these agree only
        // when nothing was returned — which is true of this scenario, and asserted so that
        // adding a return to the month makes somebody decide what the report should say.
        expect($summary['returned_revenue'])->toBe(0)
            ->and($revenue)->toBe($summary['revenue']);
    });
});

/* ============================ JALALI PERIODS ============================ */

it('includes the whole of the last day in the range', function (): void {
    $period = ReportPeriod::fromJalali('1405/05/01', '1405/05/31');

    /*
    | Asserted as inclusivity, not as a clock string.
    |
    | The boundary is 23:59:59 in TEHRAN and 20:29:59 in UTC, which is what the stored
    | value reads — so `format('H:i:s') === '23:59:59'` fails against correct code. The
    | claim that matters is that a sale rung up late on the 31st falls inside the range,
    | and that is what this checks.
    |
    | «تا ۳۱ مرداد» means all of the 31st. A range ending at local midnight drops a day's
    | trading, which is most of a shop's business on the day being asked about.
    */
    $lateOnTheLastDay = App\Support\Jalali::startOfDay('1405/05/31')->addHours(23);

    expect($lateOnTheLastDay->lessThanOrEqualTo($period->to))->toBeTrue()
        // And the next day is outside it.
        ->and(App\Support\Jalali::startOfDay('1405/06/01')->greaterThan($period->to))->toBeTrue();
});

it('accepts Persian digits in a filter, as a Persian keyboard produces them', function (): void {
    $typed = ReportPeriod::fromJalali('۱۴۰۵/۰۵/۰۱', '۱۴۰۵/۰۵/۳۱');
    $latin = ReportPeriod::fromJalali('1405/05/01', '1405/05/31');

    expect($typed->from->toDateString())->toBe($latin->from->toDateString())
        ->and($typed->to->toDateString())->toBe($latin->to->toDateString());
});

it('swaps a backwards range rather than reporting nothing', function (): void {
    $period = ReportPeriod::fromJalali('1405/05/31', '1405/05/01');

    // Somebody mistyped; they did not ask for nothing. An empty report leaves them to
    // work out why.
    expect($period->from->lessThan($period->to))->toBeTrue();
});

it('opens on the Jalali month, not the Gregorian one', function (): void {
    // 2026-08-14 is 1405/05/23. The Jalali month started on 2026-07-23, not 2026-08-01 —
    // and a "this month" report built on Carbon's startOfMonth covers parts of two.
    $period = ReportPeriod::thisMonth(CarbonImmutable::parse('2026-08-14'));

    // `Jalali::format()` renders Persian digits, because that is what a shopkeeper reads.
    // The Gregorian instant is the unambiguous half of the assertion.
    expect($period->from->toDateString())->toBe('2026-07-23')
        ->and($period->fromJalali)->toBe('۱۴۰۵/۰۵/۰۱');
});
