<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services;

use App\Modules\Sales\Enums\InvoiceStatus;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesInvoiceItem;
use App\Modules\Sales\Models\SalesReturnItem;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\JoinClause;

/**
 * What the shop actually made.
 *
 * ## The whole engine is one stored column
 *
 * `sales_invoice_items.cost_snapshot`, written once at finalisation and never
 * recomputed. Everything here is arithmetic over it.
 *
 * That is not laziness, it is the only correct design under Iranian inflation. A handset
 * bought in Ordibehesht for 62,000,000 rial and one bought in Mordad for 79,000,000 are
 * the same model; a profit report for Ordibehesht that quotes the Mordad cost is not a
 * report with a small error in it, it is a fabrication. Serialized lines snapshot the
 * device's own cost (specific identity, including its share of the freight that brought
 * it in); standard lines snapshot the weighted average at the moment of sale.
 *
 * ## Revenue here is net of VAT, deliberately
 *
 * VAT collected is not the shop's money — it is the state's, held briefly. Counting it
 * as revenue would inflate every margin by the tax rate and make a shop think it is
 * doing ten percent better than it is. So the revenue figure is `line_total - vat`,
 * which is what the customer paid **for the goods**.
 *
 * ## Rounding and shipping sit outside the margin
 *
 * The grand-total rounding adjustment is a few hundred rial of commercial courtesy, and
 * shipping is a pass-through cost. Neither belongs in cost of goods, and folding them
 * into per-line margin would make identical sales show different margins depending on
 * where the total happened to land. They are reported separately.
 *
 * ## Voided invoices contribute nothing; returns subtract
 *
 * A void says the sale never happened, so it is excluded outright. A return did happen
 * and then partly un-happened, so its refunded lines are subtracted from both revenue
 * and cost — leaving the margin on what the customer kept.
 *
 * ## The tenant is carried across every join, by hand
 *
 * `BelongsToTenant`'s global scope only knows about the model the query starts from, so a
 * joined table arrives with no `tenant_id` predicate of its own. RLS still holds — nothing
 * leaks — but `tenant_id = current_setting('app.tenant_id')` is not an expression any index
 * can be *entered* on, so Postgres abandons `(tenant_id, status, issued_at)` and walks the
 * whole date range across every shop on the platform before joining. At fifty tenants that
 * is fifty times the rows the report needed.
 *
 * So every join here equates `tenant_id` on both sides and every joined table gets the
 * constant as well. That is not a bypass of the scope, it is the scope extended to the
 * tables the scope cannot see — and it is the difference between an `Index Cond` and a
 * `Filter` on the busiest query in the product.
 */
final class ProfitEngine
{
    public function __construct(private readonly TenantContext $tenants) {}

    /**
     * The margin on one invoice, line by line.
     *
     * @return array{revenue: int, cost: int, profit: int, margin_percent: int, lines: list<array{id: int, description: string, revenue: int, cost: int, profit: int}>}
     */
    public function forInvoice(SalesInvoice $invoice): array
    {
        $invoice->loadMissing('items');

        $lines = [];
        $revenue = 0;
        $cost = 0;

        foreach ($invoice->items as $item) {
            $lineRevenue = $this->lineRevenue($item);
            $lineCost = $item->cost_snapshot * $item->quantity;

            $revenue += $lineRevenue;
            $cost += $lineCost;

            $lines[] = [
                'id' => $item->id,
                'description' => $item->description,
                'revenue' => $lineRevenue,
                'cost' => $lineCost,
                'profit' => $lineRevenue - $lineCost,
            ];
        }

        return [
            'revenue' => $revenue,
            'cost' => $cost,
            'profit' => $revenue - $cost,
            'margin_percent' => $this->marginPercent($revenue, $revenue - $cost),
            'lines' => $lines,
        ];
    }

    /**
     * The margin over a period, across every branch or one of them.
     *
     * @param  list<int>|null  $branchIds  the branches to cover; null is every branch
     * @return array{revenue: int, cost: int, profit: int, margin_percent: int, invoice_count: int, returned_revenue: int, returned_cost: int}
     */
    public function forPeriod(CarbonImmutable $from, CarbonImmutable $to, ?array $branchIds = null): array
    {
        $tenantId = $this->tenants->id();

        $sold = SalesInvoiceItem::query()
            ->join('sales_invoices', function (JoinClause $join): void {
                $join->on('sales_invoices.id', '=', 'sales_invoice_items.sales_invoice_id')
                    ->on('sales_invoices.tenant_id', '=', 'sales_invoice_items.tenant_id');
            })
            ->when($tenantId !== null, fn ($query) => $query->where('sales_invoices.tenant_id', $tenantId))
            ->where('sales_invoices.type', SalesInvoice::TYPE_INVOICE)
            // Void excluded, not netted: it did not happen.
            ->where('sales_invoices.status', InvoiceStatus::Final->value)
            ->whereNull('sales_invoices.deleted_at')
            ->whereBetween('sales_invoices.issued_at', [$from, $to])
            ->when($branchIds !== null, fn ($query) => $query->whereIn('sales_invoices.branch_id', $branchIds))
            ->selectRaw('
                coalesce(sum(sales_invoice_items.line_total - sales_invoice_items.vat_amount), 0) as revenue,
                coalesce(sum(sales_invoice_items.cost_snapshot * sales_invoice_items.quantity), 0) as cost,
                count(distinct sales_invoices.id) as invoice_count
            ')
            ->first();

        $returned = $this->returnedInPeriod($from, $to, $branchIds);

        $revenue = (int) ($sold->revenue ?? 0) - $returned['revenue'];
        $cost = (int) ($sold->cost ?? 0) - $returned['cost'];

        return [
            'revenue' => $revenue,
            'cost' => $cost,
            'profit' => $revenue - $cost,
            'margin_percent' => $this->marginPercent($revenue, $revenue - $cost),
            'invoice_count' => (int) ($sold->invoice_count ?? 0),
            'returned_revenue' => $returned['revenue'],
            'returned_cost' => $returned['cost'],
        ];
    }

    /**
     * What came back in this period, valued at what it went out at.
     *
     * The refund is what the customer got back; the cost is the *original* line's cost
     * for the quantity returned, because that is what walked back through the door. A
     * return valued at today's cost would move a past month's margin.
     *
     * @param  list<int>|null  $branchIds  the branches to cover; null is every branch
     * @return array{revenue: int, cost: int}
     */
    private function returnedInPeriod(CarbonImmutable $from, CarbonImmutable $to, ?array $branchIds): array
    {
        // Through the model, not `DB::table`: the query builder would skip
        // `BelongsToTenant`'s global scope and lean on RLS alone. RLS would in fact hold
        // — it is the backstop, not the only lock — but golden rule 1 says the scope is
        // never bypassed, and a reader should not have to know which layer saved this.
        $tenantId = $this->tenants->id();

        $row = SalesReturnItem::query()
            ->join('sales_returns', function (JoinClause $join): void {
                $join->on('sales_returns.id', '=', 'sales_return_items.sales_return_id')
                    ->on('sales_returns.tenant_id', '=', 'sales_return_items.tenant_id');
            })
            ->join('sales_invoice_items', function (JoinClause $join): void {
                $join->on('sales_invoice_items.id', '=', 'sales_return_items.sales_invoice_item_id')
                    ->on('sales_invoice_items.tenant_id', '=', 'sales_return_items.tenant_id');
            })
            ->join('sales_invoices', function (JoinClause $join): void {
                $join->on('sales_invoices.id', '=', 'sales_returns.sales_invoice_id')
                    ->on('sales_invoices.tenant_id', '=', 'sales_returns.tenant_id');
            })
            ->when($tenantId !== null, fn ($query) => $query
                ->where('sales_returns.tenant_id', $tenantId)
                ->where('sales_invoice_items.tenant_id', $tenantId)
                ->where('sales_invoices.tenant_id', $tenantId))
            ->whereBetween('sales_returns.returned_at', [$from, $to])
            ->when($branchIds !== null, fn ($query) => $query->whereIn('sales_invoices.branch_id', $branchIds))
            ->selectRaw('
                coalesce(sum(sales_return_items.refund_amount), 0) as revenue,
                coalesce(sum(sales_invoice_items.cost_snapshot * sales_return_items.quantity), 0) as cost
            ')
            ->first();

        return [
            'revenue' => (int) ($row->revenue ?? 0),
            'cost' => (int) ($row->cost ?? 0),
        ];
    }

    /**
     * One line's contribution to revenue: what was paid for the goods, without the tax.
     */
    private function lineRevenue(SalesInvoiceItem $item): int
    {
        return $item->line_total - $item->vat_amount;
    }

    /**
     * Margin as a whole percent of revenue.
     *
     * Integer because this is a headline figure, and a margin quoted to two decimal
     * places invites somebody to reconcile it against a number that was floored three
     * steps earlier. Zero revenue gives zero rather than a division error — a day with
     * no sales has no margin, it does not have an infinite one.
     */
    private function marginPercent(int $revenue, int $profit): int
    {
        return $revenue === 0 ? 0 : intdiv($profit * 100, $revenue);
    }
}
