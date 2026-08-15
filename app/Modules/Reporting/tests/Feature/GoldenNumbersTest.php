<?php

declare(strict_types=1);

use App\Modules\CRM\Models\Account;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Platform\Services\PlanCatalogueSeeder;
use App\Modules\Platform\Services\SubscriptionResolver;
use App\Modules\Platform\Services\TenantProvisioner;
use App\Modules\Reporting\Services\FinancialReports;
use App\Modules\Reporting\Services\OperationsReports;
use App\Modules\Reporting\Services\ReportPeriod;
use App\Modules\Reporting\Services\SalesReports;
use App\Modules\Reporting\Services\TaxReports;
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

        /*
        | ⚠ The crazy month contains NO SALES INVOICES.
        |
        | It seeds a chart of accounts, banking, overheads and cheques, because what it
        | was built to prove is that the ledger closes. So every figure compared here is
        | zero, and this test asserts the *composition* — that the report calls the
        | engine rather than re-deriving — and nothing whatever about the arithmetic.
        |
        | That limit is asserted rather than described, so it cannot rot: the day
        | somebody adds a sale to the scenario, this line fails and points them at
        | `SalesReportScreenTest`, which pins the sales arithmetic against a fixture
        | built for it.
        */
        expect($engine['invoice_count'])->toBe(0)
            ->and($summary['revenue'])->toBe($engine['revenue'])
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

        /*
        | Same caveat as above: this scenario has no sales, so both sides are zero and
        | what is really asserted is that neither call throws and both agree on the
        | empty case. The same invariant over a month with actual trading in it — and
        | over all three cuts, which is where a grouping bug hides — is in
        | `SalesReportScreenTest`.
        |
        | Kept rather than deleted because the empty case is a real one: a shop opens
        | this screen on its first morning, and «۰» is the correct answer, not a crash.
        */
        expect($daily)->toBe([])
            ->and($summary['returned_revenue'])->toBe(0)
            ->and($revenue)->toBe($summary['revenue']);
    });
});

/* ===================== THE PHASE 9.2 REPORTS, PINNED ===================== */

it('ages the crazy month to the same balances the ledger reports', function (): void {
    ($this->inTenant)(function (): void {
        $reports = app(FinancialReports::class);
        $ledger = app(App\Modules\CRM\Services\LedgerService::class);

        $outstanding = 0;
        $credit = 0;

        foreach ($reports->aging($this->period->to) as $row) {
            $outstanding += $row['total'];
            $credit += $row['credit'];
        }

        $balances = 0;

        foreach (App\Modules\CRM\Models\Party::query()->get() as $party) {
            $balances += $ledger->partyBalance($party, $this->period->to);
        }

        /*
        | The witness first: this scenario really does have party debt in it — two wholesale
        | sales on credit, a cheque that bounced and was chased, and one endorsed to a
        | supplier. Without this line the reconciliation below could be 0 === 0.
        */
        expect($outstanding + $credit)->toBeGreaterThan(0);

        // The conservation claim, against a scenario built by somebody else for a different
        // purpose. If the aging report ever disagrees with the ledger, the report is wrong
        // (ADR 0003) — and this is the line that says so.
        expect($outstanding - $credit)->toBe($balances);
    });
});

it('keeps the cleared cheque out of the month it was due in', function (): void {
    ($this->inTenant)(function (): void {
        $rows = app(FinancialReports::class)->chequeCalendar($this->period);

        /*
        | The crazy month's headline cheque: 450,000,000 received, due on day 20, deposited,
        | **bounced**, chased, re-deposited and cleared on day 28. So on its due date it is
        | reported — and it contributes **nothing to the net**, because that money arrived.
        |
        | This is the golden number that makes the calendar's central decision checkable
        | against a fixture nobody built for it. A calendar that counts cleared cheques as
        | incoming promises this shop 45,000,000 toman of cash it already banked.
        */
        $due = null;

        foreach ($rows as $row) {
            if ($row['cleared'] > 0) {
                $due = $row;
            }
        }

        // The witness, before the figures: the row has to exist for the assertions under it
        // to be about anything.
        expect($due)->not->toBeNull();

        expect($due['cleared'] ?? 0)->toBe(450_000_000)
            ->and($due['incoming'] ?? -1)->toBe(0)
            ->and($due['net'] ?? -1)->toBe(0);

        /*
        | The endorsed cheque — 280,000,000, still outstanding at month end — is due on day
        | 75, well outside this range, and it is NOT overdue either: its date is in the
        | future. Both halves matter, so both are asserted.
        */
        $overdue = app(FinancialReports::class)->overdueCheques($this->period->to);

        expect($overdue['incoming'])->toBe(0)
            ->and($overdue['outgoing'])->toBe(0);
    });
});

it('reports no VAT for a month with no sales, and says that is why', function (): void {
    ($this->inTenant)(function (): void {
        $monthly = app(TaxReports::class)->monthly($this->period);
        $byRate = app(TaxReports::class)->byRate($this->period);

        /*
        | ⚠ Same limit as the sales assertions above, and asserted for the same reason.
        |
        | The crazy month contains no sales invoices, so there is nothing to charge VAT on
        | and every figure is legitimately zero. That is stated as the claim — «no rows
        | BECAUSE no sales» — rather than left as an exact figure nobody checked, which is
        | the *green without witness* trap in `docs/testing.md`.
        |
        | The day somebody adds a sale to the scenario, this fails and points them at
        | `TaxReportScreenTest`, which pins the VAT arithmetic (17,763,980 base ·
        | 1,776,380 tax, floored per line per ADR 0009) against a fixture built for it.
        */
        expect($monthly)->toBe([])
            ->and($byRate)->toBe([]);
    });
});

it('reports no SMS usage for a month with no messages, and says that is why', function (): void {
    ($this->inTenant)(function (): void {
        $usage = app(OperationsReports::class)->smsUsage($this->period);
        $wallet = app(OperationsReports::class)->smsWallet($this->period);

        /*
        | The crazy month is a treasury scenario: it sends nothing. Stated rather than
        | pinned, exactly as above — `OperationsReportScreenTest` holds the arithmetic.
        |
        | The wallet is worth asserting separately: a balance of zero is a *fact* about this
        | shop, not an absence of data, and a report that threw or returned null here would
        | be broken for every shop on its first morning.
        */
        expect($usage)->toBe([])
            ->and($wallet['balance'])->toBe(0)
            ->and($wallet['charges'])->toBe(0);
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
