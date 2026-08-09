<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Inventory\Models\StockCount;
use App\Modules\Inventory\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<StockCount>
 */
final class StockCountFactory extends Factory
{
    protected $model = StockCount::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'warehouse_id' => Warehouse::factory(),
            'number' => 'CNT-'.Str::upper(Str::random(6)),
            'status' => StockCount::STATUS_OPEN,
            'is_blind' => true,
        ];
    }

    /**
     * The counter can see the expected figure — which is why it is not the default.
     */
    public function sighted(): self
    {
        return $this->state(fn (): array => ['is_blind' => false]);
    }
}
