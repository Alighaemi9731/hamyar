<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Enums\UnitStatus;
use App\Modules\Inventory\Models\ProductUnit;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Serialized-unit lookup for the `<UnitPicker/>` component.
 *
 * The scan box is the primary input: a salesperson points a reader at the box and the
 * right handset has to come back, whether the code scanned was IMEI 1, IMEI 2 or the
 * serial. `ProductUnit::scopeMatchingCode()` already answers all three, and typing a
 * model name has to work too because half the shop's phones are on a shelf, not in a
 * box with a barcode facing outwards.
 */
final class UnitController extends Controller
{
    private const LIMIT = 12;

    public function search(Request $request): JsonResponse
    {
        $this->authorize('viewAny', ProductUnit::class);

        $term = trim($request->string('q')->value());

        $showCost = $request->user()?->can('inventory.view_cost') ?? false;

        $units = ProductUnit::query()
            ->with(['variant.product', 'warehouse'])
            ->when(
                $request->boolean('sellable', true),
                // The default, and the one the POS needs: a reserved or in-repair phone
                // is owned but not sellable, and offering it at the till is how the same
                // handset gets promised to two customers.
                fn ($query) => $query->where('status', UnitStatus::InStock->value),
                fn ($query) => $query->onHand(),
            )
            ->when(
                $request->filled('warehouse_id'),
                fn ($query) => $query->where('warehouse_id', $request->integer('warehouse_id'))
            )
            ->when($term !== '', fn ($query) => $query->where(
                fn ($q) => $q->matchingCode($term)
                    ->orWhereHas('variant.product', fn ($p) => $p->where('name', 'ilike', "%{$term}%"))
            ))
            ->orderByDesc('id')
            ->limit(self::LIMIT)
            ->get();

        return response()->json([
            'results' => $units->map(fn (ProductUnit $unit): array => [
                'id' => $unit->getKey(),
                'imei1' => $unit->imei1,
                'imei2' => $unit->imei2,
                'serial' => $unit->serial,
                'product_name' => $unit->variant->product->name,
                'variant_name' => $unit->variant->displayName(),
                'status' => $unit->status->value,
                'condition_label' => $unit->condition->labelFa(),
                'grade' => $unit->grade,
                'warehouse_name' => $unit->warehouse?->name,
                // Withheld entirely rather than nulled for staff without
                // `inventory.view_cost` — Gate 1's Salesperson boundary.
                'cost' => $showCost ? Money::toArray($unit->cost) : null,
            ])->values()->all(),
        ]);
    }
}
