<?php

declare(strict_types=1);

use App\Modules\Platform\Models\Tenant;
use App\Modules\Reporting\Services\DashboardWidgets;
use App\Modules\Reporting\Services\FinancialReports;
use App\Modules\Reporting\Services\InventoryReports;
use App\Modules\Reporting\Services\OperationsReports;
use App\Modules\Reporting\Services\ProfitReports;
use App\Modules\Reporting\Services\ReportPeriod;
use App\Modules\Reporting\Services\SalesReports;
use App\Modules\Reporting\Services\TaxReports;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Database\Seeders\BulkVolumeSeeder;
use Illuminate\Support\Facades\DB;

/**
 * The performance budget: the top reports under 300ms on a shop with a year of real
 * volume behind it (`docs/specs/reporting.md`, roadmap 9.3).
 *
 * ## What this test is for
 *
 * A missing index costs nothing on the demo shop's eleven invoices and everything on a
 * shop that has been trading for a year — and the shop that has been trading for a year
 * is the one that opens a report every morning. Without a fixture at that size the
 * failure mode is a support ticket eighteen months after the index was forgotten, from
 * the customer with the most data and the least patience.
 *
 * ## The order of the assertions is not decoration
 *
 * The volume is asserted **before** anything is timed. A latency test against an empty
 * table is the purest form of *green without witness* (`docs/testing.md`): every report
 * returns instantly, the budget passes by a factor of a hundred, and the number it
 * reports is the speed of `select … where false`. So the row counts come first, and they
 * are asserted against the fixture's own report of what it wrote.
 *
 * ## Nothing here asserts money
 *
 * Every figure in the fixture is arithmetic {@see BulkVolumeSeeder} invented. Reports are
 * pinned to exact rial in `SalesReportScreenTest` and `GoldenNumbersTest`, against
 * scenarios built by the real services. Here they are pinned to a clock.
 *
 * ## The neighbour is half the fixture
 *
 * Both shops are filled to the same size, so the table holds twice what any report reads
 * and the tenant predicate has to narrow it. On a single-tenant table a sequential scan
 * and an index scan do the same work, and the test would pass with every index dropped.
 *
 * ## Every report the catalogue lists is measured here
 *
 * The spec's budget is over "the top ten reports", and for most of Phase 9 this file
 * measured the four that existed. It now covers all of them — sales in five cuts, profit in
 * three, both inventory cuts, both aging directions, the cheque calendar, the instalment
 * book, both VAT cuts, SMS usage, and three dashboard widgets: **26 measurements**.
 *
 * The only screen absent is `repairs.technicians`, and it is absent for the reason this
 * file gives about everything else: `BulkVolumeSeeder` writes no repair tickets, so timing
 * it would measure an empty table. It goes in with the fixture, not before it.
 *
 * ## What this test is NOT, said plainly
 *
 * It is a **ceiling**, not a regression detector. The measured figures at the time of
 * writing are 1–93ms against a 300ms budget, so a change that made a report three times
 * slower would still pass. Tightening the number to close that gap would buy a test that
 * fails on a busy CI box and teaches everyone to re-run it, which is worse than a
 * generous one that means what it says.
 *
 * The regression detector for a *plan* change is the fixture itself: seed it, run
 * `EXPLAIN (ANALYZE, BUFFERS)`, and read what the scan is proportional to. That is how
 * the index in `2026_08_15_000100_index_sales_invoices_for_reporting` was found, and the
 * numbers are written down there rather than asserted here.
 */
pest()->group('performance');

/** The budget from `docs/specs/reporting.md`, in milliseconds. */
const REPORT_BUDGET_MS = 300.0;

beforeEach(function (): void {
    /*
    | No plan, no roles, no logged-in user: the reports are measured as services, which
    | is where the query cost lives. Going through HTTP would add framework boot to
    | every figure and turn a budget for the schema into a benchmark for the framework —
    | and the controller layer already has its guard, the bounded query count in
    | `DashboardTest`.
    */
    $this->tenant = Tenant::factory()->withDomain()->create();
    $this->neighbour = Tenant::factory()->withDomain()->create();

    $seeder = new BulkVolumeSeeder;

    $this->counts = $seeder->fill($this->tenant);
    $this->neighbourCounts = $seeder->fill($this->neighbour);
});

afterEach(fn () => app(TenantContext::class)->forget());

it('answers every top report inside the budget on a year of trading', function (): void {
    /*
    | ---------------------------------------------------------------- witness --
    | What the fixture actually contains, asserted before a single clock starts.
    */
    expect($this->counts['items'])->toBeGreaterThanOrEqual(100_000)
        ->and($this->counts['invoices'])->toBeGreaterThanOrEqual(BulkVolumeSeeder::INVOICES)
        ->and($this->counts['parties'])->toBeGreaterThanOrEqual(BulkVolumeSeeder::PARTIES)
        ->and($this->counts['movements'])->toBeGreaterThan(50_000)
        ->and($this->counts['ledger_entries'])->toBeGreaterThan(50_000)
        // The tables the Phase 9.2 reports read. Each one is here because the report
        // below it cannot be measured without rows: a cheque calendar over an empty
        // `cheques` table times `select … where false` and passes by a factor of a
        // thousand.
        ->and($this->counts['units'])->toBeGreaterThanOrEqual(BulkVolumeSeeder::UNITS)
        ->and($this->counts['cheques'])->toBeGreaterThanOrEqual(BulkVolumeSeeder::CHEQUES)
        ->and($this->counts['installment_rows'])
        ->toBeGreaterThanOrEqual(BulkVolumeSeeder::PLANS * BulkVolumeSeeder::PLAN_ROWS)
        ->and($this->counts['messages'])->toBeGreaterThanOrEqual(BulkVolumeSeeder::MESSAGES)
        // The neighbour is the same size: the tenant predicate has something to narrow.
        ->and($this->neighbourCounts['items'])->toBe($this->counts['items'])
        ->and($this->neighbourCounts['cheques'])->toBe($this->counts['cheques']);

    /*
    | ------------------------------------------------------------ the reports --
    | Both ranges the filter bar offers, because they are different questions of the
    | schema. A month reads a slice and is about whether the range can be entered on
    | an index at all; «امسال» reads the whole 100,000 and is about what happens when
    | it cannot. Timing only the month would flatter every index here.
    */
    $reports = app(SalesReports::class);
    $profit = app(ProfitReports::class);
    $inventory = app(InventoryReports::class);
    $financial = app(FinancialReports::class);
    $tax = app(TaxReports::class);
    $operations = app(OperationsReports::class);
    $widgets = app(DashboardWidgets::class);
    $end = CarbonImmutable::parse(BulkVolumeSeeder::YEAR_END);
    $month = ReportPeriod::of($end->subDays(29), $end);
    $year = ReportPeriod::of($end->subDays(BulkVolumeSeeder::DAYS - 1), $end);

    /** @var array<string, float> $timings */
    $timings = inTenantContext($this->tenant, fn (): array => [
        'sales.daily/month' => measureReport(fn () => $reports->daily($month)),
        'sales.by_product/month' => measureReport(fn () => $reports->byProduct($month)),
        'sales.by_salesperson/month' => measureReport(fn () => $reports->bySalesperson($month)),
        'sales.summary/month' => measureReport(fn () => $reports->summary($month)),
        'sales.by_brand/month' => measureReport(fn () => $reports->byBrand($month)),
        'sales.monthly/year' => measureReport(fn () => $reports->monthly($year)),
        'sales.daily/year' => measureReport(fn () => $reports->daily($year)),
        'sales.by_product/year' => measureReport(fn () => $reports->byProduct($year)),
        'sales.by_brand/year' => measureReport(fn () => $reports->byBrand($year)),
        'sales.by_salesperson/year' => measureReport(fn () => $reports->bySalesperson($year)),
        'sales.summary/year' => measureReport(fn () => $reports->summary($year)),
        // Margin-ordered, so the sort is an expression over two SUMs rather than one —
        // a different plan from the revenue-ordered cut above, and worth its own clock.
        'profit.by_product/year' => measureReport(fn () => $profit->byProduct($year)),
        'profit.by_brand/year' => measureReport(fn () => $profit->byBrand($year)),
        // The fixture grew serialized handsets, so the cut this file previously deferred
        // is measurable and measured.
        'profit.per_imei/year' => measureReport(fn () => $profit->perUnit($year)),

        /*
        | ------------------------------------------------------------ inventory --
        | Both halves of the shelf: a SUM over 100,000 movements with the weighted-average
        | expression on top, plus 5,000 rows of the unit register. Dead stock is the same
        | scan with a HAVING over a `max(case …)`, which is a different plan.
        */
        'inventory.valuation' => measureReport(fn () => $inventory->valuation($end)),
        'inventory.dead_stock' => measureReport(fn () => $inventory->deadStock(90)),

        /*
        | ------------------------------------------------------------ financial --
        | Aging is the heaviest query in the module: a window function over every ledger
        | lot, partitioned by party. It is measured over the whole year rather than a
        | month because a balance has no range — it reads everything up to the as-of date
        | by definition, which is the point of timing it at all.
        */
        'financial.aging/receivable' => measureReport(fn () => $financial->aging($end)),
        'financial.aging/payable' => measureReport(fn () => $financial->aging($end, FinancialReports::PAYABLE)),
        'financial.cheques/year' => measureReport(fn () => $financial->chequeCalendar($year)),
        'financial.installments/year' => measureReport(fn () => $financial->installmentsBook($year)),

        /* ------------------------------------------------------------------ tax -- */
        'tax.vat_monthly/year' => measureReport(fn () => $tax->monthly($year)),
        'tax.by_rate/year' => measureReport(fn () => $tax->byRate($year)),

        /* ----------------------------------------------------------- operations -- */
        'operations.sms/year' => measureReport(fn () => $operations->smsUsage($year)),

        'dashboard.today' => measureReport(fn () => $widgets->todaysTrade(true)),
        'dashboard.trend' => measureReport(fn () => $widgets->salesTrend(true)),
        'dashboard.low_stock' => measureReport(fn () => $widgets->lowStock()),
    ]);

    // Printed on failure as well as on success — "which report" is the first question,
    // and a bare "300.4 is not less than 300" does not answer it.
    foreach ($timings as $report => $ms) {
        expect($ms)->toBeLessThan(
            REPORT_BUDGET_MS,
            sprintf('%s took %.1fms, over the %dms budget. Timings: %s', $report, $ms, REPORT_BUDGET_MS, json_encode($timings)),
        );
    }

    /*
    | ------------------------------------------------------- one shop, not two --
    | The timings above are only meaningful if the report read one shop's rows, and on
    | a fixture where the neighbour is the same size a leak would not look like a leak:
    | the figures would merely be twice as big and entirely plausible.
    |
    | So the count is checked against the same number arrived at another way — a plain
    | COUNT over the invoices table with the tenant named explicitly, rather than the
    | report's join-and-distinct. The endpoint-level isolation tests live in
    | `SalesReportScreenTest`; this is the arithmetic saying the same thing at volume.
    */
    /** @var array{0: int, 1: int} $counted */
    $counted = inTenantContext($this->tenant, fn (): array => [
        $reports->summary($month)['invoice_count'],
        DB::table('sales_invoices')
            ->where('tenant_id', $this->tenant->getKey())
            ->where('type', 'invoice')
            ->where('status', 'final')
            ->whereNull('deleted_at')
            ->whereBetween('issued_at', [$month->from, $month->to])
            ->count(),
    ]);

    [$reported, $expected] = $counted;

    expect($expected)->toBeGreaterThan(1_000)   // ...and it is not counting nothing.
        ->and($reported)->toBe($expected);
});

/**
 * The median of three runs, after a warm-up.
 *
 * The warm-up is discarded because the first call pays for connection setup, the
 * statement cache and a cold shared-buffer pool, none of which a shop pays on the
 * hundredth report of the morning. The median rather than the mean because one
 * unlucky run on a busy CI box should not decide whether an index exists.
 */
function measureReport(Closure $call, int $runs = 3): float
{
    $call();

    $times = [];

    for ($i = 0; $i < $runs; $i++) {
        $started = hrtime(true);
        $call();
        $times[] = (hrtime(true) - $started) / 1_000_000;
    }

    sort($times);

    return $times[intdiv(count($times), 2)];
}
