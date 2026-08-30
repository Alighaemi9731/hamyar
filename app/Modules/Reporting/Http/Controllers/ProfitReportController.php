<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Services\BranchContext;
use App\Modules\Reporting\Http\Concerns\MetersExports;
use App\Modules\Reporting\Services\ProfitReports;
use App\Modules\Reporting\Services\ReportPeriod;
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
 * «از چی سود کردیم» — by product, by brand, and per handset.
 *
 * ## The screen is refused, not stripped
 *
 * `SalesReportController` drops the cost and margin columns for a viewer without
 * permission and still shows them the shop's takings, which is a real answer to a real
 * question. There is no equivalent here: a profit report with the profit removed is an
 * empty table under a heading that promises otherwise. So a viewer who may not see margin
 * gets a 403, and {@see \App\Modules\Reporting\Services\ReportCatalogue} hides the rows
 * from the index using the same predicate — one boundary, so the index and the screen
 * cannot disagree about who may look.
 *
 * ## Two permissions decide it, and neither alone
 *
 * `ReportAccess` asks for `reporting.view_financial` **or** `sales.view_profit`, because
 * a shop grants margin from either direction (Gate 1). Asking for one of them here and
 * the other on the dashboard is how the same person sees profit on one screen and not the
 * other, and concludes the second screen is broken.
 */
final class ProfitReportController extends Controller
{
    use MetersExports;

    private const CUTS = ['product', 'brand', 'imei'];

    public function index(Request $request, ProfitReports $reports, SavedFilters $presets): Response
    {
        $this->authorise($request);

        $cut = $this->cut($request);
        $period = $this->period($request);

        $figures = $reports->summary($period, $this->branchIds());

        return Inertia::render('Reporting::Reports/Profit', [
            'report_key' => 'profit',
            'presets' => $presets->forReport($request->user(), 'profit'),
            'cut' => $cut,
            'period' => $period->toArray(),
            'can_export' => $request->user() instanceof User && $request->user()->can('reporting.export'),
            'summary' => [
                'revenue' => Money::toArray($figures['revenue']),
                'cost' => Money::toArray($figures['cost']),
                'profit' => Money::toArray($figures['profit']),
                'margin_percent' => $figures['margin_percent'],
                'invoice_count' => $figures['invoice_count'],
            ],
            'rows' => $this->payloadRows($this->rows($reports, $cut, $period)),
        ]);
    }

    public function export(Request $request, ProfitReports $reports): BinaryFileResponse
    {
        $this->authorise($request);

        $user = $request->user();

        abort_unless($user instanceof User && $user->can('reporting.export'), 403);

        $cut = $this->cut($request);
        $period = $this->period($request);

        $headings = $cut === 'imei'
            ? ['شناسه دستگاه', 'کالا', 'فاکتور', 'تاریخ فروش', 'مشتری', 'فروش (ریال)', 'فروش', 'بهای تمام‌شده (ریال)', 'بهای تمام‌شده', 'سود (ریال)', 'سود']
            : [$cut === 'brand' ? 'برند' : 'کالا', 'تعداد', 'فروش (ریال)', 'فروش', 'بهای تمام‌شده (ریال)', 'بهای تمام‌شده', 'سود (ریال)', 'سود'];

        $sheet = [];

        foreach ($this->rows($reports, $cut, $period) as $row) {
            // Money leaves as an integer AND the same formatted string the screen shows —
            // `Money::toArray()`, not a formatter chosen here, so the workbook cannot
            // quote rial while the page quotes toman (docs/specs/reporting.md).
            $money = [
                $row['revenue'],
                Money::toArray($row['revenue'])['formatted'],
                $row['cost'],
                Money::toArray($row['cost'])['formatted'],
                $row['margin'],
                Money::toArray($row['margin'])['formatted'],
            ];

            $sheet[] = $cut === 'imei'
                ? [$row['label'], $row['product'], $row['invoice'], $row['sold_at'], $row['customer'], ...$money]
                : [$row['label'], $row['count'], ...$money];
        }

        $name = sprintf('profit-%s-%s-%s.xlsx', $cut, $period->from->toDateString(), $period->to->toDateString());

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

    private function authorise(Request $request): void
    {
        $user = $request->user();

        abort_unless($user instanceof User && $user->can('reporting.view'), 403);
        abort_unless(ReportAccess::showsMargin($user), 403);
    }

    private function cut(Request $request): string
    {
        $cut = $request->string('cut')->value();

        return in_array($cut, self::CUTS, true) ? $cut : 'product';
    }

    private function period(Request $request): ReportPeriod
    {
        return ReportPeriod::fromJalali(
            $request->string('from')->value() ?: null,
            $request->string('to')->value() ?: null,
        );
    }

    /**
     * One cut's rows as plain integers — the shape both doors are built from.
     *
     * @return list<array{label: string, count: int, product: string, invoice: string, sold_at: string, customer: string, revenue: int, cost: int, margin: int}>
     */
    private function rows(ProfitReports $reports, string $cut, ReportPeriod $period): array
    {
        if ($cut === 'imei') {
            $rows = [];

            foreach ($reports->perUnit($period, $this->branchIds()) as $unit) {
                $rows[] = [
                    'label' => $unit['imei'] !== '' ? $unit['imei'] : 'بدون شناسه',
                    'count' => 1,
                    // The brand rides with the product name: «آیفون ۱۵ پرو» on its own is
                    // ambiguous across two shops' catalogues and this report gets printed.
                    'product' => trim($unit['brand'].' '.$unit['product']) ?: 'بدون عنوان',
                    'invoice' => $unit['invoice'],
                    'sold_at' => Jalali::format($unit['sold_at']),
                    'customer' => $unit['customer'] !== '' ? $unit['customer'] : 'مشتری گذری',
                    'revenue' => $unit['revenue'],
                    'cost' => $unit['cost'],
                    'margin' => $unit['margin'],
                ];
            }

            return $rows;
        }

        $raw = $cut === 'brand' ? $reports->byBrand($period, $this->branchIds()) : $reports->byProduct($period, $this->branchIds());

        $rows = [];

        foreach ($raw as $row) {
            $rows[] = [
                'label' => ($this->stringOf($row['label'] ?? '')) ?: ($cut === 'brand' ? 'بدون برند' : 'بدون عنوان'),
                'count' => $this->intOf($row['quantity'] ?? 0),
                'product' => '',
                'invoice' => '',
                'sold_at' => '',
                'customer' => '',
                'revenue' => $this->intOf($row['revenue'] ?? 0),
                'cost' => $this->intOf($row['cost'] ?? 0),
                'margin' => $this->intOf($row['margin'] ?? 0),
            ];
        }

        return $rows;
    }

    /**
     * @param  list<array{label: string, count: int, product: string, invoice: string, sold_at: string, customer: string, revenue: int, cost: int, margin: int}>  $rows
     * @return list<array<string, mixed>>
     */
    private function payloadRows(array $rows): array
    {
        $shaped = [];

        foreach ($rows as $row) {
            $shaped[] = [
                'label' => $row['label'],
                'count' => $row['count'],
                'product' => $row['product'],
                'invoice' => $row['invoice'],
                'sold_at' => $row['sold_at'],
                'customer' => $row['customer'],
                'revenue' => Money::toArray($row['revenue']),
                'cost' => Money::toArray($row['cost']),
                'margin' => Money::toArray($row['margin']),
            ];
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
