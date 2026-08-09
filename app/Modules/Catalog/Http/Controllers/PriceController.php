<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Http\Requests\BulkPriceRequest;
use App\Modules\Catalog\Http\Requests\PriceRequest;
use App\Modules\Catalog\Models\Brand;
use App\Modules\Catalog\Models\PriceLevel;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Catalog\Services\BulkPriceUpdater;
use App\Modules\Catalog\Services\CategoryTree;
use App\Modules\Catalog\Services\PriceResolver;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The price grid: every variant against every price level, editable in place.
 *
 * Iranian prices move weekly, so this is a screen shop owners open constantly — and
 * the bulk tool beside it can move a whole category in one action. That is why the
 * preview matters: `BulkPriceUpdater::apply()` consumes the rows the preview produced
 * rather than re-deriving them, so nobody approves one set of changes and gets another.
 */
final class PriceController extends Controller
{
    private const PER_PAGE = 25;

    public function index(Request $request, PriceResolver $prices, CategoryTree $tree): Response
    {
        $this->authorize('viewAny', Product::class);

        $variants = $this->filtered($request)
            ->with('product:id,name,type')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        /** @var list<int> $variantIds */
        $variantIds = collect($variants->items())->map(fn (ProductVariant $v): int => $v->id)->all();

        $grid = $prices->currentForMany($variantIds);

        $levels = PriceLevel::query()->orderBy('position')->get();

        return Inertia::render('Catalog::Prices/Index', [
            'levels' => $levels->map(fn (PriceLevel $level): array => [
                'id' => $level->id,
                'code' => $level->code,
                'label' => $level->name_fa,
                'is_default' => $level->is_default,
            ])->values()->all(),

            'variants' => [
                'rows' => collect($variants->items())->map(fn (ProductVariant $variant): array => [
                    'id' => $variant->id,
                    'product_name' => $variant->product->name,
                    'variant_name' => $variant->displayName(),
                    'sku' => $variant->sku,
                    'barcode' => $variant->barcode,
                    // Keyed by level id. A missing key is "no price at this level",
                    // which the grid draws as an empty cell — not as zero.
                    'prices' => $grid[$variant->id] ?? [],
                ])->all(),
                'links' => $variants->linkCollection()->toArray(),
                'total' => $variants->total(),
            ],

            'filters' => [
                'q' => trim($request->string('q')->value()),
                'category_id' => $request->integer('category_id') ?: null,
                'brand_id' => $request->integer('brand_id') ?: null,
            ],
            'categories' => $tree->options(),
            'brands' => Brand::query()->orderBy('position')->orderBy('name')->get(['id', 'name', 'name_fa'])
                ->map(fn (Brand $brand): array => ['id' => $brand->id, 'label' => $brand->name_fa ?? $brand->name])
                ->values()->all(),
            'can' => [
                'manage_prices' => $request->user()?->can('catalog.manage_prices') ?? false,
            ],
        ]);
    }

    public function update(PriceRequest $request, ProductVariant $variant, PriceResolver $prices): RedirectResponse
    {
        $this->authorize('managePrices', Product::class);

        $prices->setPrice(
            $variant->id,
            $request->integer('price_level_id'),
            $request->rial(),
            $request->filled('effective_from')
                ? CarbonImmutable::parse($request->string('effective_from')->value())
                : null,
        );

        return back()->with('success', 'قیمت ثبت شد.');
    }

    /**
     * What a bulk change would do, without doing it.
     */
    public function preview(BulkPriceRequest $request, BulkPriceUpdater $updater): JsonResponse
    {
        $this->authorize('managePrices', Product::class);

        $preview = $updater->preview(
            $this->filtered($request),
            $request->integer('price_level_id'),
            $request->string('mode')->value(),
            $request->operand(),
        );

        return response()->json([
            'rows' => array_map(static fn (array $row): array => [
                'variant_id' => $row['variant_id'],
                'name' => $row['name'],
                'from' => $row['from'] === null ? null : Money::toArray($row['from']),
                'to' => Money::toArray($row['to']),
                // Raw rial too: `apply()` must receive back exactly the figures the
                // preview computed, not a re-parse of a formatted string.
                'from_rial' => $row['from'],
                'to_rial' => $row['to'],
            ], $preview['rows']),
            'unchanged' => $preview['unchanged'],
            // A variant with no price at this level has nothing for a percentage to act
            // on. Reported rather than invented, so the gap is visible here instead of
            // at the till.
            'skipped' => $preview['skipped'],
        ]);
    }

    /**
     * Apply exactly the rows the operator approved.
     */
    public function apply(BulkPriceRequest $request, BulkPriceUpdater $updater): RedirectResponse
    {
        $this->authorize('managePrices', Product::class);

        /** @var list<array{variant_id: int, name: string, from: int|null, to: int}> $rows */
        $rows = $request->input('rows', []);

        if ($rows === []) {
            return back()->withErrors(['rows' => 'ردیفی برای اعمال وجود ندارد.']);
        }

        $written = $updater->apply($rows, $request->integer('price_level_id'));

        return back()->with('success', "قیمت {$written} ردیف به‌روزرسانی شد.");
    }

    /**
     * The variant query the grid, the preview and the apply all share.
     *
     * One builder, so "what is on screen" and "what will move" cannot diverge.
     *
     * @return Builder<ProductVariant>
     */
    private function filtered(Request $request): Builder
    {
        $term = trim($request->string('q')->value());

        return ProductVariant::query()
            ->where('is_active', true)
            ->whereHas('product', function (Builder $query) use ($request, $term): void {
                $query->where('is_active', true)
                    ->when($term !== '', fn (Builder $q) => $q->where('name', 'ilike', "%{$term}%"))
                    ->when($request->filled('category_id'), fn (Builder $q) => $q->where('category_id', $request->integer('category_id')))
                    ->when($request->filled('brand_id'), fn (Builder $q) => $q->where('brand_id', $request->integer('brand_id')));
            })
            ->orderBy('product_id')
            ->orderBy('id');
    }
}
