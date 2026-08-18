<?php

declare(strict_types=1);

use App\Modules\Catalog\Services\BarcodeRenderer;
use App\Support\QrRenderer;

/**
 * The SVG this application injects with `dangerouslySetInnerHTML` must be inert.
 *
 * Three screens embed a server-generated SVG string directly into the DOM — the label
 * sheet's barcodes, the repair receipt's tracking QR, and the invoice print's QR. React
 * escapes everything else it renders; these are the deliberate exceptions, because an
 * `<img src="data:...">` cannot be styled to print at an exact millimetre size and a
 * scanner needs exactly that.
 *
 * **A deliberate exception is a thing that needs a test.** The barcode's input is a
 * variant's `barcode` or `sku` — operator-supplied, and settable in bulk through the
 * products import, so the shortest path to this string is a spreadsheet emailed to a
 * shop. Code 128 encodes all printable ASCII, `<` and `>` included.
 *
 * The generators do escape today: the payload lands in a `<desc>` element as entities,
 * and the bars carry no text at all. This asserts that rather than trusting it, because
 * the property belongs to a third-party library that could reasonably change how it
 * labels its output in a minor version — and the failure would be silent, arriving as a
 * stored cross-site script on a screen an Owner opens to print price tags.
 *
 * Note what is asserted: **not** that the output contains no dangerous-looking
 * substring. The first version of this check searched for the word "script" and flagged
 * `&lt;script&gt;` in the escaped description — a false alarm that would have sent
 * somebody hunting a vulnerability that was never there. The question is whether a
 * parser sees a script node, so the test asks a parser.
 */

/** Inputs whose whole purpose is to escape the fragment they are embedded in. */
const HOSTILE_PAYLOADS = [
    'closing tag then script' => '</svg><script>alert(1)</script>',
    'attribute break then handler' => '"><img src=x onerror=alert(1)>',
    'svg-native handler' => '<set onbegin="alert(1)"/>',
    'entity-encoded' => '&lt;script&gt;alert(1)&lt;/script&gt;',
    'benign control' => 'ABC123',
];

/**
 * @return array{scripts: int, handlers: int, parses: bool}
 */
function svgThreatProfile(string $svg): array
{
    $document = new DOMDocument;

    // `loadXML` rather than `loadHTML`: an SVG fragment injected into the DOM is parsed
    // as XML, and a fragment that does not parse is one whose shape we cannot reason
    // about at all.
    $parses = @$document->loadXML($svg);

    if (! $parses) {
        return ['scripts' => 0, 'handlers' => 0, 'parses' => false];
    }

    $xpath = new DOMXPath($document);

    // `local-name()` so a namespaced `svg:script` is caught too.
    $scriptNodes = $xpath->query('//*[local-name()="script"]');
    $scripts = $scriptNodes === false ? 0 : $scriptNodes->length;

    $handlers = 0;

    $attributes = $xpath->query('//@*');

    /** @var DOMAttr $attribute */
    foreach ($attributes === false ? [] : $attributes as $attribute) {
        if (str_starts_with(strtolower($attribute->nodeName), 'on')) {
            $handlers++;
        }
    }

    return ['scripts' => $scripts, 'handlers' => $handlers, 'parses' => true];
}

it('renders a barcode with no executable node, whatever the operator typed', function (string $payload): void {
    $svg = app(BarcodeRenderer::class)->svg($payload);

    // Null is a fine answer — a code the symbology cannot express is refused outright,
    // which is safe by a different route.
    if ($svg === null) {
        expect(true)->toBeTrue();

        return;
    }

    $profile = svgThreatProfile($svg);

    expect($profile['parses'])->toBeTrue('The fragment must be well-formed to reason about it at all.')
        ->and($profile['scripts'])->toBe(0)
        ->and($profile['handlers'])->toBe(0);
})->with(HOSTILE_PAYLOADS);

it('renders a QR with no executable node, whatever the payload', function (string $payload): void {
    // The QR's payload is a URL this application builds rather than operator input, so
    // this is the thinner of the two cases — and it is here because "the caller only
    // ever passes a safe value" is a property of today's callers.
    $svg = app(QrRenderer::class)->svg($payload);

    if ($svg === null) {
        expect(true)->toBeTrue();

        return;
    }

    $profile = svgThreatProfile($svg);

    expect($profile['parses'])->toBeTrue()
        ->and($profile['scripts'])->toBe(0)
        ->and($profile['handlers'])->toBe(0);
})->with(HOSTILE_PAYLOADS);

it('escapes the payload it echoes rather than dropping it', function (): void {
    // The description carries the code so a screen reader and a debugging developer can
    // both read it. Asserting it is *present but escaped* pins the actual mechanism:
    // a future version that stopped escaping would still pass a test that only asked
    // whether the raw string was absent.
    $svg = app(BarcodeRenderer::class)->svg('</svg>');

    expect($svg)->toContain('&lt;/svg&gt;')
        ->and(svgThreatProfile((string) $svg)['parses'])->toBeTrue();
});
