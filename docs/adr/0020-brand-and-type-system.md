# ADR 0020 — The brand layer after the 16.2 gate: navy ink, one type family, one mark

- **Status**: accepted
- **Date**: 2026-09-04
- **Decides**: DECISION GATE 5's brand half (`docs/ROADMAP.md` 16.2). The landing's
  composition is ADR 0021.
- **Supersedes**: ADR 0008's ink and type pairing. Its accent, semantics, scale, shape
  and depth stand.

## Context

Redesign v1 (#84–#129) propagated a token system across 76 pages, and the owner judged
the result «obviously AI-generated, unprofessional» on 2026-09-03. The 16.0 baseline
(`docs/walks/v2/baseline.md`) traced part of that verdict to the brand layer itself
rather than to any page:

- The ink was `#1d1d1f`, a neutral near-black. It was never chosen — it arrived with
  shadcn's defaults and ADR 0008 kept it. A product whose single accent is blue and
  whose text is grey has one colour in the room and no relationship between them.
- The type was Estedad 800 for display over Vazirmatn 17/1.85 for text: the pairing a
  Persian AI page defaults to, set loose, with the display weight two steps heavier than
  anything the content needed.
- ADR 0016 left the ink open on purpose: the landing already wrote in navy `#0E1B2C`
  while the product wrote in `#1d1d1f`, so the two surfaces of one product disagreed
  about what black is.

Four type pairings, two ink families and three marks were rendered on `/design` with the
product's own copy and handed to the owner as a decision page.

## Decision

The owner answered on 2026-09-04: **pairing 2, mark C, navy ink**.

### 1. Ink is navy

| token | was | is | measured |
|---|---|---|---|
| `ink` | `#1d1d1f` | `#0e1b2c` | 17.3:1 on white, 15.9:1 on `canvas-alt` |
| `ink-soft` | `#6e6e73` | `#46586d` | 7.3:1 on white, 6.7:1 on `canvas-alt` |
| `canvas-alt` | `#f5f5f7` | `#f2f5f9` | — |
| `canvas-invert` | `#000000` | `#0a1628` | — |

Hairlines become alpha of the ink (`rgb(14 27 44 / 0.1)`) rather than alpha of black: a
neutral-grey hairline under navy text is exactly where a mixed palette shows, and it
shows on every row of every register.

Dark mode moves off the neutral family with it — ground `#0a1628`, card `#142943`, muted
surface `#1c3452`, text `#e9eff7`, secondary text `#9aadc4`. The `-on-dark` semantic
steps were measured against `#1d1d1f` when ADR 0008 wrote them; they are re-measured
against `#142943` here and every one still clears AA (success 6.7:1, warning 6.5:1,
danger 5.2:1, brand 5.2:1).

**Every pair in both themes was measured before this merged, not after.** The full table
is in the PR; nothing sits below 4.5:1.

### 2. One type family: IBM Plex Sans Arabic

`--font-sans` and `--font-display` both resolve to it, at 400/500/600/700 — the four
static weights it ships and the exact four the product uses, so there is no variable
axis to interpolate and no weight to synthesise.

Why one family rather than a display/text pair: on every screen in this product an IMEI,
a rial figure and a Persian label share a single row. A two-family pairing made that row
three typefaces wide, and the join showed most on the screens the shop reads all day.

Its Arabic subset was verified to cover what Persian actually needs — the Persian digits
`U+06F0–U+06F9`, the separator `U+066C`, «ک» `U+06A9`, «ی» `U+06CC` and the ezafe hamza —
by measuring rendered advance widths against a deliberate miss, not by trusting the
family name. A face that silently falls back for digits would have put two typefaces in
every money column.

Vazirmatn, Estedad and Noto Kufi Arabic stay in `resources/fonts/` as the type test's
other candidates and the gallery still renders all four. Nothing in the product points
at them.

### 3. The mark is candidate C

«همراه»: two bracket forms facing each other, making one handset silhouette with light
between them, and the brand dot in the gap. `resources/brand/mark.svg` already held it
provisionally since #136; the gate confirms it. `components/brand-mark.tsx` is the only
import site, so the pick was always a file swap.

## Consequences

- The change reached 76 pages without one page being edited. That is the whole argument
  for a token layer, and it is now demonstrated rather than asserted.
- `docs/design-system.md` §Ground/Typography and the `/design` ink sheet are updated; the
  retired neutral steps stay on the sheet for comparison.
- Anything that hardcoded `#1d1d1f` or `#f5f5f7` would now be visibly out of family. The
  gallery's swatch table is the only place either value may still appear, and it is
  labelled as retired.
- Auth screens moving to Blade is **not** decided here. It was proposed alongside this
  ADR in the plan; it belongs to the landing work and is recorded in ADR 0021.
