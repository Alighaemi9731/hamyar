<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Catalog\Models\Brand;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Brand>
 */
final class BrandFactory extends Factory
{
    protected $model = Brand::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        /** @var array{0: string, 1: string} $brand */
        $brand = fake()->randomElement([
            ['Apple', 'اپل'],
            ['Samsung', 'سامسونگ'],
            ['Xiaomi', 'شیائومی'],
            ['Nokia', 'نوکیا'],
            ['Huawei', 'هواوی'],
        ]);

        return [
            // Unique per tenant, so a suffix keeps repeated factory calls apart.
            'name' => $brand[0].' '.fake()->unique()->numerify('###'),
            'name_fa' => $brand[1],
            'position' => 0,
        ];
    }
}
