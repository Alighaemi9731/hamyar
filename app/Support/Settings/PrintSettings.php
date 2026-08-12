<?php

declare(strict_types=1);

namespace App\Support\Settings;

/**
 * What a shop puts on its own paperwork.
 *
 * Three fields, and each one exists because an Iranian mobile shop's receipt has to
 * carry something ours cannot know:
 *
 * - `logo_url` — the shop's own mark. A URL rather than an upload id, so a shop can
 *   point at whatever they already have and the print layout does not depend on the
 *   Files module being wired up.
 * - `footer_terms` — the warranty and returns wording. Every shop's differs, it is the
 *   text that gets read back at them in an argument, and it is the single most common
 *   thing a shop asks to change about a printed invoice.
 * - `show_qr` — whether the receipt carries a link to the online copy at all. On by
 *   default, because it costs a square centimetre and saves a phone call, but a shop
 *   printing on a narrow ribbon may want the space.
 */
final readonly class PrintSettings
{
    public function __construct(
        public ?string $logoUrl,
        public ?string $footerTerms,
        public bool $showQr,
    ) {}

    /**
     * The shape stored in `sales_invoices.settings_snapshot`.
     *
     * Snapshotted with the rest so a reprint of a year-old invoice carries the terms
     * that were in force when it was issued — which is the version that governs the
     * argument being had about it.
     *
     * @return array{print_logo_url: string|null, print_footer_terms: string|null, print_show_qr: bool}
     */
    public function toSnapshot(): array
    {
        return [
            'print_logo_url' => $this->logoUrl,
            'print_footer_terms' => $this->footerTerms,
            'print_show_qr' => $this->showQr,
        ];
    }
}
