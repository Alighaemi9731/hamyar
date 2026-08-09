<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Inventory\Models\Branch;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Branch>
 */
final class BranchFactory extends Factory
{
    protected $model = Branch::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // tenant_id is filled by BelongsToTenant from the active context, so a test
            // inside runFor() never has to pass it.
            'name' => 'شعبه '.fake()->city(),
            // Unique per tenant, and short because it lands inside document numbers.
            'code' => Str::upper(Str::random(4)),
            'phone' => '021'.fake()->numerify('########'),
            'is_default' => false,
            'is_active' => true,
        ];
    }

    public function default(): self
    {
        return $this->state(fn (): array => ['is_default' => true, 'code' => 'MAIN']);
    }

    public function inactive(): self
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
}
