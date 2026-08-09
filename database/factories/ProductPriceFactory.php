<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Catalog\Models\PriceLevel;
use App\Modules\Catalog\Models\ProductPrice;
use App\Modules\Catalog\Models\ProductVariant;
use App\Support\Money;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductPrice>
 */
final class ProductPriceFactory extends Factory
{
    protected $model = ProductPrice::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_variant_id' => ProductVariant::factory(),
            'price_level_id' => PriceLevel::factory(),
            // Integer rial from a toman figure, the way a shop would enter it.
            'price' => Money::fromToman(fake()->numberBetween(50, 50_000) * 1_000),
            'effective_from' => now()->subDay(),
        ];
    }
}
