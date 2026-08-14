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

    /**
     * The VAT rate, and whether this shop charges it at all.
     *
     * Snapshotted onto the invoice beside the rounding policy, for the same reason: an
     * invoice reprinted after the shop registers for VAT must still show the tax it
     * actually charged on the day, which was none.
     */
    public function vat(): VatSettings;

    /**
     * The shop's own mark and wording on printed documents.
     *
     * Snapshotted onto the invoice like the other two: a reprint has to carry the terms
     * that were in force on the day, not the ones the shop rewrote last week.
     */
    public function print(): PrintSettings;

    /**
     * What the shop pays its salespeople on each sale.
     *
     * Snapshotted onto the invoice like the rest: a rate changed in Mehr must not
     * silently restate what was earned in Shahrivar.
     */
    public function commission(): CommissionSettings;

    /**
     * How the repair bench behaves — the approval cap and the abandonment window.
     */
    public function repairs(): RepairSettings;

    /**
     * Late fees and early-settlement policy.
     *
     * Off by default in every respect a customer would notice — see
     * {@see InstallmentSettings} on why there is no sensible default for a charge
     * somebody has to defend at a counter.
     */
    public function installments(): InstallmentSettings;

    /**
     * Which automatic messages are switched on.
     *
     * Every one defaults to OFF — see {@see MessagingSettings} on why that direction is
     * not a preference.
     */
    public function messaging(): MessagingSettings;
}
