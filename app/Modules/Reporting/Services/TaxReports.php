<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Services;

use App\Modules\Reporting\Support\ShopClock;
use App\Modules\Sales\Enums\InvoiceStatus;
use App\Modules\Sales\Models\SalesInvoice;
use App\Support\Jalali;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * مالیات بر ارزش افزوده — what was charged, at what rate, in which month.
 *
 * ## It reproduces the invoices. It does not recompute them.
 *
 * This is the binding rule, and it is written down in
 * [ADR 0009](../../../../docs/adr/0009-invoice-rounding.md) (Amendment) rather than here
 * because the invoice side has to honour the same one: **per-line VAT floors to a whole
 * toman at issue, and the stored figure is the figure.**
 *
 * Re-deriving VAT from a month's revenue at today's rate is the obvious implementation and
 * it is wrong three times over. It rounds once over a month instead of once per line, so it
 * disagrees with the invoices by the accumulated remainder. It applies today's rate to
 * invoices issued under a different one — the rate lives in each invoice's
 * `settings_snapshot` precisely so a reprint keeps printing what it printed. And the
 * difference accrues in the shop's favour, which is the direction a tax authority notices.
 *
 * So every figure here is a SUM over `sales_invoice_items.vat_amount`, the column
 * finalisation wrote.
 *
 * ## Voided invoices keep their number and lose their money
 *
 * A void invoice is not deleted — the number would leave a gap the taxman asks about — so
 * it is still a row with a `vat_amount` on it. Counting it would charge the shop tax on a
 * sale it un-made. Only `final` invoices are summed, which is the same predicate every
 * other report in this module uses.
 *
 * ## The base is the line total minus its own VAT
 *
 * `line_total` is VAT-inclusive. «مأخذ مشمول» is what the tax was charged *on*, so it is
 * `line_total - vat_amount` — the same expression `SalesReports` calls revenue, for the
 * same reason: two definitions of the taxable base is one more than a shop can defend.
 */
final class TaxReports
{
    /**
     * VAT per Jalali month.
     *
     * Folded in PHP by `Jalali::monthKey()` for the reason `SalesReports::monthly()` gives:
     * Postgres has no Jalali calendar, and `date_trunc('month', …)` groups by the Gregorian
     * month, which straddles two Jalali ones. A VAT return filed against «مرداد» that
     * contains part of Tir is a wrong filing, not a cosmetic problem.
     *
     * @return list<array{label: string, invoices: int, taxable_base: int, exempt_base: int, vat: int, rounding: int}>
     */
    public function monthly(ReportPeriod $period, ?int $branchId = null): array
    {
        $months = [];

        foreach ($this->daily($period, $branchId) as $day) {
            $key = Jalali::monthKey($day['date']);

            $months[$key] ??= [
                'label' => Jalali::format($day['date'], 'F Y'),
                'invoices' => 0,
                'taxable_base' => 0,
                'exempt_base' => 0,
                'vat' => 0,
                'rounding' => 0,
            ];

            $months[$key]['invoices'] += $day['invoices'];
            $months[$key]['taxable_base'] += $day['taxable_base'];
            $months[$key]['exempt_base'] += $day['exempt_base'];
            $months[$key]['vat'] += $day['vat'];
            $months[$key]['rounding'] += $day['rounding'];
        }

        // Chronological. A VAT summary is read down the year, not ranked.
        ksort($months);

        return array_values($months);
    }

    /**
     * VAT per rate — «سهم هر نرخ», and the exempt lines beside them.
     *
     * A shop selling handsets at one rate and services at zero needs the split to fill in a
     * return, and a rate appearing that nobody expected is the tell that a product was set
     * up wrong.
     *
     * @return list<array{label: string, rate: int, lines: int, taxable_base: int, vat: int}>
     */
    public function byRate(ReportPeriod $period, ?int $branchId = null): array
    {
        $rows = DB::table('sales_invoice_items')
            ->join('sales_invoices', 'sales_invoices.id', '=', 'sales_invoice_items.sales_invoice_id')
            ->where('sales_invoices.type', SalesInvoice::TYPE_INVOICE)
            ->where('sales_invoices.status', InvoiceStatus::Final->value)
            ->whereNull('sales_invoices.deleted_at')
            ->whereBetween('sales_invoices.issued_at', [$period->from, $period->to])
            ->when($branchId !== null, fn ($q) => $q->where('sales_invoices.branch_id', $branchId))
            ->groupBy('sales_invoice_items.vat_rate')
            ->orderBy('sales_invoice_items.vat_rate')
            ->selectRaw('
                sales_invoice_items.vat_rate as rate,
                count(*) as lines,
                coalesce(sum(sales_invoice_items.line_total - sales_invoice_items.vat_amount), 0) as taxable_base,
                coalesce(sum(sales_invoice_items.vat_amount), 0) as vat
            ')
            ->get();

        $shaped = [];

        foreach ($rows as $row) {
            $values = (array) $row;
            $rate = $this->intOf($values['rate'] ?? 0);

            $shaped[] = [
                // A zero rate is «معاف یا با نرخ صفر» — the two are different in tax law and
                // this product cannot yet tell them apart, so it says the thing it knows
                // rather than picking one.
                'label' => $rate === 0 ? 'معاف / نرخ صفر' : sprintf('%d٪', $rate),
                'rate' => $rate,
                'lines' => $this->intOf($values['lines'] ?? 0),
                'taxable_base' => $this->intOf($values['taxable_base'] ?? 0),
                'vat' => $this->intOf($values['vat'] ?? 0),
            ];
        }

        return $shaped;
    }

    /**
     * The per-day figures the monthly fold is built from.
     *
     * `rounding_adjustment` is invoice-level, so it is summed over a subquery of distinct
     * invoices rather than over the join — a two-line invoice would otherwise contribute its
     * rounding twice. It is reported rather than absorbed because ADR 0009 rule 3 says the
     * paper must add up: an invoice's total is base + VAT + shipping − discount + rounding,
     * and a VAT summary that hides the last term cannot be tied back to the invoices.
     *
     * @return list<array{date: string, invoices: int, taxable_base: int, exempt_base: int, vat: int, rounding: int}>
     */
    private function daily(ReportPeriod $period, ?int $branchId): array
    {
        $day = ShopClock::dayOf('sales_invoices.issued_at');

        $lines = DB::table('sales_invoice_items')
            ->join('sales_invoices', 'sales_invoices.id', '=', 'sales_invoice_items.sales_invoice_id')
            ->where('sales_invoices.type', SalesInvoice::TYPE_INVOICE)
            ->where('sales_invoices.status', InvoiceStatus::Final->value)
            ->whereNull('sales_invoices.deleted_at')
            ->whereBetween('sales_invoices.issued_at', [$period->from, $period->to])
            ->when($branchId !== null, fn ($q) => $q->where('sales_invoices.branch_id', $branchId))
            // Ordinals, for the reason `SalesReports::daily()` sets out: `GROUP BY 1` IS
            // the first select column, so the two cannot drift into a grouped query whose
            // table does not add up to its own headings.
            ->groupByRaw('1')
            ->orderByRaw('1')
            ->selectRaw("
                {$day} as date,
                coalesce(sum(case when sales_invoice_items.vat_rate > 0 then sales_invoice_items.line_total - sales_invoice_items.vat_amount else 0 end), 0) as taxable_base,
                coalesce(sum(case when sales_invoice_items.vat_rate = 0 then sales_invoice_items.line_total else 0 end), 0) as exempt_base,
                coalesce(sum(sales_invoice_items.vat_amount), 0) as vat
            ")
            ->get()
            ->keyBy(fn (object $row): string => $this->stringOf(((array) $row)['date'] ?? ''));

        $invoices = DB::table('sales_invoices')
            ->where('sales_invoices.type', SalesInvoice::TYPE_INVOICE)
            ->where('sales_invoices.status', InvoiceStatus::Final->value)
            ->whereNull('sales_invoices.deleted_at')
            ->whereBetween('sales_invoices.issued_at', [$period->from, $period->to])
            ->when($branchId !== null, fn ($q) => $q->where('sales_invoices.branch_id', $branchId))
            ->groupByRaw('1')
            ->orderByRaw('1')
            ->selectRaw("
                {$day} as date,
                count(*) as invoices,
                coalesce(sum(sales_invoices.rounding_adjustment), 0) as rounding
            ")
            ->get()
            ->keyBy(fn (object $row): string => $this->stringOf(((array) $row)['date'] ?? ''));

        $shaped = [];

        foreach ($invoices as $date => $row) {
            $invoiceValues = (array) $row;
            $lineValues = (array) ($lines[$date] ?? new stdClass);

            $shaped[] = [
                'date' => (string) $date,
                'invoices' => $this->intOf($invoiceValues['invoices'] ?? 0),
                'taxable_base' => $this->intOf($lineValues['taxable_base'] ?? 0),
                'exempt_base' => $this->intOf($lineValues['exempt_base'] ?? 0),
                'vat' => $this->intOf($lineValues['vat'] ?? 0),
                'rounding' => $this->intOf($invoiceValues['rounding'] ?? 0),
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
}
