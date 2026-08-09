<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Enums\MovementType;
use App\Modules\Inventory\Enums\UnitStatus;
use App\Modules\Inventory\Models\ProductUnit;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockLedger;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Platform\Services\PlanCatalogueSeeder;
use App\Modules\Platform\Services\SubscriptionResolver;
use App\Modules\Platform\Services\TenantProvisioner;
use App\Support\Tenancy\TenantContext;

/**
 * The stock screens.
 *
 * The one that would be silently wrong: quantity comes from two different ledgers.
 * Accessories are a SUM over `stock_movements`; phones are a COUNT of `product_units`,
 * because receiving a handset deliberately writes no movement. A page that read only
 * movements would show zero phones, and one that read both would show every phone
 * twice.
 */
beforeEach(function (): void {
    app(PlanCatalogueSeeder::class)->sync();

    $this->tenant = Tenant::factory()->withDomain()->create();
    $this->url = tenantUrl($this->tenant);

    subscribe($this->tenant, 'pro');
    app(SubscriptionResolver::class)->forget();

    app(TenantProvisioner::class)->seedRoles($this->tenant);

    [$this->keeper, $this->seller] = app(TenantContext::class)->runFor($this->tenant, function (): array {
        $keeper = User::factory()->create();
        $keeper->assignRole('Warehousekeeper');

        $seller = User::factory()->create();
        $seller->assignRole('Salesperson');

        return [$keeper, $seller];
    });
});

afterEach(fn () => app(TenantContext::class)->forget());

/* ------------------------------------------------------------- quantities -- */

it('counts a standard product from its movements', function (): void {
    app(TenantContext::class)->runFor($this->tenant, function (): void {
        $warehouse = Warehouse::factory()->create();
        $product = Product::factory()->create(['name' => 'شارژر ۲۰ وات', 'type' => 'standard']);
        $variant = ProductVariant::factory()->for($product)->create();

        $ledger = app(StockLedger::class);
        $ledger->record($variant->id, $warehouse->id, 12, MovementType::Purchase);
        $ledger->record($variant->id, $warehouse->id, -3, MovementType::Sale);
    });

    $this->actingAs($this->keeper)
        ->get($this->url.'/inventory')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Inventory::Stock/Index')
            ->where('rows.items.0.on_hand', 9)
        );
});

it('counts a serialized product from its devices, not its movements', function (): void {
    // Receiving a phone writes no stock movement at all. Reading movements here would
    // report zero handsets while three sit on the shelf.
    app(TenantContext::class)->runFor($this->tenant, function (): void {
        $product = Product::factory()->serialized()->create(['name' => 'آیفون ۱۵ پرو']);
        $variant = ProductVariant::factory()->for($product)->create();

        ProductUnit::factory()->count(3)->for($variant, 'variant')->create();
        // Sold is not on hand; reserved is (owned, just not sellable).
        ProductUnit::factory()->for($variant, 'variant')->create(['status' => UnitStatus::Sold]);
        ProductUnit::factory()->for($variant, 'variant')->create(['status' => UnitStatus::Reserved]);
    });

    $this->actingAs($this->keeper)
        ->get($this->url.'/inventory')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('rows.items.0.on_hand', 4));
});

it('reports the serialized holding and its value', function (): void {
    app(TenantContext::class)->runFor($this->tenant, function (): void {
        $product = Product::factory()->serialized()->create();
        $variant = ProductVariant::factory()->for($product)->create();

        ProductUnit::factory()->for($variant, 'variant')->create(['cost' => 500_000_000]);
        ProductUnit::factory()->for($variant, 'variant')->create(['cost' => 300_000_000]);
        ProductUnit::factory()->for($variant, 'variant')->create([
            'cost' => 900_000_000,
            'status' => UnitStatus::Sold,
        ]);
    });

    $this->actingAs($this->keeper)
        ->get($this->url.'/inventory')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('summary.units_on_hand', 2)
            ->where('summary.stock_value.value', 800_000_000)
        );
});

it('withholds the stock valuation from staff who may not see cost', function (): void {
    // A valuation is the sum of what the shop paid — the same secret as a unit's cost.
    app(TenantContext::class)->runFor($this->tenant, function (): void {
        $variant = ProductVariant::factory()->for(Product::factory()->serialized())->create();
        ProductUnit::factory()->for($variant, 'variant')->create(['cost' => 500_000_000]);
    });

    $this->actingAs($this->seller)
        ->get($this->url.'/inventory')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('summary.stock_value', null));
});

it('narrows the figures to one warehouse', function (): void {
    [$here] = app(TenantContext::class)->runFor($this->tenant, function (): array {
        $here = Warehouse::factory()->create(['name' => 'انبار مرکزی']);
        $there = Warehouse::factory()->create(['name' => 'انبار شعبه']);

        $variant = ProductVariant::factory()->for(Product::factory()->serialized())->create();

        ProductUnit::factory()->count(2)->for($variant, 'variant')->create(['warehouse_id' => $here->id]);
        ProductUnit::factory()->for($variant, 'variant')->create(['warehouse_id' => $there->id]);

        return [$here];
    });

    $this->actingAs($this->keeper)
        ->get($this->url.'/inventory?warehouse_id='.$here->id)
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('summary.units_on_hand', 2)
            ->where('rows.items.0.on_hand', 2)
        );
});

/* -------------------------------------------------------------- low stock -- */

it('lists only products whose owner set a threshold', function (): void {
    app(TenantContext::class)->runFor($this->tenant, function (): void {
        $warehouse = Warehouse::factory()->create();
        $ledger = app(StockLedger::class);

        // Opted in, and below the line.
        $watched = Product::factory()->create(['name' => 'قاب محافظ', 'low_stock_threshold' => 5]);
        $watchedVariant = ProductVariant::factory()->for($watched)->create();
        $ledger->record($watchedVariant->id, $warehouse->id, 2, MovementType::Purchase);

        // No threshold. Two of these on the shelf is normal and nobody wants a warning.
        $ignored = Product::factory()->create(['name' => 'آیفون ۱۵ پرو']);
        $ignoredVariant = ProductVariant::factory()->for($ignored)->create();
        $ledger->record($ignoredVariant->id, $warehouse->id, 2, MovementType::Purchase);
    });

    $this->actingAs($this->keeper)
        ->get($this->url.'/inventory/low-stock')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Inventory::Stock/LowStock')
            ->has('rows', 1)
            ->where('rows.0.product_name', 'قاب محافظ')
            ->where('rows.0.on_hand', 2)
            ->where('rows.0.threshold', 5)
            ->where('rows.0.is_out', false)
        );
});

it('separates out-of-stock from merely low, and puts it first', function (): void {
    app(TenantContext::class)->runFor($this->tenant, function (): void {
        $warehouse = Warehouse::factory()->create();
        $ledger = app(StockLedger::class);

        $low = Product::factory()->create(['name' => 'رو به اتمام', 'low_stock_threshold' => 5]);
        $lowVariant = ProductVariant::factory()->for($low)->create();
        $ledger->record($lowVariant->id, $warehouse->id, 3, MovementType::Purchase);

        // Never received: zero on hand, and a different conversation entirely.
        $out = Product::factory()->create(['name' => 'تمام شده', 'low_stock_threshold' => 5]);
        ProductVariant::factory()->for($out)->create();
    });

    $this->actingAs($this->keeper)
        ->get($this->url.'/inventory/low-stock')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('rows', 2)
            ->where('rows.0.product_name', 'تمام شده')
            ->where('rows.0.is_out', true)
            ->where('rows.1.product_name', 'رو به اتمام')
            ->where('rows.1.is_out', false)
        );
});

it('surfaces the low-stock count on the stock page', function (): void {
    app(TenantContext::class)->runFor($this->tenant, function (): void {
        $product = Product::factory()->create(['low_stock_threshold' => 5]);
        ProductVariant::factory()->for($product)->create();
    });

    $this->actingAs($this->keeper)
        ->get($this->url.'/inventory')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('summary.low_stock_count', 1));
});

/* -------------------------------------------------------------- isolation -- */

it('never counts another shop stock', function (): void {
    $other = Tenant::factory()->withDomain()->create();

    app(TenantContext::class)->runFor($other, function (): void {
        $variant = ProductVariant::factory()->for(Product::factory()->serialized())->create();
        ProductUnit::factory()->count(5)->for($variant, 'variant')->create(['cost' => 100_000_000]);
    });

    $this->actingAs($this->keeper)
        ->get($this->url.'/inventory')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('rows.items', 0)
            ->where('summary.units_on_hand', 0)
            ->where('summary.stock_value.value', 0)
        );
})->group('isolation');

it('refuses the stock screens to a user without inventory.view', function (): void {
    $stranger = app(TenantContext::class)->runFor(
        $this->tenant,
        fn () => User::factory()->create()
    );

    $this->actingAs($stranger)->get($this->url.'/inventory')->assertForbidden();
    $this->actingAs($stranger)->get($this->url.'/inventory/low-stock')->assertForbidden();
});
