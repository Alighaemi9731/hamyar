<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Catalog\Models\Category;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Category>
 */
final class CategoryFactory extends Factory
{
    protected $model = Category::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->randomElement(['گوشی موبایل', 'لوازم جانبی', 'قطعات', 'تبلت', 'ساعت هوشمند']);

        return [
            'name' => $name,
            // Slugs are unique per tenant and the Persian name is not URL-safe, so the
            // random suffix keeps a factory building several categories from colliding.
            'slug' => Str::slug(Str::random(8)),
            'position' => 0,
        ];
    }
}
