# Baseline — before Redesign v2 touched a pixel (2026-09-03)

Reviewed by the rewritten `design-reviewer` agent (read-only, browser tools), against
`main` @ `c78d4b2` served from the production asset path, seeded demo tenant, five screens
× five widths × both themes. The full 60-capture matrix is in `.impeccable/review/baseline/`
(gitignored); the fold captures are in `baseline/`. This is the number Phase 16 is measured
against at closure (16.6).

## Scores

| screen | /10 | hinge |
|---|---|---|
| Landing `/` | **6** | competent and real, but drifted from ADR 0016 into gradients and stacked bands, and the fold does not say what the product is |
| Dashboard | **5** | nine identical hairline cards, five of them empty on a seeded morning; the chrome's blur does not render |
| POS | **5** | right skeleton, wrong weight: the submit is the same 40px pill as «پیش‌فاکتور»; columns collapse to 296px at 1024 |
| IMEI passport | **6** | best-structured app screen; its signature number is set in the OS monospace and the page has no action |
| Sales register | **5** | mechanically right; header row, row height, badge size and the 390 table are under-designed |
| **overall** | **5** | disciplined code, assembled screens |

## What was measured

| | landing | dashboard | POS | passport | register |
|---|---|---|---|---|---|
| h1 | Estedad **800**, 68/74.8px, −0.028em | Estedad 700, 28/39.2px | same | same | same |
| body | Vazirmatn 17/31.45px (1.85) | Vazirmatn 17/28.05px (1.65) | same | same | same |
| primary button | 50px hero, 44px nav, 12px radius, navy **gradient + inset + shadow** | 40px pill, 15px/500, 10px padding-inline | 40px pill, identical to the two outline buttons beside it | **none on the page** | 40px pill |
| sidebar | — | 288px, 19 links × 44px | | | |
| content column @1440 | 1120px | 1046px inside padding | 670 / 352 | 702 / 320 | table 1044 |
| column @1024 | 976 | 672 | **296 / 352** | single (timeline 606) | 670 |
| header | 73px white sticky, no blur | 64px `rgba(255,255,255,.72)`, **`backdrop-filter: none`** | same | same | same |
| font sizes on screen | 19 (11–68px) | 6; 38 elements at 12px | 5 | 6; 22 at 12px | 5 |
| gradients / shadow recipes | **17 / 7** | 0 / 1 | 0 / 0 | 0 / 0 | 0 / 0 |
| page height @1440 / @390 | 9093 / **11002** | 1587 / 2335 | 1119 / 1546 | 1024 / 1449 | 900 / 900 |
| console | **2 errors** (CSP: inline skip-link handlers) | 0 | 0 | 0 | 0 |
| document overflow | none | none | none | none | none |

Fonts loaded: landing Vazirmatn 400/500/700 + Estedad 700/800; app Vazirmatn 400/500/600 +
Estedad 600/700. Dark mode is a clean variable swap on every app screen.

## Critical findings (the fix list Phase 16 inherits)

1. **`.glass` has no blur in the served build.** `app.css:486-491` declares
   `backdrop-filter` and a manual `-webkit-` twin; the built asset keeps only the prefixed
   one and Chromium computes `none`. The one piece of depth the system allows itself is
   absent. → drop the manual prefix line, add a build-output guard (16.4).
2. **Every product surface is the same object** — a 1px hairline box at 18px on a flat white
   ground; `canvas-alt` is never used inside the shell; dashboard 9 boxes + 1 banner, POS 9
   hand-rolled boxes and 0 `<Card>`. Nothing is the anchor. → one anchored surface per screen,
   secondary panels borderless on the alternate ground (16.4).
3. **Primary actions do not read as primary, and hover fades them.** `button.tsx:12`
   `hover:bg-primary/80` — the most important button gets lighter under the cursor while
   `brand-hover` sits unused; no `lg` size for counter actions. → `hover:bg-brand-hover`,
   a 48px `lg`, token transitions (16.4).
4. **The landing drifted from ADR 0016 into generic SaaS hardware**: 17 gradients, 7 shadow
   recipes, five radii, a gradient-and-inset primary button with a hover lift, a glowing
   counter figure, and a full-bleed closing band that merges into the navy footer. → replaced
   wholesale in 16.3.
5. **A first-time shopkeeper reads the fold as "a ledger app, free to try".** The category
   («نرم‌افزار ابری فروشگاه موبایل») is the 14px eyebrow, the smallest type on the fold; the
   product is first seen after three screens of scrolling. → the 16.2 direction and copy gate.
6. **POS collapses at 1024** — `Pos/Index.tsx:358` splits at `lg:` (296/352), the exact trap
   rule 8 documents; the passport does it right at `xl:`. → one-line fix, first in 16.4.
7. **The landing skip link is broken by CSP** (`landing.blade.php:23-24`, inline handlers)
   — never visible on focus, two console errors per keyboard visit. → CSS-only in 16.3.
8. **The `.mesh` grid background is a generated-UI signature** (`landing.css:410`): a
   hairline line-field tiled on a fixed 46px cell, drawn over the hero and the navy band.
   Flagged by the impeccable detector, and it agrees with finding 4 — a grid overlay
   belongs on a canvas, a map, a blueprint or a measurement surface, and this page is none
   of them. Left standing deliberately rather than patched: it is part of the look 16.3
   replaces, and polishing a discarded direction is how the last two landings were built.

High-impact next: dashboard's five empty attention boxes become one line each and its local
`Card`/`Quiet` become `ui/card` + `EmptyState`; POS gets `data-density="compact"`, loses its
56px top padding and 28px title, and the submit becomes a 48px full-width action; the
passport's IMEI becomes the 40px display figure in a self-hosted tabular face with the page's
missing actions («فروش», «پذیرش تعمیر», «چاپ برچسب»); the register's header row goes muted
13px, rows to `--density-row`, and a card list under `md`; the landing at 390 (11,002px, no
navigation, screenshots cropped to 44%) is a 16.3 rebuild item, not a patch.

## The numbers to move (re-measured at 16.6)

| metric | baseline | target |
|---|---|---|
| `backdrop-filter:` present in the built app CSS | no | yes, guarded |
| surface treatments per app screen | 1 (hairline box) | ≥ 2 grounds + 1 elevated anchor |
| POS primary button height / hover | 40px / `primary/80` | 48px / `brand-hover` |
| POS columns at 1024 | 296 / 352 | stacked, or cart ≥ 400 |
| landing gradients / h1 weight | 17 / 800 | 0 / ≤ 700 |
| landing console errors (keyboard) | 2 | 0 |
| register row height | 39px | ≥ 44px (`--density-row`) |
| IMEI figure face | OS monospace | self-hosted tabular, display step |
| empty boxes on a seeded dashboard morning | 5 | 0 |
| landing height at 390 / mobile nav | 11,002px / none | ≤ 7,000px / present |
| reviewer score, overall | 5 | ≥ 8 |
