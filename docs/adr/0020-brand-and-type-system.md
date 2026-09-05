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

> **Superseded twice.** The owner retired the symbol on 2026-09-04 and commissioned a
> wordmark on 2026-09-05; see the second amendment below. §3 stays as written because it
> records what the gate decided, and «the pick was always a file swap» is the part that
> held — both replacements were.

## Amendment, 2026-09-05 — the pairing is Estedad + Vazirmatn

The owner reversed §2 the day after the gate, in an instruction that named the pairing,
the weights, the leading and the digit rule outright: display **Estedad 700/800** at
`-0.02em`, body **Vazirmatn 400/500** at 1.7 prose and 1.5 UI, financial figures
Vazirmatn with `tabular-nums`, Latin fallback Inter then the system stack.

**This is the pairing the gate replaced.** Candidate 1 on the 16.2 decision page was
Estedad + Vazirmatn, and the owner picked 2 over it. That is worth stating plainly rather
than quietly re-recording, because the next session that reads only this file would
otherwise conclude the gate chose it. Nothing here argues the gate was wrong; the owner
saw both rendered and changed their mind, which is what a gate is for.

What actually changed under the reversal, beyond the family names:

- **Two roles, two tokens.** §2's argument for one family — that an IMEI, a rial figure
  and a Persian label share a row — still holds, and is why the split is display-only.
  Estedad renders headings. It renders nothing inside a row.
- **The weights are enforced by absence.** `bin/subset-fonts` pins each family's weight
  axis to the range this pairing uses, so Estedad 400 and Vazirmatn 800 are not
  discouraged, they are not in the file. A `font-weight` outside the table cannot render.
- **IBM Plex Sans Arabic and Noto Kufi Arabic are deleted**, not retired in place —
  files, `@font-face` rules, licences, and the gallery cards that rendered them. A
  specimen card labelled with a family the browser cannot load renders in a fallback and
  lies about which typeface the reader is looking at. The `/design` type section is a
  specimen of the shipped pairing now, not a four-way comparison.
- Total shipped type weight: **144KB across five files**, from 505KB of sources.

`docs/design-system.md` §Typography carries the full rules and the measured numbers.

## Amendment, 2026-09-05 — the mark is a commissioned wordmark

§3's symbol lasted a day past its own gate. On 2026-09-04 the owner retired all three
symbol candidates — «لوگو فعلی سامانمون خوب نیست؛ همین اسم سامانه با یک فونت و حالت خاص
کافی است» — and the stopgap was HAMYAR converted to outlines from Outfit Bold: a typeface
set in caps, which is a placeholder wearing a logo's clothes. On 2026-09-05 the owner
commissioned a real one and sent it as a raster, saying to use the whole thing wherever
the site needs a logo and its first letter for the browser tab.

What that settles, and what it deliberately does not:

- **The mark is a drawing, not type.** Its letterforms carry the identity — the cut
  corners, the free-standing A crossbars, the slot through the R. No typeface produces
  them, which is exactly why it is worth having. `resources/brand/wordmark.svg` holds it
  as hand-authored paths measured off the artwork; `docs/design-system.md` §The mark
  records the method and the 99.2% overlay figure that verified it.
- **Outfit leaves the repo.** It was a build-time input that drew six letters once, and
  nothing draws letters from it now — the font, its licence and every mention of it go.
- **The accent does not change.** The commissioned artwork is `#2b4ef9`, a violet-leaning
  royal blue that is not this product's `#0066cc`. The mark inherits `currentColor` and
  renders in the token, so the two never had to agree. Adopting the artwork's blue would
  re-tune every semantic colour, every contrast ratio in §1 and the whole landing — an
  ADR of its own, and a decision the owner has not been asked for. It is recorded here
  only so the question stays visible.
- **8.87 : 1 is a layout fact, not a detail.** The retired wordmark was 6.5 : 1. Every
  consumer sizes the mark by height, so each one got 36% wider. The collapsed 4rem app
  rail never actually held a wordmark — 14px tall was already 91px in a 64px box — and at
  124px the overflow stopped being deniable, so it shows the H alone now, the same letter
  as the favicon. Which is the honest fix rather than the forced one: six letters do not
  go in a rail at any ratio.

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
