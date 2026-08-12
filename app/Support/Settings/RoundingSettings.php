<?php

declare(strict_types=1);

namespace App\Support\Settings;

/**
 * One shop's rounding policy (ADR 0009).
 *
 * A value object rather than two loose arguments, so it can be snapshotted onto an
 * invoice and read back as a unit — a step without its direction is not a policy.
 */
final readonly class RoundingSettings
{
    public function __construct(
        public int $step,
        public RoundingDirection $direction,
    ) {}

    /**
     * The shape stored in `sales_invoices.settings_snapshot`.
     *
     * @return array{rounding_step: int, rounding_direction: string}
     */
    public function toSnapshot(): array
    {
        return [
            'rounding_step' => $this->step,
            'rounding_direction' => $this->direction->value,
        ];
    }
}
