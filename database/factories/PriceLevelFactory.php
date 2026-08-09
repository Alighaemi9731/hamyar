<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Catalog\Models\PriceLevel;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PriceLevel>
 */
final class PriceLevelFactory extends Factory
{
    protected $model = PriceLevel::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => Str::lower(Str::random(8)),
            'name_fa' => 'سطح قیمت',
            'is_default' => false,
            'position' => 0,
        ];
    }

    public function consumer(): self
    {
        return $this->state(fn (): array => [
            'code' => PriceLevel::CONSUMER,
            'name_fa' => 'مصرف‌کننده',
            'is_default' => true,
        ]);
    }

    public function reseller(): self
    {
        return $this->state(fn (): array => [
            'code' => PriceLevel::RESELLER,
            'name_fa' => 'همکار',
            'position' => 1,
        ]);
    }
}
