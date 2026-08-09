<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Inventory\Models\ProductUnit;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockOverview;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * What the shop is holding, and what is about to run out.
 *
 * Quantity is never read from a column — it is a SUM over `stock_movements` for
 * standard goods and a COUNT of `product_units` for phones (golden rule 3, and see
 * {@see StockOverview} for why those two must not be added together).
 */
final class StockController extends Controller
{
    private const PER_PAGE = 25;

    public function index(Request $request, StockOverview $overview): Response
    {
        $this->authorize('viewAny', ProductUnit::class);

        $warehouseId = $request->integer('warehouse_id') ?: null;

        $variants = $this->filtered($request)
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        $figures = $overview->onHandFor($variants->getCollection(), $warehouseId);
        $holdings = $overview->serializedHoldings($warehouseId);

        $showCost = $request->user()?->can('inventory.view_cost') ?? false;

        return Inertia::render('Inventory::Stock/Index', [
            'rows' => [
                'items' => array_map(
                    fn (ProductVariant $variant): array => $this->rowFor($variant, $figures),
                    $variants->items()
                ),
                'links' => $variants->linkCollection()->toArray(),
                'total' => $variants->total(),
            ],
            'summary' => [
                'units_on_hand' => $holdings['units'],
                // Withheld from staff who may not see cost: a stock valuation is the
                // sum of what the shop paid, which is the same secret as a unit's cost.
                'stock_value' => $showCost ? Money::toArray($holdings['value']) : null,
                // Costs a second pass over the opted-in products. Worth it: "three
                // lines are about to run out" is the one number on this page that
                // prompts an action, and hiding it behind another click means nobody
                // sees it until a customer asks for something that is gone.
                'low_stock_count' => $this->lowStockQuery($request, $overview)['count'],
            ],
            'filters' => [
                'q' => trim($request->string('q')->value()),
                'warehouse_id' => $warehouseId,
            ],
            'warehouses' => $this->warehouseOptions(),
        ]);
    }

    /**
     * Lines at or below the threshold their product carries.
     */
    public function lowStock(Request $request, StockOverview $overview): Response
    {
        $this->authorize('viewAny', ProductUnit::class);

        $warehouseId = $request->integer('warehouse_id') ?: null;

        $result = $this->lowStockQuery($request, $overview);

        return Inertia::render('Inventory::Stock/LowStock', [
            'rows' => $result['rows'],
            'filters' => [
                'q' => trim($request->string('q')->value()),
                'warehouse_id' => $warehouseId,
            ],
            'warehouses' => $this->warehouseOptions(),
        ]);
    }

    /**
     * Every variant whose product sets a threshold, with the ones at or under it.
     *
     * Deliberately NOT paginated and deliberately capped. The threshold lives on the
     * product and the quantity is a SUM, so "below threshold" cannot be expressed as a
     * WHERE clause without materialising a total — which golden rule 3 forbids. Only
     * products that opted in by setting a threshold are scanned, which in a real shop
     * is the handful of accessory lines somebody cared about, not the catalogue.
     *
     * @return array{rows: list<array<string, mixed>>, count: int}
     */
    private function lowStockQuery(Request $request, StockOverview $overview): array
    {
        $warehouseId = $request->integer('warehouse_id') ?: null;

        $variants = ProductVariant::query()
            ->with('product:id,name,type,low_stock_threshold')
            ->where('is_active', true)
            ->whereHas('product', function (Builder $query): void {
                $query->where('is_active', true)->whereNotNull('low_stock_threshold');
            })
            ->orderBy('product_id')
            ->limit(500)
            ->get();

        $figures = $overview->onHandFor($variants, $warehouseId);

        $rows = [];

        foreach ($variants as $variant) {
            $threshold = $variant->product->low_stock_threshold;
            $onHand = $figures[$variant->id] ?? 0;

            if ($threshold === null || $onHand > $threshold) {
                continue;
            }

            $rows[] = [
                ...$this->rowFor($variant, $figures),
                'threshold' => $threshold,
                // Zero is a different conversation from "getting low", and the screen
                // sorts and colours on it rather than making the reader compare numbers.
                'is_out' => $onHand <= 0,
            ];
        }

        // Out of stock first, then closest to the threshold.
        usort($rows, static function (array $a, array $b): int {
            /** @var int $aOnHand */
            $aOnHand = $a['on_hand'];
            /** @var int $bOnHand */
            $bOnHand = $b['on_hand'];

            return $aOnHand <=> $bOnHand;
        });

        return ['rows' => $rows, 'count' => count($rows)];
    }

    /**
     * @param  array<int, int>  $figures
     * @return array<string, mixed>
     */
    private function rowFor(ProductVariant $variant, array $figures): array
    {
        return [
            'variant_id' => $variant->id,
            'product_id' => $variant->product_id,
            'product_name' => $variant->product->name,
            'variant_name' => $variant->displayName(),
            'type' => $variant->product->type->value,
            'barcode' => $variant->barcode,
            'sku' => $variant->sku,
            'on_hand' => $figures[$variant->id] ?? 0,
            'threshold' => $variant->product->low_stock_threshold,
        ];
    }

    /**
     * @return Builder<ProductVariant>
     */
    private function filtered(Request $request): Builder
    {
        $term = trim($request->string('q')->value());

        return ProductVariant::query()
            ->with('product:id,name,type,low_stock_threshold')
            ->where('is_active', true)
            ->whereHas('product', function (Builder $query) use ($request, $term): void {
                $query->where('is_active', true)
                    ->when($term !== '', fn (Builder $q) => $q->where('name', 'ilike', "%{$term}%"))
                    ->when(
                        $request->filled('type'),
                        fn (Builder $q) => $q->where('type', $request->string('type')->value())
                    );
            })
            ->orderBy('product_id')
            ->orderBy('id');
    }

    /**
     * @return list<array{id: int, label: string}>
     */
    private function warehouseOptions(): array
    {
        $options = [];

        foreach (Warehouse::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']) as $warehouse) {
            $options[] = ['id' => $warehouse->id, 'label' => $warehouse->name];
        }

        return $options;
    }
}
