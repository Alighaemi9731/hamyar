<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Enums\UnitStatus;
use App\Modules\Inventory\Models\ProductUnit;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Platform\Services\PlanCatalogueSeeder;
use App\Modules\Platform\Services\SubscriptionResolver;
use App\Modules\Platform\Services\TenantProvisioner;
use App\Support\Tenancy\TenantContext;
use Database\Factories\ProductUnitFactory;

/**
 * The lookup behind `<UnitPicker/>` — the scan box.
 *
 * The two failures that would matter in a shop: offering a phone that is already
 * promised to someone else, and showing a Salesperson what the shop paid for it.
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

/**
 * One handset of a named model, with a known IMEI.
 */
function makeUnit(Tenant $tenant, string $productName, string $imei, UnitStatus $status = UnitStatus::InStock): ProductUnit
{
    return app(TenantContext::class)->runFor($tenant, function () use ($productName, $imei, $status): ProductUnit {
        $product = Product::factory()->serialized()->create(['name' => $productName]);
        $variant = ProductVariant::factory()->for($product)->create();

        return ProductUnit::factory()->for($variant, 'variant')->create([
            'imei1' => $imei,
            'status' => $status,
        ]);
    });
}

/* ------------------------------------------------------------------ search -- */

it('finds a handset by its IMEI', function (): void {
    $imei = ProductUnitFactory::validImei();
    makeUnit($this->tenant, 'آیفون ۱۵ پرو', $imei);

    $this->actingAs($this->keeper)
        ->getJson($this->url.'/inventory/units/search?q='.$imei)
        ->assertOk()
        ->assertJsonCount(1, 'results')
        ->assertJsonPath('results.0.imei1', $imei);
});

it('finds a handset when the IMEI is typed with Persian digits', function (): void {
    // The column holds normalised digits. A scan and a Persian-keyboard type-in have
    // to reach the same device or the box feels broken to half the staff.
    $imei = ProductUnitFactory::validImei();
    makeUnit($this->tenant, 'آیفون ۱۵ پرو', $imei);

    $persian = strtr($imei, ['0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴',
        '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹']);

    $this->actingAs($this->keeper)
        ->getJson($this->url.'/inventory/units/search?q='.$persian)
        ->assertOk()
        ->assertJsonCount(1, 'results')
        ->assertJsonPath('results.0.imei1', $imei);
});

it('finds a handset by product name', function (): void {
    makeUnit($this->tenant, 'گلکسی S24 اولترا', ProductUnitFactory::validImei());
    makeUnit($this->tenant, 'ردمی نوت ۱۳', ProductUnitFactory::validImei());

    $this->actingAs($this->keeper)
        ->getJson($this->url.'/inventory/units/search?q=گلکسی')
        ->assertOk()
        ->assertJsonCount(1, 'results')
        ->assertJsonPath('results.0.product_name', 'گلکسی S24 اولترا');
});

it('offers only sellable handsets by default', function (): void {
    // A reserved device is owned but promised. Offering it at the till is how the same
    // phone gets sold twice, and the second customer finds out by phone call.
    makeUnit($this->tenant, 'آیفون ۱۵ پرو', ProductUnitFactory::validImei(), UnitStatus::Reserved);

    $this->actingAs($this->keeper)
        ->getJson($this->url.'/inventory/units/search?q=آیفون')
        ->assertOk()
        ->assertJsonCount(0, 'results');
});

it('includes every owned handset when sellable is turned off', function (): void {
    makeUnit($this->tenant, 'آیفون ۱۵ پرو', ProductUnitFactory::validImei(), UnitStatus::Reserved);

    $this->actingAs($this->keeper)
        ->getJson($this->url.'/inventory/units/search?q=آیفون&sellable=0')
        ->assertOk()
        ->assertJsonCount(1, 'results')
        ->assertJsonPath('results.0.status', 'reserved');
});

it('leaves sold handsets out even when sellable is turned off', function (): void {
    makeUnit($this->tenant, 'آیفون ۱۵ پرو', ProductUnitFactory::validImei(), UnitStatus::Sold);

    $this->actingAs($this->keeper)
        ->getJson($this->url.'/inventory/units/search?q=آیفون&sellable=0')
        ->assertOk()
        ->assertJsonCount(0, 'results');
});

/* -------------------------------------------------------------------- cost -- */

it('shows the purchase cost to a Warehousekeeper', function (): void {
    $unit = makeUnit($this->tenant, 'آیفون ۱۵ پرو', ProductUnitFactory::validImei());

    $this->actingAs($this->keeper)
        ->getJson($this->url.'/inventory/units/search?q=آیفون')
        ->assertOk()
        ->assertJsonPath('results.0.cost.value', $unit->cost);
});

it('withholds the purchase cost from a Salesperson', function (): void {
    // Gate 1's boundary: a Salesperson sees the device, never the margin.
    makeUnit($this->tenant, 'آیفون ۱۵ پرو', ProductUnitFactory::validImei());

    $this->actingAs($this->seller)
        ->getJson($this->url.'/inventory/units/search?q=آیفون')
        ->assertOk()
        ->assertJsonPath('results.0.cost', null);
});

/* ----------------------------------------------------------- authorization -- */

it('refuses the lookup to a user without inventory.view', function (): void {
    $stranger = app(TenantContext::class)->runFor(
        $this->tenant,
        fn () => User::factory()->create()
    );

    $this->actingAs($stranger)
        ->getJson($this->url.'/inventory/units/search')
        ->assertForbidden();
});

/* --------------------------------------------------------------- isolation -- */

it('never returns another shop handsets, even by exact IMEI', function (): void {
    $other = Tenant::factory()->withDomain()->create();

    $imei = ProductUnitFactory::validImei();
    makeUnit($other, 'آیفون فروشگاه دیگر', $imei);

    // The strongest form of the question: the searcher already knows the exact IMEI.
    $this->actingAs($this->keeper)
        ->getJson($this->url.'/inventory/units/search?q='.$imei)
        ->assertOk()
        ->assertJsonCount(0, 'results');
})->group('isolation');
