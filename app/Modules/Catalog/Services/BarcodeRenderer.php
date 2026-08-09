<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Services;

use Picqer\Barcode\BarcodeGeneratorSVG;
use Picqer\Barcode\Exceptions\BarcodeException;

/**
 * A variant's barcode, as an SVG fragment a label can embed.
 *
 * Rendered on the server rather than in the browser for one reason: what a scanner
 * reads has to be exactly what the database holds. A client-side encoder is another
 * implementation of the same standard, and the day the two disagree the shop finds out
 * by scanning a label that opens the wrong product.
 *
 * **Code 128**, deliberately. Iranian shops use a mix of EAN-13 retail codes, their own
 * sequential numbers and supplier codes, and Code 128 encodes all of them — including
 * letters — while EAN-13 would refuse everything that is not thirteen digits with a
 * correct check digit. A label that cannot be printed is worse than one in a slightly
 * less compact symbology.
 */
final class BarcodeRenderer
{
    /** Bar width in SVG user units. 2 keeps a 13-digit code inside a 38mm label. */
    private const BAR_WIDTH = 2;

    private const HEIGHT = 40;

    /**
     * An inline `<svg>` fragment, or null when the code cannot be encoded.
     *
     * The XML prolog and doctype are stripped: they are legal in a standalone file and
     * illegal in the middle of an HTML document, where they would end the fragment and
     * leave the rest of the label as text.
     *
     * Width and height become 100% so the label box decides the size — a barcode that
     * carries its own pixel dimensions cannot be printed at two label sizes.
     */
    public function svg(?string $code): ?string
    {
        if ($code === null || trim($code) === '') {
            return null;
        }

        try {
            $svg = (new BarcodeGeneratorSVG)->getBarcode(
                trim($code),
                BarcodeGeneratorSVG::TYPE_CODE_128,
                self::BAR_WIDTH,
                self::HEIGHT,
            );
        } catch (BarcodeException) {
            // A code the symbology cannot express is a data problem for the operator to
            // fix, not a 500 on a page that is printing forty other labels correctly.
            return null;
        }

        $svg = (string) preg_replace('/<\?xml[^>]*\?>\s*/', '', $svg);
        $svg = (string) preg_replace('/<!DOCTYPE[^>]*>\s*/', '', $svg);

        return (string) preg_replace(
            '/<svg width="(\d+)" height="(\d+)"/',
            '<svg width="100%" height="100%" preserveAspectRatio="none"',
            $svg,
            1
        );
    }
}
