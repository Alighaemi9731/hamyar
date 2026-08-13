<?php

declare(strict_types=1);

namespace App\Modules\Settings\Services;

use App\Support\Settings\CommissionSettings;
use App\Support\Settings\InstallmentSettings;
use App\Support\Settings\PrintSettings;
use App\Support\Settings\RepairSettings;
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

    /** Sixty days is what an Iranian shop usually answers when asked. */
    public const DEFAULT_ABANDONED_AFTER_DAYS = 60;

    /** Days late before any fee is charged. Five is the common shop answer. */
    public const DEFAULT_LATE_FEE_GRACE_DAYS = 5;

    /** However late it gets, the fee stops at a fifth of the instalment. */
    public const DEFAULT_LATE_FEE_CAP_PERCENT = 20;

    /**
     * A reminder at a week, a warning at a month, a last notice at seven weeks — and
     * then رسوبی at sixty days.
     *
     * @var list<int>
     */
    public const DEFAULT_ESCALATION_DAYS = [7, 30, 50];

    public function repairs(): RepairSettings
    {
        $tenant = $this->context->tenant();

        $cap = $tenant?->setting('repairs.approval_cap');
        $days = $tenant?->setting('repairs.abandoned_after_days');

        return new RepairSettings(
            // Zero — every job needs approval — until a shop says otherwise. Guessing
            // generously here is guessing with a customer's money.
            approvalCap: is_int($cap) && $cap > 0 ? $cap : 0,
            abandonedAfterDays: is_int($days) && $days > 0 ? $days : self::DEFAULT_ABANDONED_AFTER_DAYS,
            escalationDays: $this->escalationDays($tenant?->setting('repairs.escalation_days')),
        );
    }

    public function installments(): InstallmentSettings
    {
        $tenant = $this->context->tenant();

        $percent = $tenant?->setting('installments.late_fee_percent_per_month');
        $grace = $tenant?->setting('installments.late_fee_grace_days');
        $cap = $tenant?->setting('installments.late_fee_cap_percent');

        return new InstallmentSettings(
            // Zero — no late fee at all — until a shop configures one. A charge the owner
            // never chose is worse than no charge.
            lateFeePercentPerMonth: is_int($percent) && $percent > 0 ? min($percent, 100) : 0,
            lateFeeGraceDays: is_int($grace) && $grace >= 0 ? $grace : self::DEFAULT_LATE_FEE_GRACE_DAYS,
            // Capped even when a shop sets something absurd: a fee larger than the
            // instalment is not a late fee, it is a different contract.
            lateFeeCapPercent: is_int($cap) && $cap > 0 ? min($cap, 100) : self::DEFAULT_LATE_FEE_CAP_PERCENT,
        );
    }

    /**
     * The nudge ladder, sanitised.
     *
     * A settings document is user input by the time it reaches here, so anything that is
     * not a list of positive integers falls back to the default rather than producing a
     * ladder that fires on day zero or in a random order. Sorted and de-duplicated
     * because a shop editing JSON by hand will eventually write `[7, 7, 3]`.
     *
     * @return list<int>
     */
    private function escalationDays(mixed $configured): array
    {
        if (! is_array($configured)) {
            return self::DEFAULT_ESCALATION_DAYS;
        }

        $days = [];

        foreach ($configured as $value) {
            if (is_int($value) && $value > 0) {
                $days[] = $value;
            }
        }

        if ($days === []) {
            return self::DEFAULT_ESCALATION_DAYS;
        }

        $days = array_values(array_unique($days));
        sort($days);

        return $days;
    }

    public function commission(): CommissionSettings
    {
        $rate = $this->context->tenant()?->setting('commission.rate');

        return new CommissionSettings(
            // Clamped, and zero unless the shop said otherwise — see CommissionSettings
            // for why a default rate would be a liability nobody agreed to.
            rate: is_int($rate) && $rate > 0 && $rate <= 100 ? $rate : 0,
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
