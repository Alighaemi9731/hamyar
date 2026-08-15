<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Services;

use App\Modules\Sales\Enums\InvoiceStatus;
use App\Modules\Sales\Models\SalesInvoice;
use Illuminate\Support\Facades\DB;

/**
 * A report *of* profit, as opposed to profit as a column on the sales report.
 *
 * ## Why it is a separate screen rather than a sort order
 *
 * The sales report answers «چقدر فروختیم» and carries margin beside it. This answers
 * «از چی سود کردیم», and the two disagree about which rows matter: a case sold two
 * hundred times at nine thousand toman of margin outsells nothing and out-earns a phone
 * that moved twice. Sorting the sales report by margin would come close — but its
 * `LIMIT` has already chosen the top fifty **by revenue**, so the low-volume,
 * high-margin lines this report exists to surface were discarded before the sort. The
 * order has to be in the SQL, and that is what {@see SalesReports::byProduct()} now takes.
 *
 * ## Per-IMEI is the cut only this product can offer
 *
 * A phone shop's real question is not «موبایل چقدر سود داشت» but «این گوشی چقدر سود
 * داشت». Because every handset carries its own cost on `product_units` and its own
 * `cost_snapshot` on the line that sold it, the margin on a single serialized device is
 * exact — not an average, not an allocation. Generic retail software cannot answer it and
 * a shopkeeper asks it constantly, usually while deciding what to pay for the next
 * trade-in.
 *
 * ## The whole screen is gated, not its columns
 *
 * The sales report drops cost and margin for a viewer without permission and still has
 * something to show. Here there is nothing left: a profit report without profit is an
 * empty table with a misleading heading. So {@see \App\Modules\Reporting\Support\ReportAccess}
 * decides whether the screen opens at all, and the same predicate hides it from the index.
 */
final class ProfitReports
{
    public function __construct(private readonly SalesReports $sales) {}

    /**
     * Most profitable products first.
     *
     * @param  list<int>|null  $branchIds  the branches to cover; null is every branch
     * @return list<array<string, mixed>>
     */
    public function byProduct(ReportPeriod $period, ?array $branchIds = null, int $limit = 50): array
    {
        return $this->sales->byProduct($period, $branchIds, $limit, order: 'margin');
    }

    /**
     * Most profitable brands first.
     *
     * @param  list<int>|null  $branchIds  the branches to cover; null is every branch
     * @return list<array<string, mixed>>
     */
    public function byBrand(ReportPeriod $period, ?array $branchIds = null, int $limit = 50): array
    {
        return $this->sales->byBrand($period, $branchIds, $limit, order: 'margin');
    }

    /**
     * One row per serialized handset sold in the period, best margin first.
     *
     * ## Revenue and cost come from the invoice line, not from the unit
     *
     * `product_units.cost` is what the device costs the shop **today** — it is updated by
     * a re-grade or a rework. The line's `cost_snapshot` is what it cost on the day it
     * was sold, written once at finalisation and never recomputed (ADR 0009 and the Phase
     * 5 profit engine). Reading the unit instead would restate a past month's profit every
     * time somebody touched a unit record, which is the exact failure `cost_snapshot`
     * exists to prevent.
     *
     * ## The label is the IMEI, and it stays Latin
     *
     * It is dialled into HAMTA and read down a phone line, so it renders LTR and
     * ungrouped wherever it is shown (design-system rule 4). The product name rides
     * along, because an IMEI on its own tells a person nothing.
     *
     * @param  list<int>|null  $branchIds  the branches to cover; null is every branch
     * @return list<array{imei: string, product: string, brand: string, invoice: string, sold_at: string, customer: string, revenue: int, cost: int, margin: int}>
     */
    public function perUnit(ReportPeriod $period, ?array $branchIds = null, int $limit = 200): array
    {
        $revenue = 'sales_invoice_items.line_total - sales_invoice_items.vat_amount';
        $cost = 'sales_invoice_items.cost_snapshot * sales_invoice_items.quantity';

        $rows = DB::table('sales_invoice_items')
            ->join('sales_invoices', 'sales_invoices.id', '=', 'sales_invoice_items.sales_invoice_id')
            ->join('product_units', 'product_units.id', '=', 'sales_invoice_items.product_unit_id')
            ->leftJoin('product_variants', 'product_variants.id', '=', 'product_units.product_variant_id')
            ->leftJoin('products', 'products.id', '=', 'product_variants.product_id')
            ->leftJoin('brands', 'brands.id', '=', 'products.brand_id')
            ->leftJoin('parties', 'parties.id', '=', 'sales_invoices.party_id')
            ->where('sales_invoices.type', SalesInvoice::TYPE_INVOICE)
            ->where('sales_invoices.status', InvoiceStatus::Final->value)
            ->whereNull('sales_invoices.deleted_at')
            ->whereBetween('sales_invoices.issued_at', [$period->from, $period->to])
            ->when($branchIds !== null, fn ($q) => $q->whereIn('sales_invoices.branch_id', $branchIds))
            ->orderByDesc(DB::raw("({$revenue}) - ({$cost})"))
            ->limit($limit)
            ->selectRaw("
                coalesce(nullif(product_units.imei1, ''), product_units.serial, '') as imei,
                coalesce(products.name, '') as product,
                coalesce(nullif(brands.name_fa, ''), brands.name, '') as brand,
                coalesce(sales_invoices.number, '') as invoice,
                sales_invoices.issued_at as sold_at,
                coalesce(parties.name, '') as customer,
                {$revenue} as revenue,
                {$cost} as cost
            ")
            ->get();

        $shaped = [];

        foreach ($rows as $row) {
            $values = (array) $row;

            $lineRevenue = $this->intOf($values['revenue'] ?? 0);
            $lineCost = $this->intOf($values['cost'] ?? 0);

            $shaped[] = [
                'imei' => $this->stringOf($values['imei'] ?? ''),
                'product' => $this->stringOf($values['product'] ?? ''),
                'brand' => $this->stringOf($values['brand'] ?? ''),
                'invoice' => $this->stringOf($values['invoice'] ?? ''),
                'sold_at' => $this->stringOf($values['sold_at'] ?? ''),
                'customer' => $this->stringOf($values['customer'] ?? ''),
                'revenue' => $lineRevenue,
                'cost' => $lineCost,
                'margin' => $lineRevenue - $lineCost,
            ];
        }

        return $shaped;
    }

    /**
     * The period's headline figures — the same ones the sales report shows.
     *
     * Deliberately not recomputed here. Two screens quoting different profit for one
     * month is a support call that starts with "which one is right", and the answer would
     * be neither until somebody reconciled them.
     *
     * @param  list<int>|null  $branchIds  the branches to cover; null is every branch
     * @return array{revenue: int, cost: int, profit: int, margin_percent: float, invoice_count: int, returned_revenue: int}
     */
    public function summary(ReportPeriod $period, ?array $branchIds = null): array
    {
        return $this->sales->summary($period, $branchIds);
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
