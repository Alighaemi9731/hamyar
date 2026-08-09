<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Inventory\Models\Branch;
use App\Modules\Inventory\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Warehouse>
 */
final class WarehouseFactory extends Factory
{
    protected $model = Warehouse::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'name' => 'انبار '.fake()->word(),
            'is_sellable' => true,
            'allows_negative_stock' => false,
            'is_default' => false,
            'is_active' => true,
        ];
    }

    /**
     * A repair bench: parts here are committed to a job and must never read as
     * available to the till.
     */
    public function bench(): self
    {
        return $this->state(fn (): array => ['name' => 'میز تعمیر', 'is_sellable' => false]);
    }

    public function default(): self
    {
        return $this->state(fn (): array => ['is_default' => true]);
    }
}
