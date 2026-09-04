# The sign-in illustration — the brief, and the prompt to order it with

The sign-in and sign-up pages have an artwork column beside the form card. Until a
drawing lands at `resources/brand/auth-art.svg`, the column shows a real screenshot of
the dashboard in a frame — honest, on brand, and impossible to mistake for a placeholder.
Dropping the SVG into that path replaces it; nothing else changes.

## What the drawing has to be

- **One SVG file**, `resources/brand/auth-art.svg`, inlined into the page. No raster, no
  external request, no `<image href>` pointing anywhere.
- **Roughly 4:3, landscape**, and it must hold up between about 420px and 720px wide. It
  is hidden below 900px, so it never has to work on a phone.
- **Flat vector.** No 3D renders, no gradients meshes, no drop shadows on every object,
  no photographic textures. The product's own surfaces are flat, and a glossy 3D render
  beside a flat form card looks like two products.
- **Our palette only**: `#0066cc` brand blue, `#0e1b2c` navy ink, `#46586d` secondary,
  `#f2f5f9` tinted ground, `#ffffff`. One accent. Semantic green `#0f7b3f` and amber
  `#8a5a00` are allowed only if something in the picture genuinely means "done" or
  "waiting".
- **No text in the drawing.** Persian text drawn by an image model comes out as broken
  letterforms, and any Latin text would be the one English thing on a Persian page.
  Numbers as abstract bars or dashes are fine.
- **No people, no faces, no hands.**

## What it should show

The product's own subject, not generic office imagery: a mobile-phone shop's counter
work. Any one of these reads correctly:

- a handset seen flat-on with its IMEI represented as a barcode-like row of bars, and a
  thin line running from it to a small stack of cards — the passport idea: one device,
  one record, everything attached to it;
- a repair ticket and a phone connected by a simple line to a small tick;
- an invoice sheet, a cash box and a cheque arranged as three flat objects on a surface.

**Avoid**: shopping carts, credit cards floating in space, coins, rockets, gears,
lightbulbs, magnifying glasses, clipboards with ticks, hand-drawn "doodle" people. Those
are the stock-illustration vocabulary and they say nothing about this product.

## The prompt

Copy everything between the lines into the image model.

---

Design a single flat vector illustration, delivered as clean SVG, for the sign-in page of
a Persian (RTL) cloud software product used by mobile-phone repair and resale shops in
Iran.

Subject: one modern smartphone shown flat and face-on, slightly angled, drawn as simple
geometric shapes. To one side of it, a short horizontal row of vertical bars of varying
thickness, suggesting a scanned IMEI barcode — abstract, not a real readable barcode. A
single thin connecting line runs from the phone to a small neat stack of three rounded
rectangular cards, offset from each other, representing that device's records: purchase,
repair, sale. One small circular tick badge sits on the top card.

Style: flat vector, geometric, generous negative space, calm and precise. Uniform
2px-equivalent strokes where strokes are used. Rounded corners of about 8px on the
rectangles. No gradients, no 3D, no shadows other than at most one very soft flat
offset shape. No texture, no noise, no outlines around the whole composition.

Colour: use exactly these — primary blue #0066cc, dark navy #0e1b2c, muted slate #46586d,
very light blue-grey #f2f5f9, and white #ffffff. Blue is the only accent and should
appear on no more than a third of the artwork. The background must be transparent.

Composition: landscape, roughly 4:3, the phone occupying the visual centre-left, the card
stack to its right, balanced with clear empty space around the whole group so it can sit
on a light page without a container. It must read clearly at 480 pixels wide.

Absolutely no text, no letters, no numbers, no words in any language. No people, faces or
hands. No shopping carts, coins, credit cards, gears, rockets, lightbulbs or clipboards.

Output: a single self-contained SVG, no embedded raster images, no external fonts, no
`<text>` elements, viewBox starting at 0 0, and a transparent background.

---

## When the file arrives

1. Save it as `resources/brand/auth-art.svg`.
2. Open it and check three things before committing: no `<text>`, no `<image>`, and no
   `width`/`height` on the root `<svg>` (the page sizes it).
3. `resources/views/auth/art.blade.php` picks it up automatically — the screenshot
   fallback stops rendering the moment the file exists.
