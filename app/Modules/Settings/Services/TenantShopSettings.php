<?php

declare(strict_types=1);

namespace App\Modules\Settings\Services;

use App\Support\Settings\RoundingDirection;
use App\Support\Settings\RoundingSettings;
use App\Support\Settings\ShopSettings;
use App\Support\Tenancy\TenantContext;

/**
 * Settings, read from the shop's own `tenants.settings` document.
 *
 * The Settings module's implementation of the shared-kernel contract. Sales asks the
 * interface and never learns this class exists (ADR 0003).
 *
 * Every accessor falls back to a documented default rather than returning null, because
 * a shop that has never opened the settings screen must still be able to sell — and a
 * missing setting resolving to "no rounding" would quietly change the arithmetic on
 * every invoice.
 */
final class TenantShopSettings implements ShopSettings
{
    /** ADR 0009: the smallest step that removes unpayable change. */
    public const DEFAULT_ROUNDING_STEP = 1_000;

    public const DEFAULT_ROUNDING_DIRECTION = RoundingDirection::Nearest;

    public function __construct(private readonly TenantContext $context) {}

    public function rounding(): RoundingSettings
    {
        $tenant = $this->context->tenant();

        $step = $tenant?->setting('rounding.step');
        $direction = $tenant?->setting('rounding.direction');

        return new RoundingSettings(
            step: is_int($step) && $step > 0 ? $step : self::DEFAULT_ROUNDING_STEP,
            direction: is_string($direction)
                ? (RoundingDirection::tryFrom($direction) ?? self::DEFAULT_ROUNDING_DIRECTION)
                : self::DEFAULT_ROUNDING_DIRECTION,
        );
    }
}
