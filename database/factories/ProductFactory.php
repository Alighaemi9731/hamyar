<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Catalog\Enums\ProductType;
use App\Modules\Catalog\Models\Brand;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
final class ProductFactory extends Factory
{
    protected $model = Product::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'category_id' => Category::factory(),
            'brand_id' => Brand::factory(),
            'name' => fake()->randomElement(['گلکسی A54', 'آیفون ۱۵ پرو', 'ردمی نوت ۱۳', 'کابل شارژ تایپ‌سی']),
            'type' => ProductType::Standard,
            'is_active' => true,
        ];
    }

    /**
     * A phone: gets `product_units` rows and per-unit cost rather than a quantity.
     */
    public function serialized(): self
    {
        return $this->state(fn (): array => ['type' => ProductType::Serialized]);
    }

    public function lowStockAt(int $threshold): self
    {
        return $this->state(fn (): array => ['low_stock_threshold' => $threshold]);
    }
}
