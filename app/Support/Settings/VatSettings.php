<?php

declare(strict_types=1);

namespace App\Support\Settings;

/**
 * One shop's VAT policy — مالیات بر ارزش افزوده.
 *
 * Two fields rather than one, because "what the rate is" and "whether this shop charges
 * it" are genuinely different questions. The statutory rate is the same for everybody;
 * whether a given shop is registered for it is not, and most small mobile shops are not.
 *
 * So `enabled` defaults to **false**. A shop that has never opened the settings screen
 * must not start adding ten percent to invoices it has no authority to collect — that is
 * money taken from a customer that the shop then owes to nobody it can pay, and it is
 * discovered by the customer, at the counter, arguing.
 *
 * `rate` still carries the current statutory figure even while disabled, so ticking the
 * box on the invoice is one click and not a research exercise.
 */
final readonly class VatSettings
{
    public function __construct(
        /** Percent, 0–100. Integer: the rate has never been fractional here. */
        public int $rate,
        /** Whether new invoices open with VAT applied. */
        public bool $enabled,
    ) {}

    /**
     * The rate to write on a line, given what the invoice decided.
     *
     * A disabled shop that ticks the box on one invoice still charges the real rate —
     * `enabled` sets the default, it does not cap it.
     */
    public function rateWhen(bool $applied): int
    {
        return $applied ? $this->rate : 0;
    }

    /**
     * The shape stored in `sales_invoices.settings_snapshot`.
     *
     * @return array{vat_rate: int, vat_enabled: bool}
     */
    public function toSnapshot(): array
    {
        return [
            'vat_rate' => $this->rate,
            'vat_enabled' => $this->enabled,
        ];
    }
}
