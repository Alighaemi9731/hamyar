<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\CRM\Enums\PartyKind;
use App\Modules\CRM\Models\Party;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Party>
 */
final class PartyFactory extends Factory
{
    protected $model = Party::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'kind' => PartyKind::Customer,
            'name' => fake()->randomElement(['علی رضایی', 'مریم احمدی', 'حسین کریمی', 'زهرا موسوی']),
            'opening_balance' => 0,
            'is_active' => true,
        ];
    }

    public function supplier(): self
    {
        return $this->state(fn (): array => [
            'kind' => PartyKind::Supplier,
            'company_name' => 'پخش '.fake()->word(),
        ]);
    }

    public function colleague(): self
    {
        return $this->state(fn (): array => ['kind' => PartyKind::Colleague]);
    }

    public function withCreditLimit(int $rial): self
    {
        return $this->state(fn (): array => ['credit_limit' => $rial]);
    }

    public function openingBalance(int $rial): self
    {
        return $this->state(fn (): array => ['opening_balance' => $rial]);
    }
}
