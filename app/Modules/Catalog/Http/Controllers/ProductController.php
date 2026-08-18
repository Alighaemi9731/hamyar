<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Enums\ProductType;
use App\Modules\Catalog\Http\Requests\ProductRequest;
use App\Modules\Catalog\Http\Requests\VariantMatrixRequest;
use App\Modules\Catalog\Models\Brand;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Catalog\Services\CategoryTree;
use App\Modules\Catalog\Services\VariantMatrix;
use App\Support\Digits;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The catalogue: what the shop sells, and in which configurations.
 *
 * Deliberately says nothing about stock. Catalog answers "this thing exists and costs
 * this much"; where the units are and what happened to them is Inventory's question,
 * and mixing the two here is how a list screen ends up with a number nobody can
 * explain (golden rule 6).
 */
final class ProductController extends Controller
{
    private const PER_PAGE = 25;

    public function index(Request $request, CategoryTree $tree): Response
    {
        $this->authorize('viewAny', Product::class);

        $term = trim($request->string('q')->value());

        $products = Product::query()
            ->with(['brand:id,name,name_fa', 'category:id,name'])
            ->withCount(['variants' => fn ($query) => $query->where('is_active', true)])
            ->when($term !== '', fn ($query) => $query->where(
                fn ($q) => $q->where('name', 'ilike', "%{$term}%")->orWhere('sku', 'ilike', "%{$term}%")
            ))
            ->when($request->filled('category_id'), fn ($query) => $query->where('category_id', $request->integer('category_id')))
            ->when($request->filled('brand_id'), fn ($query) => $query->where('brand_id', $request->integer('brand_id')))
            ->when($request->filled('type'), fn ($query) => $query->where('type', $request->string('type')->value()))
            // Inactive lines are hidden by default and reachable by filter: a retired
            // product still has invoices pointing at it, so it is never gone.
            ->when(! $request->boolean('include_inactive'), fn ($query) => $query->where('is_active', true))
            ->orderBy('name')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return Inertia::render('Catalog::Products/Index', [
            'products' => [
                'rows' => array_map($this->rowFor(...), $products->items()),
                'links' => $products->linkCollection()->toArray(),
                'total' => $products->total(),
            ],
            'filters' => [
                'q' => $term,
                'category_id' => $request->integer('category_id') ?: null,
                'brand_id' => $request->integer('brand_id') ?: null,
                'type' => $request->string('type')->value() ?: null,
                'include_inactive' => $request->boolean('include_inactive'),
            ],
            'categories' => $tree->options(),
            'brands' => $this->brandOptions(),
            'types' => $this->typeOptions(),
        ]);
    }

    public function create(CategoryTree $tree): Response
    {
        $this->authorize('create', Product::class);

        return Inertia::render('Catalog::Products/Edit', [
            'product' => null,
            'variants' => [],
            'axes' => [],
            'categories' => $tree->options(),
            'brands' => $this->brandOptions(),
            'types' => $this->typeOptions(),
        ]);
    }

    public function store(ProductRequest $request): RedirectResponse
    {
        $this->authorize('create', Product::class);

        $product = Product::query()->create($request->validated());

        return redirect()
            ->route('catalog.products.edit', $product)
            ->with('success', 'کالا ثبت شد. حالا ویژگی‌ها و تنوع‌ها را بسازید.');
    }

    public function edit(Request $request, Product $product, CategoryTree $tree): Response
    {
        $this->authorize('view', $product);

        $variants = $product->variants()->orderBy('id')->get();

        return Inertia::render('Catalog::Products/Edit', [
            'product' => [
                'id' => $product->id,
                'name' => $product->name,
                'sku' => $product->sku,
                'type' => $product->type->value,
                'category_id' => $product->category_id,
                'brand_id' => $product->brand_id,
                'description' => $product->description,
                'low_stock_threshold' => $product->low_stock_threshold,
                'is_active' => $product->is_active,
            ],
            'variants' => $variants->map(fn (ProductVariant $variant): array => [
                'id' => $variant->id,
                'name' => $variant->displayName(),
                'options' => $variant->options,
                'sku' => $variant->sku,
                'barcode' => $variant->barcode,
                'is_active' => $variant->is_active,
            ])->values()->all(),
            // The matrix is re-derived from the existing variants rather than stored:
            // the variants ARE the truth, and a stored copy of the axes would drift the
            // first time someone deactivates one combination by hand.
            'axes' => $this->axesFrom($variants),
            'categories' => $tree->options(),
            'brands' => $this->brandOptions(),
            'types' => $this->typeOptions(),

            // Gates the «تاریخچه» link. The audit viewer authorises independently, so
            // this only decides whether a link is drawn — a Warehousekeeper without
            // `activity.view` should not be offered a door that answers 403.
            'can' => [
                'view_activity' => $request->user()?->can('activity.view') ?? false,
            ],
        ]);
    }

    public function update(ProductRequest $request, Product $product): RedirectResponse
    {
        $this->authorize('update', $product);

        $product->update($request->validated());

        return back()->with('success', 'کالا به‌روزرسانی شد.');
    }

    /**
     * Rebuild the variant matrix.
     *
     * Regeneration deactivates combinations that fall outside the new matrix; it never
     * deletes them (see {@see VariantMatrix}). A variant can already carry stock and
     * invoice lines, and removing it would rewrite a closed month.
     */
    public function regenerate(VariantMatrixRequest $request, Product $product, VariantMatrix $matrix): RedirectResponse
    {
        $this->authorize('update', $product);

        $variants = $matrix->generate($product, $request->axes());

        return back()->with('success', Digits::toPersian((string) count($variants)).' تنوع فعال است.');
    }

    public function destroy(Product $product): RedirectResponse
    {
        $this->authorize('delete', $product);

        // Soft delete. Invoices, stock movements and units all point here; the line
        // leaves the catalogue and its history stays readable.
        $product->delete();

        return redirect()->route('catalog.products.index')->with('success', 'کالا حذف شد.');
    }

    /**
     * @return array{id: int, name: string, sku: string|null, type: string, type_label: string, brand: string|null, category: string|null, variant_count: int, is_active: bool}
     */
    private function rowFor(Product $product): array
    {
        $brand = $product->brand;
        /** @var int $variantCount */
        $variantCount = $product->getAttribute('variants_count');

        return [
            'id' => $product->id,
            'name' => $product->name,
            'sku' => $product->sku,
            'type' => $product->type->value,
            'type_label' => $product->type->labelFa(),
            'brand' => $brand === null ? null : ($brand->name_fa ?? $brand->name),
            'category' => $product->category?->name,
            'variant_count' => $variantCount,
            'is_active' => $product->is_active,
        ];
    }

    /**
     * @return list<array{id: int, label: string}>
     */
    private function brandOptions(): array
    {
        $options = [];

        foreach (Brand::query()->orderBy('position')->orderBy('name')->get(['id', 'name', 'name_fa']) as $brand) {
            $options[] = ['id' => $brand->id, 'label' => $brand->name_fa ?? $brand->name];
        }

        return $options;
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function typeOptions(): array
    {
        return array_map(
            static fn (ProductType $type): array => ['value' => $type->value, 'label' => $type->labelFa()],
            ProductType::cases()
        );
    }

    /**
     * Recover the attribute matrix from the variants it produced.
     *
     * @param  \Illuminate\Database\Eloquent\Collection<int, ProductVariant>  $variants
     * @return list<array{name: string, values: list<string>}>
     */
    private function axesFrom($variants): array
    {
        /** @var array<string, list<string>> $axes */
        $axes = [];

        foreach ($variants as $variant) {
            if (! $variant->is_active) {
                continue;
            }

            foreach ($variant->options as $name => $value) {
                $axes[$name][] = $value;
            }
        }

        $result = [];

        foreach ($axes as $name => $values) {
            $result[] = ['name' => $name, 'values' => array_values(array_unique($values))];
        }

        return $result;
    }
}
