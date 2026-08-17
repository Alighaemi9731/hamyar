<?php

declare(strict_types=1);

namespace App\Modules\Storefront\Services;

use App\Modules\Catalog\Models\PriceLevel;
use App\Modules\Storefront\Models\StorefrontSetting;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * What a visitor is allowed to see of a shop's catalogue.
 *
 * ## The allow-list is the design
 *
 * The spec's acceptance line is *"the public catalogue leaks no cost, no non-consumer price
 * level and no customer data"*, and the way to keep that true as the catalogue grows is to
 * name the columns that go out rather than the ones that do not. A `select *` here plus a
 * later migration adding `last_cost` to `product_variants` is a leak nobody wrote.
 *
 * So every query below lists its columns, and there is no path from this class to `cost`,
 * `product_units`, `parties` or any price level other than the one asked for.
 *
 * ## Availability is coarse, deliberately
 *
 * «موجود» or «تماس بگیرید», never a count. A quantity on a public page is stale within the
 * hour, invites haggling on the last one, and tells a competitor exactly how deep the shop
 * is on a line. The spec says the same in one word: *coarse*.
 *
 * ## Prices are live, and a line with no price is absent rather than zero
 *
 * A variant nobody has priced at this level is not «۰ تومان» — it is a line the shop has
 * not published, and showing it free is a promise somebody will try to hold them to.
 */
final class PublicCatalogue
{
    /**
     * The consumer-facing catalogue for the public shop page.
     *
     * @return list<array<string, mixed>>
     */
    public function forPublic(StorefrontSetting $settings): array
    {
        $level = PriceLevel::query()->where('is_default', true)->first()
            ?? PriceLevel::query()->where('code', PriceLevel::CONSUMER)->first();

        if (! $level instanceof PriceLevel) {
            return [];
        }

        $categories = $settings->categories;

        return $this->rows(
            idOfModel($level),
            // The JSON column returns whatever was written to it; a row edited in a console
            // does not get to put a string where a category id goes.
            is_array($categories) && $categories !== []
                ? array_values(array_map('intval', array_filter($categories, 'is_numeric')))
                : null,
            $settings->shows_out_of_stock,
        );
    }

    /**
     * The catalogue at one price level, for a reseller link.
     *
     * The level comes from the link's own column — never from the request — which is what
     * makes "the token cannot be manipulated to reveal a different price level" a property
     * of the schema rather than of a controller remembering to check.
     *
     * @param  list<int>|null  $categories
     * @return list<array<string, mixed>>
     */
    public function forLevel(int $priceLevelId, ?array $categories = null): array
    {
        return $this->rows($priceLevelId, $categories, showOutOfStock: true);
    }

    /**
     * @param  list<int>|null  $categories
     * @return list<array<string, mixed>>
     */
    private function rows(int $priceLevelId, ?array $categories, bool $showOutOfStock): array
    {
        /*
        | On-hand as a boolean, computed in SQL, and never exposed as a number.
        |
        | Standard goods are a SUM over movements; handsets are rows in `product_units`
        | (golden rule 3 and 4, and the two must not be added). A shop selling only phones
        | would read as "nothing in stock" if this counted movements alone — the same defect
        | the stock valuation report had.
        */
        $onHand = '(
            coalesce((
                select sum(sm.quantity) from stock_movements sm
                where sm.product_variant_id = product_variants.id
            ), 0) > 0
            or exists (
                select 1 from product_units pu
                where pu.product_variant_id = product_variants.id
                  and pu.status = \'in_stock\'
                  and pu.deleted_at is null
            )
        )';

        $query = DB::table('product_variants')
            ->join('products', 'products.id', '=', 'product_variants.product_id')
            ->leftJoin('brands', 'brands.id', '=', 'products.brand_id')
            ->join('product_prices', function ($join) use ($priceLevelId): void {
                $join->on('product_prices.product_variant_id', '=', 'product_variants.id')
                    ->where('product_prices.price_level_id', '=', $priceLevelId);
            })
            ->where('products.is_active', true)
            ->where('product_variants.is_active', true)
            // A line the shop has not priced at this level is absent, not free.
            ->whereNotNull('product_prices.price')
            ->when(
                $categories !== null && $categories !== [],
                fn ($q) => $q->whereIn('products.category_id', $categories),
            )
            ->when(! $showOutOfStock, fn ($q) => $q->whereRaw($onHand))
            ->orderBy('products.name')
            ->orderBy('product_variants.id')
            // The allow-list. Every column that leaves is named here — see the docblock.
            ->selectRaw("
                products.name as product,
                coalesce(nullif(brands.name_fa, ''), brands.name, '') as brand,
                product_variants.name as variant,
                product_prices.price as price,
                {$onHand} as in_stock
            ")
            ->limit(500);

        $rows = [];

        foreach ($query->get() as $row) {
            $values = (array) $row;
            $price = is_numeric($values['price'] ?? null) ? (int) $values['price'] : 0;

            $rows[] = [
                'product' => $this->stringOf($values['product'] ?? ''),
                'brand' => $this->stringOf($values['brand'] ?? ''),
                'variant' => $this->stringOf($values['variant'] ?? ''),
                'price' => $price,
                'price_formatted' => Money::toArray($price)['formatted'],
                // Coarse, on purpose.
                'in_stock' => (bool) ($values['in_stock'] ?? false),
            ];
        }

        return $rows;
    }

    private function stringOf(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }
}
