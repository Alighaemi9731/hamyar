<?php

declare(strict_types=1);

namespace App\Modules\Settings\Services;

use App\Support\Settings\PrintSettings;
use App\Support\Settings\RoundingDirection;
use App\Support\Settings\RoundingSettings;
use App\Support\Settings\ShopSettings;
use App\Support\Settings\VatSettings;
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

    /**
     * The statutory Iranian rate. Carried even while VAT is switched off, so a shop that
     * registers only has to tick the box.
     */
    public const DEFAULT_VAT_RATE = 10;

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

    public function print(): PrintSettings
    {
        $tenant = $this->context->tenant();

        $logo = $tenant?->setting('print.logo_url');
        $terms = $tenant?->setting('print.footer_terms');
        $showQr = $tenant?->setting('print.show_qr');

        return new PrintSettings(
            logoUrl: $this->nonEmptyString($logo),
            footerTerms: $this->nonEmptyString($terms),
            // Defaults ON. Only an explicit `false` turns it off — a missing setting
            // meaning "no QR" would quietly drop the feature for every existing shop.
            showQr: $showQr !== false,
        );
    }

    private function nonEmptyString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    public function vat(): VatSettings
    {
        $tenant = $this->context->tenant();

        $rate = $tenant?->setting('vat.rate');
        $enabled = $tenant?->setting('vat.enabled');

        return new VatSettings(
            // Clamped rather than trusted: this is a percent, and a settings document
            // carrying 1000 would multiply an invoice by eleven.
            rate: is_int($rate) && $rate >= 0 && $rate <= 100 ? $rate : self::DEFAULT_VAT_RATE,
            // Defaults to off — see VatSettings. Only an explicit `true` turns it on.
            enabled: $enabled === true,
        );
    }
}
