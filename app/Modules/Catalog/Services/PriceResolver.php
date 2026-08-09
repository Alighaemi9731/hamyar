<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Services;

use App\Modules\Catalog\Models\PriceLevel;
use App\Modules\Catalog\Models\ProductPrice;
use Carbon\CarbonImmutable;

/**
 * What a given customer pays for a given variant, right now.
 *
 * `product_prices` is append-only, so "the price" is the newest row whose
 * `effective_from` has already passed — not the newest row, which may be a scheduled
 * increase that must not apply yet.
 *
 * Falls back to the shop's default level rather than returning nothing. A همکار price
 * that was never entered means "this line has no reseller rate", and charging a reseller
 * the consumer price is a conversation; refusing to sell them anything is a lost sale.
 */
final class PriceResolver
{
    /** @var array<string, ProductPrice|null> */
    private array $cache = [];

    /**
     * The price in integer rial, or null when the variant has no price at all.
     */
    public function priceFor(int $variantId, ?int $priceLevelId = null, ?CarbonImmutable $at = null): ?int
    {
        return $this->rowFor($variantId, $priceLevelId, $at)?->price;
    }

    /**
     * The winning price row, so a caller can also see when it took effect.
     */
    public function rowFor(int $variantId, ?int $priceLevelId = null, ?CarbonImmutable $at = null): ?ProductPrice
    {
        $at ??= CarbonImmutable::now();
        $priceLevelId ??= $this->defaultLevelId();

        if ($priceLevelId === null) {
            return null;
        }

        $key = "{$variantId}:{$priceLevelId}:{$at->getTimestamp()}";

        if (array_key_exists($key, $this->cache)) {
            return $this->cache[$key];
        }

        $row = $this->newestEffective($variantId, $priceLevelId, $at);

        if (! $row instanceof ProductPrice) {
            $default = $this->defaultLevelId();

            // Only worth a second query when the requested level was not already the
            // default one.
            if ($default !== null && $default !== $priceLevelId) {
                $row = $this->newestEffective($variantId, $default, $at);
            }
        }

        return $this->cache[$key] = $row;
    }

    /**
     * The whole price grid for a page of variants, in one query.
     *
     * `[variantId][levelId] => rial`. Absent keys mean "no price at this level", which
     * the grid shows as an empty cell — distinct from a price of zero, which is a
     * decision someone made.
     *
     * No fallback to the default level here, deliberately: the grid is where prices are
     * *edited*, and showing the consumer price in the همکار column would have an
     * operator "confirm" a reseller rate that was never entered.
     *
     * @param  list<int>  $variantIds
     * @return array<int, array<int, int>>
     */
    public function currentForMany(array $variantIds, ?CarbonImmutable $at = null): array
    {
        if ($variantIds === []) {
            return [];
        }

        $at ??= CarbonImmutable::now();

        $rows = ProductPrice::query()
            ->whereIn('product_variant_id', $variantIds)
            ->where('effective_from', '<=', $at)
            // Newest first per (variant, level); the first row seen for a pair wins,
            // which is the same rule `newestEffective()` applies one row at a time.
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->get();

        $grid = [];

        foreach ($rows as $row) {
            $grid[$row->product_variant_id][$row->price_level_id] ??= $row->price;
        }

        return $grid;
    }

    /**
     * Record a new price. Never updates an existing row — see {@see ProductPrice}.
     */
    public function setPrice(int $variantId, int $priceLevelId, int $rial, ?CarbonImmutable $from = null): ProductPrice
    {
        $this->cache = [];

        /** @var ProductPrice $price */
        $price = ProductPrice::query()->create([
            'product_variant_id' => $variantId,
            'price_level_id' => $priceLevelId,
            'price' => $rial,
            'effective_from' => $from ?? CarbonImmutable::now(),
        ]);

        return $price;
    }

    public function forget(): void
    {
        $this->cache = [];
    }

    private function newestEffective(int $variantId, int $levelId, CarbonImmutable $at): ?ProductPrice
    {
        return ProductPrice::query()
            ->where('product_variant_id', $variantId)
            ->where('price_level_id', $levelId)
            // A scheduled increase sits in the table with a future date and must not
            // apply until it arrives.
            ->where('effective_from', '<=', $at)
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->first();
    }

    private function defaultLevelId(): ?int
    {
        $level = PriceLevel::query()->where('is_default', true)->first()
            ?? PriceLevel::query()->orderBy('position')->first();

        /** @var int|null $id */
        $id = $level?->getKey();

        return $id;
    }
}
