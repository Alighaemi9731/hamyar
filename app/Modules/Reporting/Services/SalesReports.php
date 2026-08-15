<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Services;

use App\Modules\Sales\Enums\InvoiceStatus;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Services\ProfitEngine;
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
     * @return list<array<string, mixed>>
     */
    public function daily(ReportPeriod $period, ?int $branchId = null): array
    {
        $rows = DB::table('sales_invoice_items')
            ->join('sales_invoices', 'sales_invoices.id', '=', 'sales_invoice_items.sales_invoice_id')
            ->where('sales_invoices.type', SalesInvoice::TYPE_INVOICE)
            ->where('sales_invoices.status', InvoiceStatus::Final->value)
            ->whereNull('sales_invoices.deleted_at')
            ->whereBetween('sales_invoices.issued_at', [$period->from, $period->to])
            ->when($branchId !== null, fn ($q) => $q->where('sales_invoices.branch_id', $branchId))
            ->groupBy(DB::raw('date(sales_invoices.issued_at)'))
            ->orderBy(DB::raw('date(sales_invoices.issued_at)'))
            ->selectRaw('
                date(sales_invoices.issued_at) as day,
                count(distinct sales_invoices.id) as invoices,
                coalesce(sum(sales_invoice_items.line_total - sales_invoice_items.vat_amount), 0) as revenue,
                coalesce(sum(sales_invoice_items.cost_snapshot * sales_invoice_items.quantity), 0) as cost
            ')
            ->get();

        return $this->shape($rows, 'day', 'date');
    }

    /**
     * Sales by product, biggest first — «چی می‌فروشه».
     *
     * @return list<array<string, mixed>>
     */
    public function byProduct(ReportPeriod $period, ?int $branchId = null, int $limit = 50): array
    {
        return $this->grouped($period, $branchId, $limit, 'products.name', 'products.id', [
            'join' => fn ($query) => $query
                ->leftJoin('product_variants', 'product_variants.id', '=', 'sales_invoice_items.product_variant_id')
                ->leftJoin('products', 'products.id', '=', 'product_variants.product_id'),
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
     * @param  array{join: callable}  $options
     * @return list<array<string, mixed>>
     */
    private function grouped(ReportPeriod $period, ?int $branchId, int $limit, string $labelColumn, string $groupColumn, array $options): array
    {
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
            ->orderByDesc(DB::raw('coalesce(sum(sales_invoice_items.line_total - sales_invoice_items.vat_amount), 0)'))
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
}
