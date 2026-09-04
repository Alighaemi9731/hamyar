<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Models\PriceLevel;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Catalog\Services\BarcodeRenderer;
use App\Modules\Catalog\Services\PriceResolver;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Price and barcode labels.
 *
 * The sheet on screen IS the sheet that prints — the controls around it carry
 * `no-print` and disappear. A separate "print preview" route is where label printing
 * goes wrong: two renderings drift, and the operator finds out after wasting a sheet
 * of adhesive stock.
 */
final class LabelController extends Controller
{
    /** More than fits on one A4 sheet at the smallest label size; past this, print twice. */
    private const SEARCH_LIMIT = 20;

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Product::class);

        // A term handed over by link (the passport's «چاپ برچسب» carries the product
        // name). Echoed as a string; the page runs it through the same scoped search.
        $term = $request->query('q');
        $term = is_string($term) ? mb_substr(trim($term), 0, 120) : '';

        return Inertia::render('Catalog::Labels/Index', [
            'initial_term' => $term === '' ? null : $term,
            'levels' => PriceLevel::query()
                ->orderBy('position')
                ->get(['id', 'code', 'name_fa', 'is_default'])
                ->map(fn (PriceLevel $level): array => [
                    'id' => $level->id,
                    'label' => $level->name_fa,
                    'is_default' => $level->is_default,
                ])
                ->values()
                ->all(),
        ]);
    }

    /**
     * Variants matching a term, already carrying everything a label needs.
     *
     * The price and the barcode SVG travel with the row rather than being fetched per
     * label: a shop printing forty labels would otherwise make forty more requests, and
     * the sheet would assemble itself line by line in front of them.
     */
    public function search(Request $request, PriceResolver $prices, BarcodeRenderer $barcodes): JsonResponse
    {
        $this->authorize('viewAny', Product::class);

        $term = trim($request->string('q')->value());
        $levelId = $request->integer('price_level_id') ?: null;

        $variants = ProductVariant::query()
            ->with('product:id,name')
            ->where('is_active', true)
            ->when($term !== '', fn ($query) => $query->where(
                fn ($q) => $q->where('barcode', 'ilike', "%{$term}%")
                    ->orWhere('sku', 'ilike', "%{$term}%")
                    ->orWhereHas('product', fn ($p) => $p->where('name', 'ilike', "%{$term}%"))
            ))
            ->orderBy('product_id')
            ->limit(self::SEARCH_LIMIT)
            ->get();

        return response()->json([
            'results' => $variants->map(function (ProductVariant $variant) use ($prices, $barcodes, $levelId): array {
                $price = $prices->priceFor($variant->id, $levelId);

                return [
                    'id' => $variant->id,
                    'product_name' => $variant->product->name,
                    'variant_name' => $variant->displayName(),
                    'barcode' => $variant->barcode,
                    'sku' => $variant->sku,
                    // Null when the variant has no price at any level. The label still
                    // prints — a barcode with no price is a stock label, which is a
                    // thing shops want — but it says so rather than printing «۰».
                    'price' => $price === null ? null : Money::toArray($price),
                    'barcode_svg' => $barcodes->svg($variant->barcode ?? $variant->sku),
                ];
            })->values()->all(),
        ]);
    }
}
