<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Inventory\Models\StockTransfer;
use App\Modules\Inventory\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<StockTransfer>
 */
final class StockTransferFactory extends Factory
{
    protected $model = StockTransfer::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'from_warehouse_id' => Warehouse::factory(),
            'to_warehouse_id' => Warehouse::factory(),
            'number' => 'TRF-'.Str::upper(Str::random(6)),
            'status' => StockTransfer::STATUS_DRAFT,
        ];
    }
}
