<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Models\User;
use App\Modules\Reporting\Services\ReportPeriod;
use App\Modules\Reporting\Services\SavedFilters;
use App\Modules\Reporting\Services\TaxReports;
use App\Modules\Reporting\Support\ReportAccess;
use App\Support\Money;
use App\Support\Spreadsheet\ArraySheet;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * The VAT return, as far as this product can fill it in.
 *
 * ## Refused, not stripped
 *
 * Same shape as the profit report: a tax summary with the tax figures removed is an empty
 * table under a heading that promises otherwise. `ReportAccess::showsTax()` decides, and it
 * hides the rows from the index too.
 *
 * ## The figures are the invoices', reproduced
 *
 * See {@see TaxReports} and [ADR 0009](../../../../../docs/adr/0009-invoice-rounding.md)
 * (Amendment): per-line VAT floored at issue is the number, and nothing here recomputes it
 * from a period total at today's rate.
 */
final class TaxReportController extends Controller
{
    private const CUTS = ['monthly', 'rate'];

    public function index(Request $request, TaxReports $reports, SavedFilters $presets): Response
    {
        $this->authorise($request);

        $cut = $this->cut($request);
        $period = ReportPeriod::fromJalali($request->string('from')->value(), $request->string('to')->value());

        $rows = $cut === 'rate' ? $reports->byRate($period) : $reports->monthly($period);

        return Inertia::render('Reporting::Reports/Tax', [
            'cut' => $cut,
            'period' => $period->toArray(),
            'can_export' => $request->user() instanceof User && $request->user()->can('reporting.export'),
            'report_key' => 'tax',
            'presets' => $presets->forReport($request->user(), 'tax'),
            'rows' => $this->payloadRows($rows, $cut),
            'totals' => $this->totals($rows),
        ]);
    }

    public function export(Request $request, TaxReports $reports): BinaryFileResponse
    {
        $this->authorise($request);

        $user = $request->user();

        abort_unless($user instanceof User && $user->can('reporting.export'), 403);

        $cut = $this->cut($request);
        $period = ReportPeriod::fromJalali($request->string('from')->value(), $request->string('to')->value());

        $headings = $cut === 'rate'
            ? ['نرخ', 'تعداد سطر', 'مأخذ مشمول (ریال)', 'مأخذ مشمول', 'مالیات (ریال)', 'مالیات']
            : ['ماه', 'تعداد فاکتور', 'مأخذ مشمول (ریال)', 'مأخذ مشمول', 'معاف (ریال)', 'معاف', 'مالیات (ریال)', 'مالیات', 'گرد کردن (ریال)'];

        $sheet = [];

        foreach ($cut === 'rate' ? $reports->byRate($period) : $reports->monthly($period) as $row) {
            $base = $this->intOf($row['taxable_base'] ?? 0);
            $vat = $this->intOf($row['vat'] ?? 0);

            $sheet[] = $cut === 'rate'
                ? [
                    $this->stringOf($row['label'] ?? ''),
                    $this->intOf($row['lines'] ?? 0),
                    $base,
                    Money::toArray($base)['formatted'],
                    $vat,
                    Money::toArray($vat)['formatted'],
                ]
                : [
                    $this->stringOf($row['label'] ?? ''),
                    $this->intOf($row['invoices'] ?? 0),
                    $base,
                    Money::toArray($base)['formatted'],
                    $this->intOf($row['exempt_base'] ?? 0),
                    Money::toArray($this->intOf($row['exempt_base'] ?? 0))['formatted'],
                    $vat,
                    Money::toArray($vat)['formatted'],
                    $this->intOf($row['rounding'] ?? 0),
                ];
        }

        $name = sprintf('vat-%s-%s.xlsx', $cut, $period->from->toDateString());

        return Excel::download(new ArraySheet($headings, $sheet), $name);
    }

    private function authorise(Request $request): void
    {
        $user = $request->user();

        abort_unless($user instanceof User && $user->can('reporting.view'), 403);
        abort_unless(ReportAccess::showsTax($user), 403);
    }

    private function cut(Request $request): string
    {
        $cut = $request->string('cut')->value();

        return in_array($cut, self::CUTS, true) ? $cut : 'monthly';
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function payloadRows(array $rows, string $cut): array
    {
        $shaped = [];

        foreach ($rows as $row) {
            $line = [
                'label' => $this->stringOf($row['label'] ?? ''),
                'taxable_base' => Money::toArray($this->intOf($row['taxable_base'] ?? 0)),
                'vat' => Money::toArray($this->intOf($row['vat'] ?? 0)),
            ];

            if ($cut === 'rate') {
                $line['rate'] = $this->intOf($row['rate'] ?? 0);
                $line['lines'] = $this->intOf($row['lines'] ?? 0);
            } else {
                $line['invoices'] = $this->intOf($row['invoices'] ?? 0);
                $line['exempt_base'] = Money::toArray($this->intOf($row['exempt_base'] ?? 0));
                $line['rounding'] = Money::toArray($this->intOf($row['rounding'] ?? 0));
            }

            $shaped[] = $line;
        }

        return $shaped;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function totals(array $rows): array
    {
        $base = 0;
        $exempt = 0;
        $vat = 0;
        $rounding = 0;

        foreach ($rows as $row) {
            $base += $this->intOf($row['taxable_base'] ?? 0);
            $exempt += $this->intOf($row['exempt_base'] ?? 0);
            $vat += $this->intOf($row['vat'] ?? 0);
            $rounding += $this->intOf($row['rounding'] ?? 0);
        }

        return [
            'taxable_base' => Money::toArray($base),
            'exempt_base' => Money::toArray($exempt),
            'vat' => Money::toArray($vat),
            'rounding' => Money::toArray($rounding),
            'rows' => count($rows),
        ];
    }

    private function intOf(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private function stringOf(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }
}
