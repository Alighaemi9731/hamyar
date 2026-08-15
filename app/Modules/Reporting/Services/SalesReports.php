<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Services;

use App\Modules\Reporting\Support\ShopClock;
use App\Modules\Sales\Enums\InvoiceStatus;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Services\ProfitEngine;
use App\Support\Jalali;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * What the shop sold, cut the ways a shopkeeper asks about it.
 *
 * ## Every figure here comes from `sales_invoice_items`, not from a re-derivation
 *
 * Revenue is `line_total - vat_amount` and cost is `cost_snapshot * quantity` — the same
 * expressions {@see ProfitEngine} uses, because a report that computes margin its own way
 * disagrees with the invoice it summarises the first time a rounding adjustment or a return
 * appears. ADR 0009 is explicit that reports must reproduce invoice figures exactly, and
 * the cheapest way to guarantee that is to read the same columns.
 *
 * Where a total is needed rather than a breakdown, `ProfitEngine` is called rather than
 * copied.
 *
 * ## Void invoices are excluded, never netted
 *
 * A voided sale did not happen. Netting it against the day's takings would make a shop that
 * corrected one mistake look like it sold two phones and returned one — which is a
 * different story with different tax consequences.
 *
 * ## Returns are their own line, not a negative sale
 *
 * `ProfitEngine` already nets returns into period margin. Here they are reported separately
 * because «چقدر فروختیم» and «چقدر برگشت خورد» are two questions, and a single net figure
 * answers neither.
 */
final class SalesReports
{
    public function __construct(private readonly ProfitEngine $profit) {}

    /**
     * Sales by day, for a line chart and a table under it.
     *
     * ## The day is the shop's day, not the server's
     *
     * `issued_at` is stored UTC (golden rule 5) and the bucket is the Tehran calendar day,
     * so the timestamp is shifted before it is truncated. Grouping on `date(issued_at)`
     * directly puts anything sold between midnight and 03:30 Tehran onto the previous
     * day's row — and, worse, onto the previous *month's* row eleven times a year, where
     * {@see monthly()} would then report it under the wrong month entirely.
     *
     * @return list<array<string, mixed>>
     */
    public function daily(ReportPeriod $period, ?int $branchId = null): array
    {
        $day = $this->shopDayExpression();

        $rows = DB::table('sales_invoice_items')
            ->join('sales_invoices', 'sales_invoices.id', '=', 'sales_invoice_items.sales_invoice_id')
            ->where('sales_invoices.type', SalesInvoice::TYPE_INVOICE)
            ->where('sales_invoices.status', InvoiceStatus::Final->value)
            ->whereNull('sales_invoices.deleted_at')
            ->whereBetween('sales_invoices.issued_at', [$period->from, $period->to])
            ->when($branchId !== null, fn ($q) => $q->where('sales_invoices.branch_id', $branchId))
            // Ordinals, not a repeat of the expression: `GROUP BY 1` IS the first select
            // column, so the two cannot drift apart — and a grouped query whose GROUP BY
            // is a different expression than its SELECT is legal SQL that returns a table
            // which does not add up to its own headings.
            ->groupByRaw('1')
            ->orderByRaw('1')
            ->selectRaw("
                {$day} as day,
                count(distinct sales_invoices.id) as invoices,
                coalesce(sum(sales_invoice_items.line_total - sales_invoice_items.vat_amount), 0) as revenue,
                coalesce(sum(sales_invoice_items.cost_snapshot * sales_invoice_items.quantity), 0) as cost
            ")
            ->get();

        return $this->shape($rows, 'day', 'date');
    }

    /**
     * Sales by **Jalali** month — the twelve-row view of a year.
     *
     * ## Folded in PHP, and that is not a shortcut
     *
     * Postgres has no Jalali calendar, so `date_trunc('month', …)` would group by the
     * Gregorian month — and a Gregorian month straddles two Jalali ones. «فروش مرداد»
     * would then be part Tir and part Mordad, which is not a wrong total so much as an
     * answer to a question nobody asked.
     *
     * So the daily rows are folded by {@see Jalali::monthKey()}, the same key
     * the recurring-expense generator books against. A year is at most 366 rows, so the
     * fold costs nothing measurable and the calendar is right by construction.
     *
     * The label is «مرداد ۱۴۰۵» rather than a number: this report is read aloud in
     * conversations about which month was better.
     *
     * @return list<array<string, mixed>>
     */
    public function monthly(ReportPeriod $period, ?int $branchId = null): array
    {
        $months = [];

        foreach ($this->daily($period, $branchId) as $day) {
            $date = is_scalar($day['date'] ?? null) ? (string) $day['date'] : '';

            if ($date === '') {
                continue;
            }

            $key = Jalali::monthKey($date);

            $months[$key] ??= [
                'label' => Jalali::format($date, 'F Y'),
                'invoices' => 0,
                'quantity' => 0,
                'revenue' => 0,
                'cost' => 0,
                'margin' => 0,
            ];

            $months[$key]['invoices'] += $this->intOf($day['invoices'] ?? 0);
            $months[$key]['revenue'] += $this->intOf($day['revenue'] ?? 0);
            $months[$key]['cost'] += $this->intOf($day['cost'] ?? 0);
        }

        // Chronological, not biggest-first. The point of a month-per-row table is the
        // shape of the year, and sorting it by size destroys exactly that.
        ksort($months);

        $rows = [];

        foreach ($months as $month) {
            $month['margin'] = $month['revenue'] - $month['cost'];
            $rows[] = $month;
        }

        return $rows;
    }

    /**
     * Sales by brand — «کدام برند می‌فروشد».
     *
     * The Persian name leads and the Latin one is the fallback, because that is the order
     * the rest of the product renders a brand in. A line with no variant behind it (a
     * service, or a handset sold off its own unit record) has no brand and is **kept**,
     * grouped under one unnamed row: dropping it would make the brand cut disagree with
     * every other cut of the same range, which is the one thing a set of cuts must not do.
     *
     * @return list<array<string, mixed>>
     */
    public function byBrand(ReportPeriod $period, ?int $branchId = null, int $limit = 50, string $order = 'revenue'): array
    {
        return $this->grouped($period, $branchId, $limit, "coalesce(nullif(brands.name_fa, ''), brands.name)", 'brands.id', [
            'join' => fn ($query) => $query
                ->leftJoin('product_variants', 'product_variants.id', '=', 'sales_invoice_items.product_variant_id')
                ->leftJoin('products', 'products.id', '=', 'product_variants.product_id')
                ->leftJoin('brands', 'brands.id', '=', 'products.brand_id'),
            'order' => $order === 'margin' ? 'margin' : 'revenue',
        ]);
    }

    /**
     * Sales by product, biggest first — «چی می‌فروشه».
     *
     * @return list<array<string, mixed>>
     */
    public function byProduct(ReportPeriod $period, ?int $branchId = null, int $limit = 50, string $order = 'revenue'): array
    {
        return $this->grouped($period, $branchId, $limit, 'products.name', 'products.id', [
            'join' => fn ($query) => $query
                ->leftJoin('product_variants', 'product_variants.id', '=', 'sales_invoice_items.product_variant_id')
                ->leftJoin('products', 'products.id', '=', 'product_variants.product_id'),
            'order' => $order === 'margin' ? 'margin' : 'revenue',
        ]);
    }

    /**
     * Sales by salesperson — the report a commission conversation starts from.
     *
     * @return list<array<string, mixed>>
     */
    public function bySalesperson(ReportPeriod $period, ?int $branchId = null, int $limit = 50): array
    {
        return $this->grouped($period, $branchId, $limit, 'users.name', 'users.id', [
            'join' => fn ($query) => $query->leftJoin('users', 'users.id', '=', 'sales_invoices.salesperson_id'),
        ]);
    }

    /**
     * The period totals — the same numbers `ProfitEngine` gives, by construction.
     *
     * The key names mirror `ProfitEngine`'s exactly — `profit`, not `margin`, and
     * `returned_revenue`, not `returns`. Renaming them here would give the shop two names
     * for one figure and make a mismatch between two screens impossible to spot.
     *
     * @return array{revenue: int, cost: int, profit: int, margin_percent: float, invoice_count: int, returned_revenue: int}
     */
    public function summary(ReportPeriod $period, ?int $branchId = null): array
    {
        $margin = $this->profit->forPeriod($period->from, $period->to, $branchId);

        return [
            'revenue' => $this->intOf($margin['revenue'] ?? 0),
            'cost' => $this->intOf($margin['cost'] ?? 0),
            'profit' => $this->intOf($margin['profit'] ?? 0),
            'margin_percent' => is_numeric($margin['margin_percent'] ?? null) ? (float) $margin['margin_percent'] : 0.0,
            'invoice_count' => $this->intOf($margin['invoice_count'] ?? 0),
            'returned_revenue' => $this->intOf($margin['returned_revenue'] ?? 0),
        ];
    }

    /**
     * One grouped cut of the same underlying columns.
     *
     * ## The sort is part of the question, so it happens in SQL
     *
     * `LIMIT 50` on top of «biggest by revenue» and `LIMIT 50` on top of «biggest by
     * margin» are two different fifty rows. Re-sorting the first set in PHP would answer
     * "the fifty best sellers, arranged by profit" to somebody who asked for the fifty
     * most profitable — plausibly, and wrongly, and most visibly on exactly the low-volume
     * high-margin lines a profit report exists to surface.
     *
     * @param  array{join: callable, order?: 'revenue'|'margin'}  $options
     * @return list<array<string, mixed>>
     */
    private function grouped(ReportPeriod $period, ?int $branchId, int $limit, string $labelColumn, string $groupColumn, array $options): array
    {
        $revenue = 'coalesce(sum(sales_invoice_items.line_total - sales_invoice_items.vat_amount), 0)';
        $cost = 'coalesce(sum(sales_invoice_items.cost_snapshot * sales_invoice_items.quantity), 0)';

        $order = ($options['order'] ?? 'revenue') === 'margin' ? "({$revenue}) - ({$cost})" : $revenue;

        $query = DB::table('sales_invoice_items')
            ->join('sales_invoices', 'sales_invoices.id', '=', 'sales_invoice_items.sales_invoice_id');

        ($options['join'])($query);

        $rows = $query
            ->where('sales_invoices.type', SalesInvoice::TYPE_INVOICE)
            ->where('sales_invoices.status', InvoiceStatus::Final->value)
            ->whereNull('sales_invoices.deleted_at')
            ->whereBetween('sales_invoices.issued_at', [$period->from, $period->to])
            ->when($branchId !== null, fn ($q) => $q->where('sales_invoices.branch_id', $branchId))
            ->groupBy(DB::raw($groupColumn), DB::raw($labelColumn))
            ->orderByDesc(DB::raw($order))
            ->limit($limit)
            ->selectRaw("
                {$labelColumn} as label,
                coalesce(sum(sales_invoice_items.quantity), 0) as quantity,
                coalesce(sum(sales_invoice_items.line_total - sales_invoice_items.vat_amount), 0) as revenue,
                coalesce(sum(sales_invoice_items.cost_snapshot * sales_invoice_items.quantity), 0) as cost
            ")
            ->get();

        return $this->shape($rows, 'label', 'label');
    }

    /**
     * @param  \Illuminate\Support\Collection<int, stdClass>  $rows
     * @return list<array<string, mixed>>
     */
    private function shape(\Illuminate\Support\Collection $rows, string $sourceKey, string $targetKey): array
    {
        $shaped = [];

        foreach ($rows as $row) {
            $values = (array) $row;

            $revenue = $this->intOf($values['revenue'] ?? 0);
            $cost = $this->intOf($values['cost'] ?? 0);

            $shaped[] = [
                $targetKey => is_scalar($values[$sourceKey] ?? null) ? (string) $values[$sourceKey] : '',
                'invoices' => $this->intOf($values['invoices'] ?? 0),
                'quantity' => $this->intOf($values['quantity'] ?? 0),
                'revenue' => $revenue,
                'cost' => $cost,
                // Derived here rather than in SQL so it is the same subtraction everywhere,
                // and so a null cost cannot make it null.
                'margin' => $revenue - $cost,
            ];
        }

        return $shaped;
    }

    private function intOf(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * `issued_at` shifted from stored UTC into the shop's wall clock, then truncated.
     *
     * The expression itself — and the reason the timezone is inlined rather than bound —
     * moved to {@see ShopClock} when the financial reports needed the same shift. Grouping
     * by ordinal solves the `GROUP BY`-matches-`SELECT` half; `ShopClock` is the value.
     */
    private function shopDayExpression(): string
    {
        return ShopClock::dayOf('sales_invoices.issued_at');
    }
}
