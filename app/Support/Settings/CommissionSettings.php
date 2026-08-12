<?php

declare(strict_types=1);

namespace App\Support\Settings;

/**
 * What a shop pays its salespeople, per sale.
 *
 * Approved at DECISION GATE 3 (2026-08-12): commission is a percentage of the
 * **margin** a sale made, not of its value.
 *
 * ## Why margin and not turnover
 *
 * A salesperson paid on turnover has no reason to hold a price. Discount a
 * 100,000,000 phone to 95,000,000 and their commission falls by five percent while the
 * shop's margin falls by twenty-two. Paying on margin makes the discount cost them too,
 * which is the only version of the incentive that points the same way as the shop's.
 *
 * ## The rate defaults to zero, and zero means "no accrual"
 *
 * Not "some sensible default percentage". A shop that has never opened the settings
 * screen has not agreed to owe anybody anything, and inventing a rate would accrue a
 * liability nobody promised — discovered at payroll, argued about with a person.
 */
final readonly class CommissionSettings
{
    public function __construct(
        /** Percent of margin, 0–100. Zero switches the accrual off entirely. */
        public int $rate,
    ) {}

    public function isEnabled(): bool
    {
        return $this->rate > 0;
    }

    /**
     * The accrual on a given margin, in rial.
     *
     * Floored to a whole toman like every other derived figure on an invoice (ADR 0009)
     * — a shop cannot pay a tenth of one.
     *
     * A negative margin earns nothing rather than owing the shop money back: selling at
     * a loss is a decision somebody above the salesperson made, and clawing it out of
     * their commission is not what "commission" means.
     */
    public function on(int $margin): int
    {
        if (! $this->isEnabled() || $margin <= 0) {
            return 0;
        }

        return intdiv(intdiv($margin * $this->rate, 100), 10) * 10;
    }

    /**
     * The shape stored in `sales_invoices.settings_snapshot`.
     *
     * @return array{commission_rate: int}
     */
    public function toSnapshot(): array
    {
        return ['commission_rate' => $this->rate];
    }
}
