<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Inventory\Models\Branch;
use App\Modules\Repairs\Enums\TicketStatus;
use App\Modules\Repairs\Models\RepairTicket;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<RepairTicket>
 */
final class RepairTicketFactory extends Factory
{
    protected $model = RepairTicket::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'code' => 'REP-'.str_pad((string) fake()->unique()->numberBetween(1, 999999), 6, '0', STR_PAD_LEFT),
            'device_brand' => fake()->randomElement(['اپل', 'سامسونگ', 'شیائومی']),
            'device_model' => fake()->randomElement(['آیفون ۱۳', 'گلکسی S23', 'ردمی نوت ۱۲']),
            'reported_issue' => 'روشن نمی‌شود',
            'status' => TicketStatus::Queued,
            'priority' => RepairTicket::PRIORITY_NORMAL,
            'estimate_amount' => 0,
            'prepaid_amount' => 0,
            'tracking_token' => Str::random(48),
        ];
    }

    public function withPasscode(string $passcode = '4517'): self
    {
        return $this->state(fn (): array => ['device_passcode' => $passcode]);
    }

    public function status(TicketStatus $status): self
    {
        return $this->state(fn (): array => ['status' => $status]);
    }
}
