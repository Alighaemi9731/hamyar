# 0019 — The UI redesign's directions

**Status:** accepted · **Date:** 2026-09-03 · **Phases:** 0–15 of the redesign programme (`#84`–`#127`)

## Context

The design system was finished before most pages were written to it: a measured token layer,
27 domain components, an ADR behind every visual decision — and 59 of 77 page files with one
commit, written in a single pass before the system matured. The programme's job was
propagation, not invention. This ADR records the directions that emerged from doing it, so the
next page is built to them rather than rediscovering them.

## Decisions

1. **Page families, not one template.** Command centre, counter, document, ledger, register,
   passport, editor, analysis, configuration, public, paper. Each has a personality and a
   signature; the shared language is tokens, components and rhythm, not sameness.

2. **The screen and the document are two things.** A report, a receipt, a Z report is a
   document that has to keep printing exactly as it does. A screen built for a monitor is a
   different artefact with a stat band, a `DataTable`, a totals row. The document moves into
   its own component **unchanged** — verified by diffing the rendered DOM against `main`, not
   by reading — and `useReportView` shows it before printing it, so nothing prints unseen.

3. **Multi-column bands split at `xl`, never `lg`.** The sidebar arrives at `lg`, so the
   content column is *narrower* at 1024 than at 768. Measured three times; the third case did
   not overflow — it collapsed a timeline from 704px to 328px with nothing looking broken.

4. **40px is the floor, and the label is the target.** No control under 40px outside
   `/design`'s size specimen. A checkbox stays a 20px mark inside a 40px row that is all hit
   area; an unlabelled one carries a transparent `::before` and says so in
   `data-hit-area="expanded"`, because a pseudo-element is invisible to anything that
   measures targets.

5. **Radix is RTL from the root**, and an icon that names a physical direction is never
   mirrored. Both encoded above in the design system; the second is gated.

6. **Money aligns on the units digit.** `text-end` in an RTL table is the physical left and
   scatters a column across as much as 65px; `DataTable`'s `numeric` is the physical right.
   Ladders use a fixed `9ch` track so two ladders on one rail share an axis. A unit never sits
   inline on a rung.

7. **A refusal always has somewhere to appear.** `<FormErrors>` takes the whole bag;
   `handled` is a promise that a key has an inline home, and the promise is only kept when the
   inline element exists — one draft made the promise and rendered nothing. The baseline
   ratchets down and cannot grow.

8. **Evidence is a number, not a look.** Every claim in this programme that turned out wrong
   — a report "sound" from reading, a hit area "broken" from a probe that clicked off-screen, a
   variant "doing nothing" from reading `transform` instead of `rotate` — was corrected by a
   measurement. Sweeps run with data, at six widths, in both themes, and read the console,
   because a CSP refusal and a 500 both look fine from PHP.

## Consequences

- New pages assemble `PageHeader` + `FilterBar` + `DataTable` + `Pagination` (register),
  `Card` + `MoneyLadder` (document/ledger), or the sheet/screen pair (analysis). Inventing a
  surface is the exception and needs a reason in the file.
- The nine guards are the floor of review, not its ceiling; each exists because its shape
  shipped at least once and the story is in `docs/lessons.md`.
- Two things are left open on purpose and belong to the owner: the signed public invoice link
  (a tenancy/security decision, `#120`) and the landing's signature element and ink
  (`#125`). Undefined invoice line order (`#99`) is a one-line product decision.
