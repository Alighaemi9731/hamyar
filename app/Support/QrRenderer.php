<?php

declare(strict_types=1);

namespace App\Support;

use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\Encoder;
use BaconQrCode\Exception\ExceptionInterface as BaconException;

/**
 * A QR code, as an inline SVG a printed document can embed.
 *
 * ## Why this is hand-rolled rather than Bacon's own SVG writer
 *
 * Bacon ships an `SvgImageBackEnd`, and it needs `ext-dom`. This one walks the encoded
 * matrix and emits one `<rect>` per dark module, which needs nothing at all — the
 * container stays smaller and a deploy cannot fail on a missing PHP extension for the
 * sake of a square of dots.
 *
 * Same reasoning as {@see \App\Modules\Catalog\Services\BarcodeRenderer}: rendered on the
 * server so what a phone camera reads is exactly what we meant to encode, and sized in
 * percentages so the print layout decides how big it is.
 *
 * ## Error correction is set high on purpose
 *
 * These get printed on 80mm thermal paper by a machine with a fading ribbon, then
 * photographed under shop lighting. `H` tolerates about thirty percent of the code being
 * unreadable, which is the difference between a customer scanning their receipt and a
 * customer typing a URL.
 */
final class QrRenderer
{
    /** A quiet zone of four modules is the spec minimum; below it, scanners struggle. */
    private const QUIET_ZONE = 4;

    /**
     * An inline `<svg>` fragment, or null when the payload cannot be encoded.
     */
    public function svg(?string $payload): ?string
    {
        if ($payload === null || trim($payload) === '') {
            return null;
        }

        try {
            $matrix = Encoder::encode(
                trim($payload),
                ErrorCorrectionLevel::H(),
                Encoder::DEFAULT_BYTE_MODE_ECODING,
            )->getMatrix();
        } catch (BaconException) {
            // A payload too long for the symbology is a data problem, not a 500 on a
            // receipt that is otherwise printing correctly.
            return null;
        }

        $size = $matrix->getWidth();
        $side = $size + (self::QUIET_ZONE * 2);

        $rects = '';

        for ($y = 0; $y < $size; $y++) {
            for ($x = 0; $x < $size; $x++) {
                if ($matrix->get($x, $y) === 1) {
                    $px = $x + self::QUIET_ZONE;
                    $py = $y + self::QUIET_ZONE;

                    // Drawn 1.02 wide rather than 1: at small print sizes a renderer
                    // rounding each rect independently leaves hairline gaps between
                    // modules, and a scanner reads those gaps as light.
                    $rects .= "<rect x=\"{$px}\" y=\"{$py}\" width=\"1.02\" height=\"1.02\"/>";
                }
            }
        }

        return '<svg xmlns="http://www.w3.org/2000/svg" width="100%" height="100%" '
            ."viewBox=\"0 0 {$side} {$side}\" shape-rendering=\"crispEdges\" role=\"img\">"
            // An explicit white ground: thermal paper is white, but a PDF exported from
            // the browser in dark mode would otherwise put dark modules on dark nothing.
            ."<rect width=\"{$side}\" height=\"{$side}\" fill=\"#ffffff\"/>"
            ."<g fill=\"#000000\">{$rects}</g>"
            .'</svg>';
    }
}
