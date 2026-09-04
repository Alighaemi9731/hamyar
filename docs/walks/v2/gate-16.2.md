# Gate 16.2 — the landing's direction, prepared

Working document for the owner's one stopping point in Redesign v2. What gets shown, why
these and not others, and what each choice commits the build to. The decision itself is
recorded in ADR 0021 after the gate; this file is the preparation and stays as the record
of the hand that was dealt.

## The roll

impeccable's direction seed, run 2026-09-04 in Persuade mode for the landing:
`concept-seed.mjs --scope direction --mode persuade` → key **`fd28c358`**, **assigned index
6** of the grounded list below, six catalog challengers dealt. Reproduce with
`--from fd28c358 --candidate-count 7`. The roll exists so the landing does not converge on
the category default a third time (ADR 0016: two directions built, two rejected live).

## The audience's world — seven grounded candidates, ordered by resonance

The mechanism the page must prove: **one handset, one row, one history** — every device is
a serialized unit whose profit is true because cost was captured at the sale. The audience:
a shop in a پاساژ (علاءالدین, چارسو), the owner behind a glass counter with a customer
waiting, a mid-range Android in hand. What they know by heart, spanning at least three
material families (paper, glass and light, packaging, screens):

| # | world | why it resonates and can carry the mechanism | family |
|---|---|---|---|
| 1 | **دفتر — the ruled ledger book** | Every shop keeps one; a row per handset *is* the product's claim. Rules, index tabs, a red margin, tabular columns. Editorial-industrial. | paper |
| 2 | **شناسنامه — the ID booklet** | The product's own word for the IMEI record. Stamps, dated entries, a photo. The literal reading of the product's metaphor — allowed once, and this is it. | paper |
| 3 | **تابلوی قیمت — the market price board** | The Telegram price lists and LED boards they read every morning: dense columns, live figures, tabular digits, high contrast. The shop as a trading floor. | light |
| 4 | **فاکتور رسمی — the carbon-copy invoice book** | Every sale ends in the official numbered form: boxed fields, a red serial, three coloured copies, a stamp. Document topology. | paper |
| 5 | **ویترین — the glass counter** | Handsets on stands under glass, lit from above: product on light grey, soft shadow, a quiet grid. The category's canon wearing the shop's clothes. | glass |
| 6 | **جعبه و برچسب — the retail box and its IMEI label** | The object every unit *is*: the box-board white, the spec label's bold model name and hairline grid, the barcode, the IMEI in tabular mono, regulatory marks. Scanning the label is the product's first gesture. | packaging |
| 7 | **برچسب زرد — the yellow price tag** | The bazaar's loudest graphic: marker on yellow, dense boxes. Market-fluent; measured 1.4:1 as text (ADR 0016), so it can only ever be a chip. | paper |

Kept out as the rut: the calm white SaaS page with a centred column (built twice), the dark
cinematic scroll page (built, rejected), and the thermal receipt as hero (the signature
element that never worked, retired with this programme).

## The hand the owner sees

Three full cards, real markup at 375 and 1440 under `/design/landing/{a,b,c}`, built on the
brand layer with the fresh captures — never an image of a page:

- **Assigned — «جعبه و برچسب» (box and label).** Thesis: the page is the label on the box,
  and the box is the product. Ground is box-board white; blocks are hairline-ruled spec
  panels; the model name is the biggest type; the IMEI and every figure sit in tabular
  mono; one signal colour where the label would carry a regulatory mark. First viewport:
  the real IMEI passport rendered as the label of the box it came in, the scan gesture as
  the signature interaction (type an IMEI, the label fills). Honest risk: reads as
  packaging or e-commerce if the copy does not keep saying *software*.
- **Impeccable's pick — «دفتر» (the ledger).** My top-ranked world, shown because the
  assigned one is not it. Thesis: the page is the shop's ledger, one ruled sheet, the
  handsets as rows that scroll into place; a red margin rule and index tabs for the
  sections; figures right-aligned on the units digit. First viewport: today's page of the
  ledger with the day's real rows filling in. Honest risk: familiar-editorial — the most
  likely place a careful run lands, and the closest to the last landing's "rows" idea.
- **The standing exit — the category standard, played straight.** A modern SaaS landing
  executed at the craft level of two or three named references (the owner names them),
  no irony: product frame in the hero, bento tour, pricing table. Always offered, never
  recommended by me.

Challengers dealt, and their verdicts (fused with product truth, judged on audience
identification and product clarity):

| challenger | verdict | what the assigned direction takes from it |
|---|---|---|
| teletext magazine (40×24 cell grid, eight colours on black) | declined — a Tehran shopkeeper never read Ceefax | **grid discipline**: the label's spec panels sit on one strict column lattice, no free-floating cards |
| jet-age ticket wallet (carbon coupons, route arcs, official colour) | competitive on clarity, loses on identification | **document topology**: sections as coupons in one wallet — the fold, the stub, the serial; official colour used once |
| ASCII live render, gravity-rain garden, raku vessel, kaiju broadcast alert | declined — no audience identification, no product clarity | nothing; demoted to the row |

## What the owner locks at the gate

1. Direction (assigned / pick / canon / re-roll — plain, safer or bolder).
2. Type pairing (the four on `/design`; the licensed IRANSansX + Yekan Bakh option if bought).
3. Mark (A / B / C on `/design`; C recommended).
4. Ink (navy family into the product, per the contrast sheet).
5. Copy v1 notes (`docs/brand/positioning.md`, `lang/fa/landing.php` draft).
6. Pilot-shop wording, contact channel, legal entity for the footer.

Outputs: ADR 0020 (brand and type), ADR 0021 (landing direction), the direction contract
as the landing layout's first body comment, the surface brief for `/`, PRODUCT.md updated.
