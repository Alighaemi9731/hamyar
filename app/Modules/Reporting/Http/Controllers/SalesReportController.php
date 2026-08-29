<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Services\BranchContext;
use App\Modules\Reporting\Http\Concerns\MetersExports;
use App\Modules\Reporting\Services\ReportPeriod;
use App\Modules\Reporting\Services\SalesReports;
use App\Modules\Reporting\Services\SavedFilters;
use App\Modules\Reporting\Support\ReportAccess;
use App\Support\Jalali;
use App\Support\Money;
use App\Support\Spreadsheet\ArraySheet;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * The sales report, in the three cuts a shopkeeper asks for.
 *
 * ## One screen, three cuts — not three screens
 *
 * «فروش روزانه», «فروش بر اساس کالا» and «فروش بر اساس فروشنده» are the same query
 * grouped three ways over the same range. Three routes would mean three filter bars
 * that drift, and a shopkeeper who sets a range on one and has to set it again on the
 * next. The cut is a parameter; the range survives switching it.
 *
 * ## The export is the same data, not a second query
 *
 * `rows()` is called by both the screen and the download. A separate export query is how
 * a spreadsheet ends up disagreeing with the page it was exported from — usually by one
 * boundary day, and usually discovered at year end.
 *
 * ## Money exports as an integer and a string, in two columns
 *
 * The integer is what a spreadsheet does arithmetic on; the formatted string is what a
 * human reads. Exporting only the formatted figure gives an accountant a column of text
 * they have to clean before they can sum it, and exporting only the integer gives them
 * rial where they expected toman (`docs/specs/reporting.md`).
 */
final class SalesReportController extends Controller
{
    use MetersExports;

    private const CUTS = ['daily', 'monthly', 'product', 'brand', 'salesperson'];

    /**
     * What the first column is called, per cut.
     *
     * «بدون عنوان» is wrong for a brand: a line with no brand behind it is not missing a
     * title, it is a product nobody filed under a marque, and «بدون برند» is what a shop
     * would write on the row themselves.
     */
    private const EMPTY_LABEL = ['brand' => 'بدون برند', 'salesperson' => 'بدون فروشنده'];

    public function index(Request $request, SalesReports $reports, SavedFilters $presets): Response
    {
        $this->authorise($request);

        $cut = $this->cut($request);
        $period = $this->period($request);
        $user = $request->user() instanceof User ? $request->user() : null;
        $showsMargin = ReportAccess::showsMargin($user);

        return Inertia::render('Reporting::Reports/Sales', [
            'report_key' => 'sales',
            'presets' => $presets->forReport($request->user(), 'sales'),
            'cut' => $cut,
            'period' => $period->toArray(),
            'shows_margin' => $showsMargin,
            'can_export' => $user instanceof User && $user->can('reporting.export'),
            'summary' => $this->summary($reports, $period, $showsMargin),
            'rows' => $this->payloadRows($this->rows($reports, $cut, $period), $showsMargin),
        ]);
    }

    public function export(Request $request, SalesReports $reports): BinaryFileResponse
    {
        $this->authorise($request);

        $user = $request->user();

        abort_unless($user instanceof User && $user->can('reporting.export'), 403);

        $cut = $this->cut($request);
        $period = $this->period($request);
        $showsMargin = ReportAccess::showsMargin($user);

        $headings = [
            match ($cut) {
                'daily' => 'تاریخ',
                'monthly' => 'ماه',
                'brand' => 'برند',
                'salesperson' => 'فروشنده',
                default => 'عنوان',
            },
            in_array($cut, ['daily', 'monthly'], true) ? 'تعداد فاکتور' : 'تعداد',
            'فروش (ریال)',
            'فروش',
        ];

        if ($showsMargin) {
            $headings = [...$headings, 'بهای تمام‌شده (ریال)', 'بهای تمام‌شده', 'سود (ریال)', 'سود'];
        }

        $sheet = [];

        foreach ($this->rows($reports, $cut, $period) as $row) {
            // The display column goes through `Money::toArray()` — the same call the
            // screen makes — rather than through a formatter chosen here. That is what
            // stops the spreadsheet quoting rial while the page quotes toman.
            $line = [
                $row['label'],
                $row['count'],
                $row['revenue'],
                Money::toArray($row['revenue'])['formatted'],
            ];

            if ($showsMargin) {
                $line = [
                    ...$line,
                    $row['cost'],
                    Money::toArray($row['cost'])['formatted'],
                    $row['margin'],
                    Money::toArray($row['margin'])['formatted'],
                ];
            }

            $sheet[] = $line;
        }

        // The Jalali range is in the filename because a folder of «گزارش فروش.xlsx»
        // files is a folder nobody can tell apart three months later.
        $name = sprintf(
            'sales-%s-%s-%s.xlsx',
            $cut,
            $period->from->toDateString(),
            $period->to->toDateString(),
        );

        /*
        | The credit, after the workbook is built and before it is handed over.
        |
        | Counting first would charge for a report that then failed to render — a refusal
        | the shop cannot see the cause of, on the one screen where they were trying to
        | get something out of the system. `reporting.exports` is one credit for all seven
        | reports: a shopkeeper thinks "I exported four things today", not "I exported two
        | sales reports and two tax reports".
        */
        $this->meterExport();

        return Excel::download(new ArraySheet($headings, $sheet), $name);
    }

    /**
     * `reporting.view` gates the whole module's screens; the plan gate is on the route.
     */
    private function authorise(Request $request): void
    {
        $user = $request->user();

        abort_unless($user instanceof User && $user->can('reporting.view'), 403);
    }

    private function cut(Request $request): string
    {
        $cut = $request->string('cut')->value();

        // An unknown cut falls back rather than 404s: a stale bookmark to a cut that was
        // renamed should still show the shop its sales.
        return in_array($cut, self::CUTS, true) ? $cut : 'daily';
    }

    private function period(Request $request): ReportPeriod
    {
        return ReportPeriod::fromJalali(
            $request->string('from')->value() ?: null,
            $request->string('to')->value() ?: null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(SalesReports $reports, ReportPeriod $period, bool $showsMargin): array
    {
        $figures = $reports->summary($period, $this->branchIds());

        $summary = [
            'revenue' => Money::toArray($figures['revenue']),
            'invoice_count' => $figures['invoice_count'],
            'returned_revenue' => Money::toArray($figures['returned_revenue']),
        ];

        if ($showsMargin) {
            $summary['cost'] = Money::toArray($figures['cost']);
            $summary['profit'] = Money::toArray($figures['profit']);
            $summary['margin_percent'] = $figures['margin_percent'];
        }

        return $summary;
    }

    /**
     * One cut's rows as plain integers — the shape both doors are built from.
     *
     * Cost and margin are always present here because the query returns them either
     * way. What differs is what leaves the server: {@see payloadRows()} and the export
     * both drop them for a viewer without margin permission, and both ask
     * {@see ReportAccess} rather than deciding for themselves. One boundary, two doors.
     *
     * @return list<array{label: string, count: int, revenue: int, cost: int, margin: int}>
     */
    private function rows(SalesReports $reports, string $cut, ReportPeriod $period): array
    {
        $raw = match ($cut) {
            'monthly' => $reports->monthly($period, $this->branchIds()),
            'product' => $reports->byProduct($period, $this->branchIds()),
            'brand' => $reports->byBrand($period, $this->branchIds()),
            'salesperson' => $reports->bySalesperson($period, $this->branchIds()),
            default => $reports->daily($period, $this->branchIds()),
        };

        // A period cut counts invoices; a dimension cut counts items. «تعداد فاکتور» on a
        // by-product row would be the number of baskets a battery appeared in, which is a
        // real figure and never the one anybody wanted.
        $countsInvoices = in_array($cut, ['daily', 'monthly'], true);

        $rows = [];

        foreach ($raw as $row) {
            $rows[] = [
                'label' => $cut === 'daily'
                    // Stored UTC, rendered Jalali (golden rule 5). The raw date stays out
                    // of the payload: nothing downstream needs it and a Gregorian string
                    // on a Persian report is a bug report.
                    ? Jalali::format(is_scalar($row['date'] ?? null) ? (string) $row['date'] : null)
                    : ($this->stringOf($row['label'] ?? '') ?: (self::EMPTY_LABEL[$cut] ?? 'بدون عنوان')),
                'count' => $countsInvoices
                    ? $this->intOf($row['invoices'] ?? 0)
                    : $this->intOf($row['quantity'] ?? 0),
                'revenue' => $this->intOf($row['revenue'] ?? 0),
                'cost' => $this->intOf($row['cost'] ?? 0),
                'margin' => $this->intOf($row['margin'] ?? 0),
            ];
        }

        return $rows;
    }

    /**
     * @param  list<array{label: string, count: int, revenue: int, cost: int, margin: int}>  $rows
     * @return list<array<string, mixed>>
     */
    private function payloadRows(array $rows, bool $showsMargin): array
    {
        $shaped = [];

        foreach ($rows as $row) {
            $line = [
                'label' => $row['label'],
                'count' => $row['count'],
                'revenue' => Money::toArray($row['revenue']),
            ];

            if ($showsMargin) {
                $line['cost'] = Money::toArray($row['cost']);
                $line['margin'] = Money::toArray($row['margin']);
            }

            $shaped[] = $line;
        }

        return $shaped;
    }

    private function intOf(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private function stringOf(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }

    /**
     * The branches this report covers: the one being viewed, or every branch the viewer is
     * allowed when they are looking at «همه شعب».
     *
     * Resolved here rather than threaded through the private helpers because several of
     * them run per cut, and a parameter on each was four more places for the two halves of
     * the rule to come apart. `BranchAccess` memoises, so the repeat calls are free.
     *
     * Before 10.1 the report controllers passed nothing at all — so a manager restricted to
     * one branch read the whole shop's figures the moment they opened a report. The access
     * floor was enforced on every list screen and on none of the reports.
     *
     * @return list<int>|null
     */
    private function branchIds(): ?array
    {
        return app(BranchContext::class)->scopeIds();
    }
}
