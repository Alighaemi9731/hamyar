<?php

declare(strict_types=1);

namespace App\Support\Settings;

/**
 * What a shop has decided about how the software behaves for them.
 *
 * A shared-kernel **contract**, bound by the Settings module (ADR 0003). Sales needs
 * the rounding policy and must not import Settings to get it; Settings owns the storage
 * and must not know Sales exists.
 *
 * Deliberately narrow. It grows one accessor at a time, as a module actually needs a
 * setting — a fat `get(string $key)` would let any module read any setting and put the
 * defaults nowhere in particular.
 */
interface ShopSettings
{
    /**
     * The rounding step in rial, and which way totals move.
     *
     * See ADR 0009. Both are per-tenant with seeded defaults; an invoice snapshots them
     * at issue so a reprint rounds the way it did on the day.
     */
    public function rounding(): RoundingSettings;
}
