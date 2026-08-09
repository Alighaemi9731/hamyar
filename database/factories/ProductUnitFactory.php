<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Inventory\Enums\UnitCondition;
use App\Modules\Inventory\Enums\UnitStatus;
use App\Modules\Inventory\Models\ProductUnit;
use App\Modules\Inventory\Models\Warehouse;
use App\Support\Imei;
use App\Support\Money;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductUnit>
 */
final class ProductUnitFactory extends Factory
{
    protected $model = ProductUnit::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_variant_id' => ProductVariant::factory(),
            'warehouse_id' => Warehouse::factory(),
            // A real Luhn-valid IMEI, so tests exercise validation rather than
            // sidestepping it with an obviously fake number.
            'imei1' => self::validImei(),
            'status' => UnitStatus::InStock,
            'condition' => UnitCondition::New,
            'cost' => Money::fromToman(fake()->numberBetween(1_000, 60_000) * 1_000),
            'acquired_at' => now()->subDays(fake()->numberBetween(0, 60)),
        ];
    }

    /**
     * A syntactically valid 15-digit IMEI with a correct check digit.
     */
    public static function validImei(): string
    {
        // 35 is a common Type Allocation Code prefix; the rest is random.
        $body = '35'.fake()->unique()->numerify('############');

        return $body.((string) Imei::checkDigitFor($body));
    }

    public function used(string $grade = 'A'): self
    {
        return $this->state(fn (): array => [
            'condition' => UnitCondition::Used,
            'grade' => $grade,
        ]);
    }

    public function status(UnitStatus $status): self
    {
        return $this->state(fn (): array => ['status' => $status]);
    }

    public function dualSim(): self
    {
        return $this->state(fn (): array => ['imei2' => self::validImei()]);
    }
}
