<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\PriceLevel;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Catalog\Services\BarcodeRenderer;
use App\Modules\Catalog\Services\PriceResolver;
use App\Modules\Identity\Models\User;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Platform\Services\PlanCatalogueSeeder;
use App\Modules\Platform\Services\SubscriptionResolver;
use App\Modules\Platform\Services\TenantProvisioner;
use App\Support\Tenancy\TenantContext;

/**
 * Price and barcode labels.
 *
 * The barcode is rendered on the server from the same string the database holds, so
 * the tests that matter are about the SVG being embeddable and about a code the
 * symbology cannot express failing softly — a sheet of forty labels must not 500
 * because one line has a bad code.
 */
beforeEach(function (): void {
    app(PlanCatalogueSeeder::class)->sync();

    $this->tenant = Tenant::factory()->withDomain()->create();
    $this->url = tenantUrl($this->tenant);

    subscribe($this->tenant, 'pro');
    app(SubscriptionResolver::class)->forget();
    app(PriceResolver::class)->forget();

    app(TenantProvisioner::class)->seedRoles($this->tenant);

    $this->keeper = app(TenantContext::class)->runFor($this->tenant, function (): User {
        $user = User::factory()->create();
        $user->assignRole('Warehousekeeper');

        return $user;
    });
});

afterEach(fn () => app(TenantContext::class)->forget());

/* ---------------------------------------------------------------- barcode -- */

it('renders an SVG fragment that can be embedded in a page', function (): void {
    $svg = app(BarcodeRenderer::class)->svg('6260000000019');

    expect($svg)->toBeString();
    // An XML prolog or a doctype in the middle of an HTML document ends the fragment
    // and leaves the rest of the label as text on the page.
    expect($svg)->not->toContain('<?xml');
    expect($svg)->not->toContain('<!DOCTYPE');
    expect($svg)->toStartWith('<svg width="100%" height="100%"');
});

it('returns nothing rather than throwing when there is no code', function (): void {
    expect(app(BarcodeRenderer::class)->svg(null))->toBeNull();
    expect(app(BarcodeRenderer::class)->svg('   '))->toBeNull();
});

it('encodes a code with letters, which EAN-13 would refuse', function (): void {
    // Iranian shops mix retail EANs, their own sequential numbers and supplier codes.
    // Code 128 takes all of them; a label that cannot be printed is the worse failure.
    expect(app(BarcodeRenderer::class)->svg('ACC-2024-BLK'))->toBeString();
});

/* ----------------------------------------------------------------- screen -- */

it('opens the label sheet with the shop price levels', function (): void {
    app(TenantContext::class)->runFor(
        $this->tenant,
        fn () => PriceLevel::factory()->create(['name_fa' => 'مصرف‌کننده', 'is_default' => true])
    );

    $this->actingAs($this->keeper)
        ->get($this->url.'/catalog/labels')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Catalog::Labels/Index')
            ->has('levels', 1)
            ->where('levels.0.label', 'مصرف‌کننده')
        );
});

it('sends everything one label needs in a single row', function (): void {
    [$level] = app(TenantContext::class)->runFor($this->tenant, function (): array {
        $level = PriceLevel::factory()->create(['is_default' => true]);
        $product = Product::factory()->create(['name' => 'قاب محافظ شفاف']);
        $variant = ProductVariant::factory()->for($product)->create(['barcode' => '6260000000019']);

        app(PriceResolver::class)->setPrice($variant->id, $level->id, 4_500_000);

        return [$level];
    });

    // Price and barcode travel WITH the row: printing forty labels must not become
    // forty more requests assembling the sheet line by line in front of the operator.
    $this->actingAs($this->keeper)
        ->getJson($this->url.'/catalog/labels/search?q=قاب&price_level_id='.$level->id)
        ->assertOk()
        ->assertJsonCount(1, 'results')
        ->assertJsonPath('results.0.product_name', 'قاب محافظ شفاف')
        ->assertJsonPath('results.0.barcode', '6260000000019')
        ->assertJsonPath('results.0.price.value', 4_500_000);
});

it('still offers a variant that has no price, marked as having none', function (): void {
    // A barcode with no price is a stock label, which shops do want. It prints, and it
    // says so rather than printing «۰».
    app(TenantContext::class)->runFor($this->tenant, function (): void {
        PriceLevel::factory()->create(['is_default' => true]);
        $product = Product::factory()->create(['name' => 'کابل بدون قیمت']);
        ProductVariant::factory()->for($product)->create(['barcode' => '6260000000026']);
    });

    $this->actingAs($this->keeper)
        ->getJson($this->url.'/catalog/labels/search?q=کابل')
        ->assertOk()
        ->assertJsonPath('results.0.price', null)
        // The barcode is still there: no price does not mean no label.
        ->assertJsonPath(
            'results.0.barcode_svg',
            fn (mixed $svg): bool => is_string($svg) && str_starts_with($svg, '<svg')
        );
});

it('finds a variant by scanning its barcode', function (): void {
    app(TenantContext::class)->runFor($this->tenant, function (): void {
        $product = Product::factory()->create(['name' => 'قاب محافظ']);
        ProductVariant::factory()->for($product)->create(['barcode' => '6260000000019']);
        ProductVariant::factory()->for(Product::factory()->create())->create(['barcode' => '1111111111116']);
    });

    $this->actingAs($this->keeper)
        ->getJson($this->url.'/catalog/labels/search?q=6260000000019')
        ->assertOk()
        ->assertJsonCount(1, 'results')
        ->assertJsonPath('results.0.barcode', '6260000000019');
});

/* -------------------------------------------------------------- isolation -- */

it('never offers another shop products for labelling', function (): void {
    $other = Tenant::factory()->withDomain()->create();

    app(TenantContext::class)->runFor($other, function (): void {
        $product = Product::factory()->create(['name' => 'کالای فروشگاه دیگر']);
        ProductVariant::factory()->for($product)->create(['barcode' => '9990000000019']);
    });

    $this->actingAs($this->keeper)
        ->getJson($this->url.'/catalog/labels/search?q=9990000000019')
        ->assertOk()
        ->assertJsonCount(0, 'results');
})->group('isolation');
