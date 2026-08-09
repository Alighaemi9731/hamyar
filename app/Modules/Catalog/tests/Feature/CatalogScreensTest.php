<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\PriceLevel;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Catalog\Services\PriceResolver;
use App\Modules\Identity\Models\User;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Platform\Services\PlanCatalogueSeeder;
use App\Modules\Platform\Services\SubscriptionResolver;
use App\Modules\Platform\Services\TenantProvisioner;
use App\Support\Money;
use App\Support\Tenancy\TenantContext;

/**
 * The Phase 3.9 catalogue screens: the tree, the product editor with its variant
 * matrix, and the price grid with its bulk tool.
 */
beforeEach(function (): void {
    app(PlanCatalogueSeeder::class)->sync();

    $this->tenant = Tenant::factory()->withDomain()->create();
    $this->url = tenantUrl($this->tenant);

    subscribe($this->tenant, 'pro');
    app(SubscriptionResolver::class)->forget();
    app(PriceResolver::class)->forget();

    app(TenantProvisioner::class)->seedRoles($this->tenant);

    [$this->owner, $this->keeper, $this->seller] = app(TenantContext::class)->runFor($this->tenant, function (): array {
        $owner = User::factory()->create();
        $owner->assignRole('Owner');

        $keeper = User::factory()->create();
        $keeper->assignRole('Warehousekeeper');

        $seller = User::factory()->create();
        $seller->assignRole('Salesperson');

        return [$owner, $keeper, $seller];
    });

    $this->inTenant = fn (Closure $callback): mixed => inTenantContext($this->tenant, $callback);
});

afterEach(fn () => app(TenantContext::class)->forget());

/* ------------------------------------------------------------- categories -- */

it('renders the category tree', function (): void {
    ($this->inTenant)(function (): void {
        $phones = Category::factory()->create(['name' => 'گوشی موبایل']);
        Category::factory()->create(['name' => 'اپل', 'parent_id' => $phones->id]);
    });

    $this->actingAs($this->owner)
        ->get($this->url.'/catalog/categories')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Catalog::Categories/Index')
            ->has('tree', 1)
            ->where('tree.0.name', 'گوشی موبایل')
            ->has('tree.0.children', 1)
            ->where('tree.0.children.0.name', 'اپل')
        );
});

it('slugs a Persian category name without collapsing it to nothing', function (): void {
    // Str::slug transliterates to ASCII by default, which turns every Persian name
    // into an empty string — and then the second category collides with the first.
    $this->actingAs($this->owner)
        ->post($this->url.'/catalog/categories', ['name' => 'گوشی موبایل'])
        ->assertRedirect();

    $this->actingAs($this->owner)
        ->post($this->url.'/catalog/categories', ['name' => 'لوازم جانبی'])
        ->assertRedirect();

    ($this->inTenant)(function (): void {
        /** @var list<string> $slugs */
        $slugs = Category::query()->pluck('slug')->all();

        expect($slugs)->toHaveCount(2);
        expect(array_unique($slugs))->toHaveCount(2);
        expect($slugs[0])->not->toBe('');
    });
});

it('refuses to make a category its own descendant', function (): void {
    [$parent, $child] = ($this->inTenant)(function (): array {
        $parent = Category::factory()->create(['name' => 'گوشی']);
        $child = Category::factory()->create(['name' => 'اپل', 'parent_id' => $parent->id]);

        return [$parent, $child];
    });

    // A cycle does not error in an adjacency list — it silently removes the whole
    // subtree from every tree query, taking its products with it.
    $this->actingAs($this->owner)
        ->put($this->url.'/catalog/categories/'.$parent->id, [
            'name' => 'گوشی',
            'parent_id' => $child->id,
        ])
        ->assertSessionHasErrors('parent_id');

    ($this->inTenant)(fn () => expect($parent->fresh()?->parent_id)->toBeNull());
});

it('refuses to delete a category that still has children', function (): void {
    [$parent] = ($this->inTenant)(function (): array {
        $parent = Category::factory()->create();
        Category::factory()->create(['parent_id' => $parent->id]);

        return [$parent];
    });

    $this->actingAs($this->owner)
        ->delete($this->url.'/catalog/categories/'.$parent->id)
        ->assertSessionHasErrors('category');

    ($this->inTenant)(fn () => expect(Category::query()->count())->toBe(2));
});

/* ---------------------------------------------------------------- product -- */

it('creates a product and lands on its editor', function (): void {
    $this->actingAs($this->keeper)
        ->post($this->url.'/catalog/products', [
            'name' => 'آیفون ۱۵ پرو',
            'type' => 'serialized',
            'is_active' => true,
        ])
        ->assertRedirect();

    ($this->inTenant)(function (): void {
        $product = Product::query()->firstOrFail();

        expect($product->name)->toBe('آیفون ۱۵ پرو');
        expect($product->isSerialized())->toBeTrue();
    });
});

it('hides inactive products until they are asked for', function (): void {
    ($this->inTenant)(function (): void {
        Product::factory()->create(['name' => 'کالای فعال', 'is_active' => true]);
        Product::factory()->create(['name' => 'کالای بازنشسته', 'is_active' => false]);
    });

    $this->actingAs($this->seller)
        ->get($this->url.'/catalog')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('products.rows', 1));

    $this->actingAs($this->seller)
        ->get($this->url.'/catalog?include_inactive=1')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('products.rows', 2));
});

it('builds the variant matrix and deactivates rather than deletes what falls outside it', function (): void {
    $product = ($this->inTenant)(fn () => Product::factory()->serialized()->create());

    $this->actingAs($this->keeper)
        ->put($this->url.'/catalog/products/'.$product->id.'/variants', [
            'axes' => [
                ['name' => 'رنگ', 'values' => ['مشکی', 'سفید']],
                ['name' => 'حافظه', 'values' => ['128', '256']],
            ],
        ])
        ->assertRedirect();

    ($this->inTenant)(fn () => expect(ProductVariant::query()->count())->toBe(4));

    // Drop a colour. The two white variants may already carry stock or an invoice
    // line, so they must survive as inactive rows rather than disappear.
    $this->actingAs($this->keeper)
        ->put($this->url.'/catalog/products/'.$product->id.'/variants', [
            'axes' => [
                ['name' => 'رنگ', 'values' => ['مشکی']],
                ['name' => 'حافظه', 'values' => ['128', '256']],
            ],
        ])
        ->assertRedirect();

    ($this->inTenant)(function (): void {
        expect(ProductVariant::query()->count())->toBe(4);
        expect(ProductVariant::query()->where('is_active', true)->count())->toBe(2);
    });
});

it('refuses a barcode that another live variant already holds', function (): void {
    [$first, $second] = ($this->inTenant)(function (): array {
        $product = Product::factory()->create();

        return [
            ProductVariant::factory()->for($product)->create(['barcode' => '6260000000019']),
            ProductVariant::factory()->for($product)->create(['barcode' => null]),
        ];
    });

    $this->actingAs($this->keeper)
        ->put($this->url.'/catalog/variants/'.$second->id, ['barcode' => '6260000000019'])
        ->assertSessionHasErrors('barcode');

    ($this->inTenant)(function () use ($first, $second): void {
        // The rule mirrors the partial unique index, so this surfaces as a field-level
        // message instead of a database exception — and the original keeps its code.
        expect($second->fresh()?->barcode)->toBeNull();
        expect($first->fresh()?->barcode)->toBe('6260000000019');
    });
});

/* ----------------------------------------------------------------- prices -- */

it('stores a price typed in toman as integer rial', function (): void {
    // Golden rule 2. A shop types 78,000,000 toman; the column must hold 780,000,000.
    [$variant, $level] = ($this->inTenant)(function (): array {
        app(TenantProvisioner::class);

        return [
            ProductVariant::factory()->create(),
            PriceLevel::factory()->create(['is_default' => true]),
        ];
    });

    $this->actingAs($this->owner)
        ->put($this->url.'/catalog/prices/'.$variant->id, [
            'price_level_id' => $level->id,
            'amount' => 78_000_000,
            'unit' => Money::UNIT_TOMAN,
        ])
        ->assertRedirect();

    ($this->inTenant)(function () use ($variant, $level): void {
        $resolver = app(PriceResolver::class);
        $resolver->forget();

        expect($resolver->priceFor($variant->id, $level->id))->toBe(780_000_000)->toBeRial();
    });
});

it('previews a bulk rise and applies exactly the rows it showed', function (): void {
    [$level, $variants] = ($this->inTenant)(function (): array {
        $level = PriceLevel::factory()->create(['is_default' => true]);
        $product = Product::factory()->create(['name' => 'شارژر ۲۰ وات']);

        $variants = [];
        $resolver = app(PriceResolver::class);

        foreach ([1_000_000, 2_000_000] as $index => $rial) {
            $variant = ProductVariant::factory()->for($product)->create();
            $resolver->setPrice($variant->id, $level->id, $rial);
            $variants[$index] = $variant;
        }

        // A third variant with no price at all: a percentage of nothing is nothing,
        // and it must be reported as skipped rather than invented.
        ProductVariant::factory()->for($product)->create();

        return [$level, $variants];
    });

    $preview = $this->actingAs($this->owner)
        ->postJson($this->url.'/catalog/prices/preview', [
            'price_level_id' => $level->id,
            'mode' => 'percent',
            'value' => 10,
            'unit' => Money::UNIT_RIAL,
        ])
        ->assertOk()
        ->json();

    expect($preview['rows'])->toHaveCount(2);
    expect($preview['skipped'])->toBe(1);
    expect($preview['rows'][0]['to_rial'])->toBe(1_100_000);

    $this->actingAs($this->owner)
        ->post($this->url.'/catalog/prices/apply', [
            'price_level_id' => $level->id,
            'mode' => 'percent',
            'value' => 10,
            'unit' => Money::UNIT_RIAL,
            'rows' => array_map(static fn (array $row): array => [
                'variant_id' => $row['variant_id'],
                'name' => $row['name'],
                'from' => $row['from_rial'],
                'to' => $row['to_rial'],
            ], $preview['rows']),
        ])
        ->assertRedirect();

    ($this->inTenant)(function () use ($variants, $level): void {
        $resolver = app(PriceResolver::class);
        $resolver->forget();

        expect($resolver->priceFor($variants[0]->id, $level->id))->toBe(1_100_000);
        expect($resolver->priceFor($variants[1]->id, $level->id))->toBe(2_200_000);
    });
});

it('lets a Salesperson see the price grid but not change a price', function (): void {
    [$variant, $level] = ($this->inTenant)(fn (): array => [
        ProductVariant::factory()->create(),
        PriceLevel::factory()->create(['is_default' => true]),
    ]);

    $this->actingAs($this->seller)
        ->get($this->url.'/catalog/prices')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('can.manage_prices', false));

    $this->actingAs($this->seller)
        ->put($this->url.'/catalog/prices/'.$variant->id, [
            'price_level_id' => $level->id,
            'amount' => 1,
            'unit' => Money::UNIT_RIAL,
        ])
        ->assertForbidden();
});

/* --------------------------------------------------------------- isolation -- */

it('does not expose another shop product to its editor', function (): void {
    $other = Tenant::factory()->withDomain()->create();

    $foreign = app(TenantContext::class)->runFor(
        $other,
        fn () => Product::factory()->create(['name' => 'کالای فروشگاه دیگر'])
    );

    $this->actingAs($this->owner)
        ->get($this->url.'/catalog/products/'.$foreign->id)
        ->assertNotFound();

    $this->actingAs($this->owner)
        ->put($this->url.'/catalog/products/'.$foreign->id, ['name' => 'دزدیده', 'type' => 'standard'])
        ->assertNotFound();
})->group('isolation');

it('does not expose another shop category to the tree', function (): void {
    $other = Tenant::factory()->withDomain()->create();

    $foreign = app(TenantContext::class)->runFor(
        $other,
        fn () => Category::factory()->create(['name' => 'دسته فروشگاه دیگر'])
    );

    $this->actingAs($this->owner)
        ->get($this->url.'/catalog/categories')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('tree', 0));

    $this->actingAs($this->owner)
        ->delete($this->url.'/catalog/categories/'.$foreign->id)
        ->assertNotFound();
})->group('isolation');
