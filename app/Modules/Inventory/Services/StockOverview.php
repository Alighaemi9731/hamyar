<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use App\Modules\Catalog\Enums\ProductType;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Inventory\Models\ProductUnit;

/**
 * How much of each thing the shop has, from the right ledger.
 *
 * There are **two** answers to "how many", and the whole reason this class exists is
 * that they must never be added together:
 *
 * - A **standard** product is a quantity: `SUM(stock_movements.quantity)`.
 * - A **serialized** product is a set of rows: `COUNT(product_units)` in an on-hand
 *   status.
 *
 * Receiving a phone creates a `product_unit` and deliberately writes **no** stock
 * movement, and a serialized transfer moves the unit and writes none either (3.6). A
 * screen that summed movements for everything would report zero phones; one that added
 * both sources would report every phone twice. Picking the source from
 * `products.type` is the only correct reading.
 *
 * Everything here is batched. A stock page is the screen most likely to be long, and
 * the figures come from two aggregate queries regardless of how many rows are on it.
 */
final class StockOverview
{
    public function __construct(private readonly StockLedger $ledger) {}

    /**
     * On-hand figures for a page of variants, keyed by variant id.
     *
     * @param  \Illuminate\Support\Collection<int, ProductVariant>|array<int, ProductVariant>  $variants
     *                                                                                                    each with its `product` relation loaded
     * @return array<int, int>
     */
    public function onHandFor(iterable $variants, ?int $warehouseId = null): array
    {
        $standardIds = [];
        $serializedIds = [];

        foreach ($variants as $variant) {
            if ($variant->product->type === ProductType::Serialized) {
                $serializedIds[] = $variant->id;
            } else {
                $standardIds[] = $variant->id;
            }
        }

        $figures = $this->ledger->onHandForMany($standardIds, $warehouseId);

        foreach ($this->serializedCounts($serializedIds, $warehouseId) as $variantId => $count) {
            $figures[$variantId] = $count;
        }

        return $figures;
    }

    /**
     * Handsets physically owned right now, and what they cost.
     *
     * `onHand()` includes reserved and in-repair devices: the shop owns them, they are
     * part of a valuation, and neither can be sold. Excluding them would understate the
     * stock a shop is insuring.
     *
     * @return array{units: int, value: int}
     */
    public function serializedHoldings(?int $warehouseId = null): array
    {
        $row = ProductUnit::query()
            ->onHand()
            ->when($warehouseId !== null, fn ($query) => $query->where('warehouse_id', $warehouseId))
            ->selectRaw('count(*) as units, coalesce(sum(cost), 0) as value')
            ->first();

        if ($row === null) {
            return ['units' => 0, 'value' => 0];
        }

        /** @var int|numeric-string $units */
        $units = $row->getAttribute('units');
        /** @var int|numeric-string $value */
        $value = $row->getAttribute('value');

        return ['units' => (int) $units, 'value' => (int) $value];
    }

    /**
     * On-hand counts for serialized variants, keyed by variant id.
     *
     * @param  list<int>  $variantIds
     * @return array<int, int>
     */
    private function serializedCounts(array $variantIds, ?int $warehouseId): array
    {
        if ($variantIds === []) {
            return [];
        }

        $rows = ProductUnit::query()
            ->selectRaw('product_variant_id, count(*) as total')
            ->whereIn('product_variant_id', $variantIds)
            ->onHand()
            ->when($warehouseId !== null, fn ($query) => $query->where('warehouse_id', $warehouseId))
            ->groupBy('product_variant_id')
            ->get();

        $counts = [];

        foreach ($rows as $row) {
            /** @var int|numeric-string $variantId */
            $variantId = $row->getAttribute('product_variant_id');
            /** @var int|numeric-string $total */
            $total = $row->getAttribute('total');

            $counts[(int) $variantId] = (int) $total;
        }

        // A serialized variant with no units left is absent from the group-by, and a
        // missing key reads as "unknown" downstream when it means zero.
        foreach ($variantIds as $id) {
            $counts[$id] ??= 0;
        }

        return $counts;
    }
}
