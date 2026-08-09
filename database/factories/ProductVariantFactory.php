<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductVariant>
 */
final class ProductVariantFactory extends Factory
{
    protected $model = ProductVariant::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'options' => ['رنگ' => fake()->randomElement(['مشکی', 'سفید', 'آبی'])],
            // Unique per tenant among live rows, so it must not repeat within a test.
            'barcode' => fake()->unique()->numerify('62#########'),
            'is_active' => true,
        ];
    }

    public function withoutBarcode(): self
    {
        return $this->state(fn (): array => ['barcode' => null]);
    }
}
