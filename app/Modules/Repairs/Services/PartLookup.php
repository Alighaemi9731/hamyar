<?php

declare(strict_types=1);

namespace App\Modules\Repairs\Services;

use App\Modules\Catalog\Enums\ProductType;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockLedger;
use App\Modules\Inventory\Services\StockReservations;

/**
 * Finding a part to fit.
 *
 * ## Why this is not the POS scanner
 *
 * {@see \App\Modules\Sales\Services\PosScanner} answers a very similar question and is
 * deliberately not reused. It resolves handsets by IMEI, applies reseller price levels,
 * and gates cost on the till's cost-visibility permission — three behaviours a bench
 * needs none of, and one it must not have. A technician fitting a screen is asking a
 * stock question, so the dependency points at Inventory rather than at Sales.
 *
 * ## Cost is not in the answer
 *
 * A technician picking a screen does not need to know what the shop paid for it, and
 * whether anybody may see that figure is a permission the till already gates
 * (`inventory.view_cost`). Shipping it here would hand the cost of every part to anybody
 * who can edit a ticket, through a route nobody thinks of as a cost report.
 *
 * The cost that matters is snapshotted server-side when the part is fitted, from the
 * ledger — see {@see TicketParts::consume()}. It never travels through a browser.
 *
 * Serialized units are excluded outright. A phone in `product_units` is a device the shop
 * sells, not a component; fitting one to somebody else's handset would strand its IMEI
 * history halfway through a repair.
 *
 * ## Available, never on-hand
 *
 * The figure a technician sees is net of every other job's holds, for the same reason the
 * till's is: two benches must not both plan around the last screen. `onHand()` answers
 * "what is in the building" and would let exactly that happen.
 */
final class PartLookup
{
    public function __construct(
        private readonly StockReservations $reservations,
        private readonly StockLedger $stock,
    ) {}

    /**
     * Parts matching a typed fragment, with what may actually be claimed.
     *
     * @return list<array{variant_id: int, product_name: string, variant_name: string|null, available: int}>
     */
    public function search(string $term, int $branchId, int $limit = 15): array
    {
        $term = trim($term);

        if ($term === '') {
            return [];
        }

        $warehouseId = $this->warehouseIdFor($branchId);

        if ($warehouseId === null) {
            return [];
        }

        $variants = ProductVariant::query()
            ->with('product:id,name,type')
            // A component, not a handset. This is the outer condition rather than one
            // branch of the name/sku group, so a barcode match cannot smuggle a phone
            // into a parts picker.
            ->whereHas('product', fn ($query) => $query->where('type', ProductType::Standard->value))
            ->where(function ($query) use ($term): void {
                $query
                    ->whereHas('product', fn ($product) => $product->where('name', 'ilike', '%'.$term.'%'))
                    ->orWhere('sku', 'ilike', '%'.$term.'%')
                    ->orWhere('barcode', $term);
            })
            ->limit($limit)
            ->get();

        $reserved = $this->reservations->reservedForMany(
            array_values(array_map(
                fn (ProductVariant $variant): int => (int) $variant->id,
                $variants->all(),
            )),
            $warehouseId,
        );

        return array_values(array_map(function (ProductVariant $variant) use ($warehouseId, $reserved): array {
            $onHand = $this->stock->onHand((int) $variant->id, $warehouseId);

            return [
                'variant_id' => (int) $variant->id,
                'product_name' => $variant->product->name,
                // Null when it would only repeat the product name — `displayName()`
                // falls back to the product for a variant with no distinguishing
                // options, and drawing both reads as "شارژرشارژر" on the picker.
                'variant_name' => $variant->displayName() === $variant->product->name
                    ? null
                    : $variant->displayName(),
                'available' => max(0, $onHand - ($reserved[(int) $variant->id] ?? 0)),
            ];
        }, $variants->all()));
    }

    /**
     * The warehouse a bench draws from.
     *
     * The same one the till sells from, deliberately: a shop with one stockroom must not
     * have the POS and the bench disagreeing about how many screens are left.
     */
    public function warehouseIdFor(int $branchId): ?int
    {
        $id = Warehouse::query()
            ->where('branch_id', $branchId)
            ->where('is_active', true)
            ->where('is_sellable', true)
            ->orderByDesc('is_default')
            ->value('id');

        return is_int($id) ? $id : null;
    }
}
