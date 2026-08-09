<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\CRM\Models\Account;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Account>
 */
final class AccountFactory extends Factory
{
    protected $model = Account::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'صندوق فروشگاه',
            'type' => Account::TYPE_CASH,
            'opening_balance' => 0,
            'is_default' => false,
            'is_active' => true,
        ];
    }

    public function posTerminal(): self
    {
        return $this->state(fn (): array => [
            'name' => 'کارتخوان',
            'type' => Account::TYPE_POS_TERMINAL,
            'terminal_number' => fake()->numerify('########'),
        ]);
    }
}
