<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Services;

use App\Modules\Inventory\Enums\MovementType;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * What is on the shelves, what it is worth, and what has not moved.
 *
 * ## Both halves of the stock, or the report is wrong by most of the shop's money
 *
 * This product keeps stock in **two** places on purpose. Standard goods are a SUM over
 * `stock_movements` (golden rule 3); serialized handsets are rows in `product_units`, and
 * Phase 3.6 deliberately writes **no** movement for them — a phone counted in both the
 * unit register and the quantity ledger is counted twice.
 *
 * The consequence for a valuation is severe and easy to miss: a report that sums movements
 * alone values a mobile-phone shop's phones at **zero**, which is most of what the shop
 * owns. So every figure here is the sum of two queries, and the split is reported as well
 * as the total — «۴۲ دستگاه» and «۳۱۰ قلم کالا» are different kinds of asset and a shop
 * counts them separately when the accountant asks.
 *
 * ## Valuation is at cost, and at a date
 *
 * At cost, never at selling price: stock is an asset at what it took to acquire, and
 * valuing it at retail books a profit nobody has made. Standard goods use the same
 * quantity-weighted average {@see \App\Modules\Inventory\Services\StockLedger} sells them
 * at, computed set-based here rather than per-variant, because a valuation over four
 * hundred lines is exactly where a per-row helper becomes four hundred queries.
 *
 * The `asOf` date exists because "what was this worth on the last day of the year" is the
 * question an accountant actually asks, and a stored total could never answer it. The
 * ledger already carries the capability; this exposes it.
 *
 * ## Dead stock is measured from the last time something LEFT
 *
 * Not from when it arrived. A line restocked last week that has not sold since Farvardin
 * is dead stock with fresh purchase dates all over it, and dating it from the arrival
 * would hide exactly the case a shop most needs to see. A line that has never sold at all
 * is dated from its first movement, and its idle days are counted from there.
 */
final class InventoryReports
{
    /**
     * Every line with stock, valued at cost.
     *
     * @return list<array{label: string, kind: string, quantity: int, unit_cost: int, value: int}>
     */
    public function valuation(?CarbonImmutable $asOf = null, ?int $warehouseId = null): array
    {
        $asOf ??= CarbonImmutable::now();

        return [...$this->standardValuation($asOf, $warehouseId), ...$this->serializedValuation($asOf, $warehouseId)];
    }

    /**
     * Lines holding stock that nothing has left in `$days`.
     *
     * @return list<array{label: string, kind: string, quantity: int, value: int, idle_days: int, last_out: string}>
     */
    public function deadStock(int $days = 90, ?int $warehouseId = null): array
    {
        $cutoff = CarbonImmutable::now()->subDays($days);

        return [...$this->standardDeadStock($cutoff, $warehouseId), ...$this->serializedDeadStock($cutoff, $warehouseId)];
    }

    /**
     * @return list<array{label: string, kind: string, quantity: int, unit_cost: int, value: int}>
     */
    private function standardValuation(CarbonImmutable $asOf, ?int $warehouseId): array
    {
        $rows = DB::table('stock_movements')
            ->join('product_variants', 'product_variants.id', '=', 'stock_movements.product_variant_id')
            ->leftJoin('products', 'products.id', '=', 'product_variants.product_id')
            ->where('stock_movements.occurred_at', '<=', $asOf)
            ->when($warehouseId !== null, fn ($q) => $q->where('stock_movements.warehouse_id', $warehouseId))
            ->groupBy('product_variants.id', 'products.name')
            // A line whose movements net to zero is not "worth nothing", it is not stock.
            // Negative nets are kept: a shop that has oversold something needs to see it.
            ->havingRaw('sum(stock_movements.quantity) <> 0')
            ->orderByDesc(DB::raw('sum(stock_movements.quantity) * coalesce('.$this->averageCostExpression().', 0)'))
            ->selectRaw("
                coalesce(products.name, '') as label,
                sum(stock_movements.quantity) as quantity,
                coalesce(".$this->averageCostExpression().', 0) as unit_cost
            ')
            ->get();

        $shaped = [];

        foreach ($rows as $row) {
            $values = (array) $row;

            $quantity = $this->intOf($values['quantity'] ?? 0);
            $unitCost = $this->intOf($values['unit_cost'] ?? 0);

            $shaped[] = [
                'label' => $this->stringOf($values['label'] ?? '') ?: 'بدون عنوان',
                'kind' => 'standard',
                'quantity' => $quantity,
                'unit_cost' => $unitCost,
                'value' => $quantity * $unitCost,
            ];
        }

        return $shaped;
    }

    /**
     * Handsets in stock, each at its own cost.
     *
     * No averaging: a serialized unit's cost is the cost of that device, which is the
     * whole reason `product_units` carries one. Grouped by product for a readable sheet,
     * with the per-device figure available on the IMEI passport where it belongs.
     *
     * @return list<array{label: string, kind: string, quantity: int, unit_cost: int, value: int}>
     */
    private function serializedValuation(CarbonImmutable $asOf, ?int $warehouseId): array
    {
        $rows = DB::table('product_units')
            ->leftJoin('product_variants', 'product_variants.id', '=', 'product_units.product_variant_id')
            ->leftJoin('products', 'products.id', '=', 'product_variants.product_id')
            ->where('product_units.status', 'in_stock')
            ->whereNull('product_units.deleted_at')
            // `acquired_at` may be null on a unit created before the column meant
            // anything; `created_at` is the backstop so it is still counted.
            ->whereRaw('coalesce(product_units.acquired_at, product_units.created_at) <= ?', [$asOf])
            ->when($warehouseId !== null, fn ($q) => $q->where('product_units.warehouse_id', $warehouseId))
            ->groupBy('products.id', 'products.name')
            ->orderByDesc(DB::raw('sum(product_units.cost)'))
            ->selectRaw("
                coalesce(products.name, '') as label,
                count(*) as quantity,
                coalesce(sum(product_units.cost), 0) as value
            ")
            ->get();

        $shaped = [];

        foreach ($rows as $row) {
            $values = (array) $row;

            $quantity = $this->intOf($values['quantity'] ?? 0);
            $value = $this->intOf($values['value'] ?? 0);

            $shaped[] = [
                'label' => $this->stringOf($values['label'] ?? '') ?: 'بدون عنوان',
                'kind' => 'serialized',
                'quantity' => $quantity,
                /*
                | Shown for comparability with the standard rows; the exact per-device
                | figure lives on the passport, and this is the group's average.
                |
                | Raised to a whole toman, and that is not cosmetic: three handsets whose
                | costs total ten million toman average 33,333,333.33 — a figure `Money`
                | refuses to render, so the whole report answered **500**. The standard
                | rows have ceiled since `averageCostExpression()` was written; this path
                | divided and hoped, which held only while every group happened to divide
                | evenly.
                |
                | Ceiling rather than flooring, in the same direction and for the same
                | reason as the standard expression: the two sit in one table under one
                | heading, and a shop that finds them rounded opposite ways has no way to
                | tell which is wrong. `value` beside it stays the exact sum.
                */
                'unit_cost' => $quantity > 0 ? Money::ceilToToman(intdiv($value, $quantity)) : 0,
                'value' => $value,
            ];
        }

        return $shaped;
    }

    /**
     * @return list<array{label: string, kind: string, quantity: int, value: int, idle_days: int, last_out: string}>
     */
    private function standardDeadStock(CarbonImmutable $cutoff, ?int $warehouseId): array
    {
        $outbound = 'max(case when stock_movements.quantity < 0 then stock_movements.occurred_at end)';

        $rows = DB::table('stock_movements')
            ->join('product_variants', 'product_variants.id', '=', 'stock_movements.product_variant_id')
            ->leftJoin('products', 'products.id', '=', 'product_variants.product_id')
            ->when($warehouseId !== null, fn ($q) => $q->where('stock_movements.warehouse_id', $warehouseId))
            ->groupBy('product_variants.id', 'products.name')
            ->havingRaw('sum(stock_movements.quantity) > 0')
            // Never left, or last left before the cutoff. `coalesce` to the first movement
            // so a line that has never sold is dated from when it arrived rather than
            // treated as missing data and dropped.
            ->havingRaw("coalesce({$outbound}, min(stock_movements.occurred_at)) <= ?", [$cutoff])
            ->orderByRaw("coalesce({$outbound}, min(stock_movements.occurred_at)) asc")
            ->selectRaw("
                coalesce(products.name, '') as label,
                sum(stock_movements.quantity) as quantity,
                coalesce(".$this->averageCostExpression().", 0) as unit_cost,
                coalesce({$outbound}, min(stock_movements.occurred_at)) as last_out
            ")
            ->get();

        $shaped = [];

        foreach ($rows as $row) {
            $values = (array) $row;

            $quantity = $this->intOf($values['quantity'] ?? 0);
            $unitCost = $this->intOf($values['unit_cost'] ?? 0);
            $lastOut = $this->stringOf($values['last_out'] ?? '');

            $shaped[] = [
                'label' => $this->stringOf($values['label'] ?? '') ?: 'بدون عنوان',
                'kind' => 'standard',
                'quantity' => $quantity,
                'value' => $quantity * $unitCost,
                'idle_days' => $this->daysSince($lastOut),
                'last_out' => $lastOut,
            ];
        }

        return $shaped;
    }

    /**
     * Handsets that have been in stock since before the cutoff.
     *
     * One row per device rather than per product, because that is the granularity the
     * decision is made at: a shop discounts *this* phone, not "iPhone 13s in general".
     *
     * @return list<array{label: string, kind: string, quantity: int, value: int, idle_days: int, last_out: string}>
     */
    private function serializedDeadStock(CarbonImmutable $cutoff, ?int $warehouseId): array
    {
        $since = 'coalesce(product_units.acquired_at, product_units.created_at)';

        $rows = DB::table('product_units')
            ->leftJoin('product_variants', 'product_variants.id', '=', 'product_units.product_variant_id')
            ->leftJoin('products', 'products.id', '=', 'product_variants.product_id')
            ->where('product_units.status', 'in_stock')
            ->whereNull('product_units.deleted_at')
            ->whereRaw("{$since} <= ?", [$cutoff])
            ->when($warehouseId !== null, fn ($q) => $q->where('product_units.warehouse_id', $warehouseId))
            ->orderByRaw("{$since} asc")
            ->selectRaw("
                trim(coalesce(products.name, '') || ' ' || coalesce(nullif(product_units.imei1, ''), product_units.serial, '')) as label,
                product_units.cost as value,
                {$since} as last_out
            ")
            ->get();

        $shaped = [];

        foreach ($rows as $row) {
            $values = (array) $row;
            $lastOut = $this->stringOf($values['last_out'] ?? '');

            $shaped[] = [
                'label' => $this->stringOf($values['label'] ?? '') ?: 'بدون عنوان',
                'kind' => 'serialized',
                'quantity' => 1,
                'value' => $this->intOf($values['value'] ?? 0),
                'idle_days' => $this->daysSince($lastOut),
                'last_out' => $lastOut,
            ];
        }

        return $shaped;
    }

    /**
     * Quantity-weighted average cost, as a SQL expression over the grouped movements.
     *
     * The same rule `StockLedger::weightedAverageCost()` applies one variant at a time:
     * inbound movements only, and only the three types that carry a real acquisition
     * cost. A transfer in re-states stock the shop already owned and would double-count
     * it; an outward movement does not change what the remaining stock cost.
     *
     * Integer division, never a float — money is integer rial (golden rule 2), and a
     * fractional unit cost is the one place a float would creep into a valuation.
     *
     * Then raised to a whole toman by the same rule and in the same direction as
     * {@see \App\Modules\Inventory\Services\StockLedger::weightedAverageCost()}, because
     * the two must agree to the rial: the valuation of a line and the cost the till
     * snapshots when it sells that line are the same figure, and a shop that finds them
     * differing has no way to tell which is wrong. `(v + 9) / 10 * 10` in integer
     * arithmetic is ceiling-to-ten without going anywhere near a float.
     */
    private function averageCostExpression(): string
    {
        $inbound = sprintf(
            "stock_movements.quantity > 0 and stock_movements.type in ('%s', '%s', '%s')",
            MovementType::Purchase->value,
            MovementType::Return->value,
            MovementType::Adjustment->value,
        );

        /*
        | The `::bigint` casts are load-bearing, and their absence is silent. Postgres
        | returns **numeric** from `sum()` over a bigint column, so `sum(a) / sum(b)` is
        | exact decimal division, not the truncating integer division this expression is
        | written as — and the ceiling-to-ten that follows then operates on a fraction and
        | produces one. It surfaced as a valuation of 56,666,675 rial: not a whole toman,
        | so `Money` refused it, from a screen the ledger's own PHP path had computed
        | correctly moments earlier.
        */
        $inQuantity = "sum(case when {$inbound} then stock_movements.quantity else 0 end)::bigint";
        $inValue = "sum(case when {$inbound} then stock_movements.quantity * stock_movements.unit_cost else 0 end)::bigint";

        return "case
            when {$inQuantity} > 0
            then (({$inValue} / {$inQuantity}) + 9) / 10 * 10
            else 0
        end";
    }

    private function daysSince(string $timestamp): int
    {
        if ($timestamp === '') {
            return 0;
        }

        return (int) CarbonImmutable::parse($timestamp)->diffInDays(CarbonImmutable::now());
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
