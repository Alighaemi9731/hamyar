<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\PriceLevel;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductPrice;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Catalog\Services\PriceResolver;
use App\Modules\Platform\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;

/**
 * `PriceResolver` is a container singleton, and its memo must not outlive a tenant switch.
 *
 * ## Why this file exists at all
 *
 * `bin/check-forgettable-singletons` found 87 `forget()` calls that were no-ops because
 * neither this class nor `SubscriptionResolver` was ever bound as a singleton. Binding them
 * makes those calls mean something — and turns the memo into shared state, which is exactly
 * where a cross-tenant leak comes from.
 *
 * The old key was `variant:level:timestamp`. Variant ids are unique across the whole table,
 * so that reads as sufficient — and it was, while every resolution produced a fresh
 * instance. Shared, it is not: **a leak introduced by a performance optimisation**, which is
 * the worst kind to go looking for, because nothing about the optimisation looks like
 * access control.
 *
 * Three ordinary situations serve two tenants from one container: a queued job, a test's
 * `runFor()`, and the storefront resolving a price-list token mid-request. So the tenant
 * leads the key, and this test is what says so.
 */
beforeEach(function (): void {
    $this->shopA = Tenant::factory()->withDomain()->create();
    $this->shopB = Tenant::factory()->withDomain()->create();

    /** @return array{variant: int, level: int} */
    $this->seed = function (Tenant $tenant, int $price): array {
        return app(TenantContext::class)->runFor($tenant, function () use ($price): array {
            $level = PriceLevel::factory()->create(['code' => PriceLevel::CONSUMER, 'is_default' => true]);
            $product = Product::factory()->create(['is_active' => true]);
            $variant = ProductVariant::factory()->create(['product_id' => $product->getKey(), 'is_active' => true]);

            ProductPrice::query()->create([
                'product_variant_id' => $variant->getKey(),
                'price_level_id' => $level->getKey(),
                'price' => $price,
                'effective_from' => CarbonImmutable::now()->subDay(),
            ]);

            return ['variant' => idOf($variant), 'level' => idOf($level)];
        });
    };
});

/*
| A FIXED instant, passed explicitly, and the tests below are worthless without it.
|
| `PriceResolver`'s cache key ends in `$at->getTimestamp()` — correct, because a price is
| effective-dated and "the price at T" is a different question from "the price at T+1 day".
| The consequence for a test is that two calls a second apart have DIFFERENT keys and never
| share a cache entry. The first version of this file left `$at` to default to `now()`, and
| both the leak test and the memo test passed against a cache that had never been read.
|
| Green without witness, in a file written to catch a leak. Pinning the instant is what
| makes the shared key actually shared.
*/
afterEach(function (): void {
    app(TenantContext::class)->forget();
});

it('is one shared instance, so forget() is not a no-op', function (): void {
    // The property the gate enforces. Two resolutions must be the same object, or every
    // `forget()` in the suite clears a cache nobody reads.
    expect(app(PriceResolver::class))->toBe(app(PriceResolver::class));
});

it('never serves one shop’s cached price to another', function (): void {
    /*
    | The leak the tenant-keyed cache prevents.
    |
    | Shop B asks for shop A's variant id — which a crafted request can do, since ids are
    | sequential and shared across one table. RLS returns nothing, so the honest answer is
    | null. A memo keyed without the tenant would answer with A's price instead, and no
    | query would run to contradict it.
    */
    $a = ($this->seed)($this->shopA, 88_819_990);
    ($this->seed)($this->shopB, 12_340_000);

    $resolver = app(PriceResolver::class);
    $at = CarbonImmutable::parse('2026-08-18 09:00:00');

    /*
    | BOTH ids are shop A's, and passed explicitly — which is the whole test.
    |
    | Letting `$priceLevelId` default makes each shop resolve its OWN default level, so the
    | cache keys differ by level and never collide. The second version of this file did
    | exactly that and passed with the tenant stripped out of the key: green for the third
    | time for the third wrong reason. A crafted request supplies both ids, so the test does.
    */
    $seen = app(TenantContext::class)->runFor(
        $this->shopA,
        fn (): ?int => $resolver->priceFor($a['variant'], $a['level'], $at),
    );

    expect($seen)->toBe(88_819_990);

    // Same instance, same instant, same ids — a tenant-blind cache hits here.
    $leaked = app(TenantContext::class)->runFor(
        $this->shopB,
        fn (): ?int => $resolver->priceFor($a['variant'], $a['level'], $at),
    );

    expect($leaked)->toBeNull();
})->group('isolation');

it('still memoises within one tenant', function (): void {
    /*
    | The positive half. Without it the test above passes on a cache that never hits at
    | all — a "leak-proof" resolver that simply forgot everything would be green here and
    | would have thrown away the reason the singleton exists.
    */
    $a = ($this->seed)($this->shopA, 12_340_000);
    $variant = $a['variant'];
    $level = $a['level'];

    $at = CarbonImmutable::parse('2026-08-18 09:00:00');

    app(TenantContext::class)->runFor($this->shopA, function () use ($variant, $level, $at): void {
        $resolver = app(PriceResolver::class);

        expect($resolver->priceFor($variant, $level, $at))->toBe(12_340_000);

        // Delete the row underneath it. A live query would now return null; the memo
        // answers from what it already read, which is what a memo is.
        ProductPrice::query()->where('product_variant_id', $variant)->delete();

        expect($resolver->priceFor($variant, $level, $at))->toBe(12_340_000);

        // And `forget()` actually clears it — the whole point of the singleton binding.
        $resolver->forget();

        expect($resolver->priceFor($variant, $level, $at))->toBeNull();
    });
});
