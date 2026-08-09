<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Services;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use Illuminate\Database\ConnectionInterface;

/**
 * Turns an attribute matrix into variants.
 *
 * Three colours × two storage sizes is six variants, and typing six rows by hand is how
 * a shop ends up with five. The matrix is the input a person actually has.
 *
 * ## Regeneration never deletes
 *
 * The rule that matters here: re-running the matrix **deactivates** variants that fall
 * outside it, it does not remove them. A variant may already have stock, a sold unit, or
 * a line on last month's invoice; deleting it would orphan those and silently change a
 * closed month's numbers. Deactivating keeps the history readable and takes it off the
 * till, which is what "we stopped selling the red one" actually means.
 */
final class VariantMatrix
{
    public function __construct(private readonly ConnectionInterface $connection) {}

    /**
     * Create or reactivate one variant per combination, and deactivate the rest.
     *
     * @param  array<string, list<string>>  $axes  e.g. ['رنگ' => ['مشکی','سفید'], 'حافظه' => ['128','256']]
     * @return list<ProductVariant> every variant now active on the product
     */
    public function generate(Product $product, array $axes): array
    {
        $combinations = $this->combine($axes);

        /** @var list<ProductVariant> $variants */
        $variants = $this->connection->transaction(function () use ($product, $combinations): array {
            /** @var array<string, ProductVariant> $existing */
            $existing = $product->variants()
                ->withTrashed()
                ->get()
                ->keyBy(fn (ProductVariant $variant): string => $this->fingerprint($variant->options))
                ->all();

            $kept = [];

            foreach ($combinations as $options) {
                $key = $this->fingerprint($options);
                $variant = $existing[$key] ?? null;

                if ($variant instanceof ProductVariant) {
                    // Already known — a shop re-running the matrix after adding a colour
                    // must not lose the barcode and price history of the others.
                    if ($variant->trashed()) {
                        $variant->restore();
                    }

                    $variant->update(['is_active' => true]);
                } else {
                    $variant = ProductVariant::query()->create([
                        'product_id' => $product->getKey(),
                        'options' => $options,
                        'is_active' => true,
                    ]);
                }

                $kept[$key] = $variant;
            }

            foreach ($existing as $key => $variant) {
                if (! array_key_exists($key, $kept) && $variant->is_active) {
                    $variant->update(['is_active' => false]);
                }
            }

            return array_values($kept);
        });

        return $variants;
    }

    /**
     * The cartesian product of the axes, in a stable order.
     *
     * Order matters for the fingerprint below: `{colour, storage}` and
     * `{storage, colour}` describe the same variant and must not produce two rows.
     *
     * @param  array<string, list<string>>  $axes
     * @return list<array<string, string>>
     */
    public function combine(array $axes): array
    {
        $axes = array_filter($axes, static fn (array $values): bool => $values !== []);

        if ($axes === []) {
            return [];
        }

        ksort($axes);

        /** @var list<array<string, string>> $result */
        $result = [[]];

        foreach ($axes as $axis => $values) {
            $next = [];

            foreach ($result as $partial) {
                foreach (array_unique($values) as $value) {
                    $next[] = [...$partial, $axis => $value];
                }
            }

            $result = $next;
        }

        return $result;
    }

    /**
     * A stable identity for a combination, independent of key order.
     *
     * @param  array<string, string>  $options
     */
    public function fingerprint(array $options): string
    {
        ksort($options);

        return (string) json_encode($options, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
