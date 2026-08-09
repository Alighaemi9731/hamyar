<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\PriceLevel;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Catalog\Services\BulkPriceUpdater;
use App\Modules\Catalog\Services\PriceResolver;
use App\Modules\Catalog\Services\VariantMatrix;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Platform\Services\PlanCatalogueSeeder;
use App\Modules\Platform\Services\TenantProvisioner;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Acceptance criteria from docs/specs/catalog.md, in order.
 */
function variantId(ProductVariant $variant): int
{
    /** @var int $id */
    $id = $variant->getKey();

    return $id;
}

function levelId(PriceLevel $level): int
{
    /** @var int $id */
    $id = $level->getKey();

    return $id;
}

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->withDomain()->create();
    $this->prices = app(PriceResolver::class);
    $this->prices->forget();
});

afterEach(fn () => app(TenantContext::class)->forget());

/* -------------------------------------------------------- variant matrix -- */

it('produces the right variant count with no duplicates', function (): void {
    app(TenantContext::class)->runFor($this->tenant, function (): void {
        $product = Product::factory()->serialized()->create();

        // 3 colours × 2 storage sizes = 6, and typing six rows by hand is how a shop
        // ends up with five.
        $variants = app(VariantMatrix::class)->generate($product, [
            'رنگ' => ['مشکی', 'سفید', 'آبی'],
            'حافظه' => ['128', '256'],
        ]);

        expect($variants)->toHaveCount(6);
        expect($product->variants()->count())->toBe(6);

        $fingerprints = array_map(
            fn (ProductVariant $v): string => app(VariantMatrix::class)->fingerprint($v->options),
            $variants
        );

        expect(array_unique($fingerprints))->toHaveCount(6);
    });
});

it('treats the same combination in a different key order as one variant', function (): void {
    $matrix = app(VariantMatrix::class);

    // {colour, storage} and {storage, colour} describe the same physical thing.
    expect($matrix->fingerprint(['رنگ' => 'مشکی', 'حافظه' => '256']))
        ->toBe($matrix->fingerprint(['حافظه' => '256', 'رنگ' => 'مشکی']));
});

it('deactivates rather than deletes a variant dropped from the matrix', function (): void {
    app(TenantContext::class)->runFor($this->tenant, function (): void {
        $product = Product::factory()->create();
        $matrix = app(VariantMatrix::class);

        $matrix->generate($product, ['رنگ' => ['مشکی', 'قرمز']]);

        // The red one is discontinued. Deleting it would orphan last month's invoice
        // lines and silently change a closed month's numbers.
        $matrix->generate($product, ['رنگ' => ['مشکی']]);

        expect($product->variants()->count())->toBe(2);
        expect($product->variants()->where('is_active', true)->count())->toBe(1);
    });
});

it('keeps barcode and price history when the matrix is re-run', function (): void {
    app(TenantContext::class)->runFor($this->tenant, function (): void {
        $product = Product::factory()->create();
        $matrix = app(VariantMatrix::class);

        $first = $matrix->generate($product, ['رنگ' => ['مشکی']])[0];
        $first->update(['barcode' => '6210000000001']);

        // Adding a colour must not disturb the variant that already exists.
        $again = $matrix->generate($product, ['رنگ' => ['مشکی', 'سفید']]);

        expect($again)->toHaveCount(2);
        expect($first->fresh()?->barcode)->toBe('6210000000001');
        expect($product->variants()->count())->toBe(2);
    });
});

it('generates nothing from an empty matrix', function (): void {
    app(TenantContext::class)->runFor($this->tenant, function (): void {
        $product = Product::factory()->create();

        expect(app(VariantMatrix::class)->generate($product, []))->toBe([]);
        expect(app(VariantMatrix::class)->generate($product, ['رنگ' => []]))->toBe([]);
    });
});

/* -------------------------------------------------------------- barcodes -- */

it('enforces barcode uniqueness per tenant', function (): void {
    app(TenantContext::class)->runFor($this->tenant, function (): void {
        ProductVariant::factory()->create(['barcode' => '6210000000009']);

        expect(fn () => DB::transaction(
            fn () => ProductVariant::factory()->create(['barcode' => '6210000000009'])
        ))->toThrow(QueryException::class);
    });
});

it('lets two tenants use the same barcode', function (): void {
    pest()->group('isolation');

    $other = Tenant::factory()->withDomain()->create();

    // Barcodes are the manufacturer's; two unrelated shops stocking the same charger
    // must not collide.
    app(TenantContext::class)->runFor($this->tenant, fn () => ProductVariant::factory()->create(['barcode' => '6211111111111']));
    app(TenantContext::class)->runFor($other, fn () => ProductVariant::factory()->create(['barcode' => '6211111111111']));

    app(TenantContext::class)->runFor($other, fn () => expect(ProductVariant::query()->count())->toBe(1));
});

it('allows many variants with no barcode at all', function (): void {
    app(TenantContext::class)->runFor($this->tenant, function (): void {
        // A plain unique index would have collided on the NULLs; the index is partial.
        ProductVariant::factory()->withoutBarcode()->count(3)->create();

        expect(ProductVariant::query()->whereNull('barcode')->count())->toBe(3);
    });
});

it('frees a barcode once its variant is soft-deleted', function (): void {
    app(TenantContext::class)->runFor($this->tenant, function (): void {
        $old = ProductVariant::factory()->create(['barcode' => '6212222222222']);
        $old->delete();

        // A retired line must not hold its barcode hostage.
        $new = ProductVariant::factory()->create(['barcode' => '6212222222222']);

        expect($new->exists)->toBeTrue();
    });
});

/* ------------------------------------------------------ price resolution -- */

it('gives a new shop the three price levels', function (): void {
    app(PlanCatalogueSeeder::class)->sync();

    $tenant = app(TenantProvisioner::class)->provision([
        'name' => 'موبایل نمونه',
        'subdomain' => 'sample-mobile',
        'owner_name' => 'سارا',
        'owner_mobile' => '09124445566',
        'owner_email' => null,
        'password' => 'secret-secret-1',
    ]);

    app(TenantContext::class)->runFor($tenant, function (): void {
        // Reseller pricing is an everyday concept in this market, not an upgrade.
        expect(PriceLevel::query()->pluck('code')->all())
            ->toBe([PriceLevel::CONSUMER, PriceLevel::RESELLER, PriceLevel::VIP]);

        expect(PriceLevel::query()->where('is_default', true)->value('code'))
            ->toBe(PriceLevel::CONSUMER);
    });
});

it('resolves the price for the requested level', function (): void {
    app(TenantContext::class)->runFor($this->tenant, function (): void {
        $consumer = PriceLevel::factory()->consumer()->create();
        $reseller = PriceLevel::factory()->reseller()->create();
        $variant = ProductVariant::factory()->create();

        $this->prices->setPrice(variantId($variant), levelId($consumer), 50_000_000);
        $this->prices->setPrice(variantId($variant), levelId($reseller), 47_000_000);

        expect($this->prices->priceFor(variantId($variant), levelId($reseller)))->toBe(47_000_000);
        expect($this->prices->priceFor(variantId($variant), levelId($consumer)))->toBe(50_000_000);
    });
});

it('falls back to the default level when a level has no price', function (): void {
    app(TenantContext::class)->runFor($this->tenant, function (): void {
        $consumer = PriceLevel::factory()->consumer()->create();
        $reseller = PriceLevel::factory()->reseller()->create();
        $variant = ProductVariant::factory()->create();

        $this->prices->setPrice(variantId($variant), levelId($consumer), 50_000_000);

        // Charging a reseller the consumer price is a conversation; refusing to sell
        // them anything is a lost sale.
        expect($this->prices->priceFor(variantId($variant), levelId($reseller)))->toBe(50_000_000);
    });
});

it('ignores a price scheduled for the future', function (): void {
    app(TenantContext::class)->runFor($this->tenant, function (): void {
        $level = PriceLevel::factory()->consumer()->create();
        $variant = ProductVariant::factory()->create();

        $this->prices->setPrice(variantId($variant), levelId($level), 50_000_000, now()->subDay()->toImmutable());
        $this->prices->setPrice(variantId($variant), levelId($level), 60_000_000, now()->addWeek()->toImmutable());

        $this->prices->forget();

        // The increase exists in the table but has not arrived.
        expect($this->prices->priceFor(variantId($variant), levelId($level)))->toBe(50_000_000);
    });
});

it('keeps old prices so a past month can be re-derived', function (): void {
    app(TenantContext::class)->runFor($this->tenant, function (): void {
        $level = PriceLevel::factory()->consumer()->create();
        $variant = ProductVariant::factory()->create();

        $this->prices->setPrice(variantId($variant), levelId($level), 40_000_000, now()->subMonth()->toImmutable());
        $this->prices->setPrice(variantId($variant), levelId($level), 50_000_000, now()->subDay()->toImmutable());

        $this->prices->forget();

        // Append-only: a profit report for last month must use last month's price.
        expect($this->prices->priceFor(variantId($variant), levelId($level), now()->subWeeks(2)->toImmutable()))
            ->toBe(40_000_000);
    });
});

/* ------------------------------------------------------------ bulk update -- */

it('applies exactly what the preview showed', function (): void {
    app(TenantContext::class)->runFor($this->tenant, function (): void {
        $level = PriceLevel::factory()->consumer()->create();
        $updater = app(BulkPriceUpdater::class);

        $variants = ProductVariant::factory()->count(3)->create();

        foreach ($variants as $variant) {
            $this->prices->setPrice(variantId($variant), levelId($level), 10_000_000);
        }

        $this->prices->forget();

        $preview = $updater->preview(ProductVariant::query(), levelId($level), BulkPriceUpdater::MODE_PERCENT, 10);

        expect($preview['rows'])->toHaveCount(3);
        expect($preview['rows'][0]['to'])->toBe(11_000_000);

        $written = $updater->apply($preview['rows'], levelId($level));
        $this->prices->forget();

        expect($written)->toBe(3);

        // The guarantee that makes this screen safe to use weekly.
        foreach ($preview['rows'] as $row) {
            expect($this->prices->priceFor($row['variant_id'], levelId($level)))->toBe($row['to']);
        }
    });
});

it('skips variants that have no price rather than inventing one', function (): void {
    app(TenantContext::class)->runFor($this->tenant, function (): void {
        $level = PriceLevel::factory()->consumer()->create();
        ProductVariant::factory()->count(2)->create();

        $preview = app(BulkPriceUpdater::class)
            ->preview(ProductVariant::query(), levelId($level), BulkPriceUpdater::MODE_PERCENT, 10);

        // Reported, so the operator sees the gap now rather than at the till.
        expect($preview['rows'])->toBe([]);
        expect($preview['skipped'])->toBe(2);
    });
});

it('never produces a negative price', function (): void {
    app(TenantContext::class)->runFor($this->tenant, function (): void {
        $level = PriceLevel::factory()->consumer()->create();
        $variant = ProductVariant::factory()->create();

        $this->prices->setPrice(variantId($variant), levelId($level), 1_000_000);
        $this->prices->forget();

        $preview = app(BulkPriceUpdater::class)
            ->preview(ProductVariant::query(), levelId($level), BulkPriceUpdater::MODE_AMOUNT, -5_000_000);

        // A negative total reads downstream as the shop owing the customer.
        expect($preview['rows'][0]['to'])->toBe(0);
    });
});

it('counts unchanged rows separately from changed ones', function (): void {
    app(TenantContext::class)->runFor($this->tenant, function (): void {
        $level = PriceLevel::factory()->consumer()->create();
        $variant = ProductVariant::factory()->create();

        $this->prices->setPrice(variantId($variant), levelId($level), 1_000_000);
        $this->prices->forget();

        // 0% is a no-op, and the preview should say so rather than writing a duplicate
        // price row with the same value.
        $preview = app(BulkPriceUpdater::class)
            ->preview(ProductVariant::query(), levelId($level), BulkPriceUpdater::MODE_PERCENT, 0);

        expect($preview['rows'])->toBe([]);
        expect($preview['unchanged'])->toBe(1);
    });
});

it('rejects an unknown bulk mode', function (): void {
    app(TenantContext::class)->runFor($this->tenant, function (): void {
        $level = PriceLevel::factory()->consumer()->create();

        expect(fn () => app(BulkPriceUpdater::class)
            ->preview(ProductVariant::query(), levelId($level), 'divide', 2))
            ->toThrow(InvalidArgumentException::class);
    });
});

/* ------------------------------------------------------------- isolation -- */

it('does not leak catalog data across tenants', function (): void {
    pest()->group('isolation');

    $other = Tenant::factory()->withDomain()->create();

    app(TenantContext::class)->runFor($this->tenant, fn () => Product::factory()->count(3)->create());
    app(TenantContext::class)->runFor($other, fn () => Product::factory()->create());

    app(TenantContext::class)->runFor($other, fn () => expect(Product::query()->count())->toBe(1));
});

it('does not leak prices across tenants', function (): void {
    pest()->group('isolation');

    $other = Tenant::factory()->withDomain()->create();

    app(TenantContext::class)->runFor($this->tenant, function (): void {
        $level = PriceLevel::factory()->consumer()->create();
        $variant = ProductVariant::factory()->create();
        $this->prices->setPrice(variantId($variant), levelId($level), 99_000_000);
    });

    app(TenantContext::class)->runFor($other, function (): void {
        $this->prices->forget();

        // Prices are commercially sensitive: a competitor's reseller rate is exactly the
        // thing a shop would pay to see.
        expect(App\Modules\Catalog\Models\ProductPrice::query()->count())->toBe(0);
    });
});
