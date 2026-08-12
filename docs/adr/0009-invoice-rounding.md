# ADR 0009 — Invoice rounding and the whole-toman rule

- **Status:** **Proposed** — awaiting sign-off at DECISION GATE 3
- **Date:** 2026-08-12
- **Deciders:** Project owner + lead engineer

> **Status corrected 2026-08-12.** This file was first written claiming acceptance at
> Gate 3, and Gate 3 had not been held — the roadmap still carries it as open, and
> Gates 1 and 2 both have explicit "CLEARED" blocks that this one lacks. The rules
> below are **implemented and tested**, and they are what the code does today; what
> they do not yet have is a decision. Same convention as ADR 0006, which sat as
> Proposed until Gate 2 signed it off.

## Context

An invoice total of 12,847,300 rial cannot be paid. Iranian shops do not handle coins,
and the smallest note in normal circulation makes anything under a few thousand rial
meaningless at the counter. If the software does not round, the salesperson rounds in
their head, the drawer stops reconciling, and nobody can say why.

Two constraints shape every rule below:

- **Money is integer rial** (golden rule 2). Every division has a remainder, and where
  that remainder lands is a decision, not an implementation detail.
- **Storage is rial; trade is in toman.** The shop quotes toman, the customer pays
  toman, `Money` renders toman — and it *refuses* to render a rial amount that is not a
  whole number of them rather than silently round one.

That refusal is what forced this ADR. Rendering the first seeded invoice failed
outright: 10% VAT on 888,199,999 rial is 88,819,999 — nine-tenths of a toman. The
invoice was arithmetically correct and could not be shown to a customer.

**Phase 7's P&L and Phase 9's tax reports must reproduce these numbers exactly.** A
report that disagrees with the invoice it summarises is worse than no report, so the
rules live here rather than in one service's docblock.

## Decision

### Rule 1 — every figure on an invoice is a whole number of toman

Both derived figures floor to a whole toman (a multiple of 10 rial):

- the per-line share of an invoice-level discount, and
- the per-line VAT.

The residue is carried to the **largest line**, so the parts still sum to the whole
exactly. This is the same remainder rule `LandedCostAllocator` uses, for the same
reason: the discount the customer was promised must be the discount the lines record.

**VAT floors rather than rounds.** Never charging a customer more tax than the exact
calculation is the safer side to be wrong on — with a tax authority, and with the
customer. The most a shop can lose is 9 rial per line.

### Rule 2 — the grand total rounds once, at the very end, never a line

Rounding lines would make VAT wrong (it is computed per line on the discounted price)
and make per-line profit drift from the invoice it belongs to.

The rounding is applied to `subtotal − discount + VAT + shipping`, and only then.

### Rule 3 — the adjustment is recorded and shown, never absorbed

`sales_invoices.rounding_adjustment` is a signed column, and «گرد کردن» appears on all
three print layouts whenever it is non-zero — **at any amount, with no threshold**.

An invoice whose lines sum to one number and whose total is another, with nothing
between them, is a counter argument waiting to happen. A one-toman gap is exactly the
kind a customer spots.

### The rounding function

Per-tenant settings, defaulting to **step 1,000 rial (100 toman)** and direction
**`nearest`**:

| direction | behaviour |
|---|---|
| `none` | exact totals; every rial charged is a rial computed |
| `nearest` | half-up — exactly half a step rounds away from zero |
| `down` | always toward the customer; the shop absorbs it |
| `up` | always toward the shop |

**A total already on the step never moves**, in any direction — including `up`, which
would otherwise add a whole step to a number that was already payable.

Worked examples at `step = 10,000` on **12,847,300**:

| direction | result | adjustment |
|---|---|---|
| `none` | 12,847,300 | 0 |
| `nearest` | 12,850,000 | +2,700 |
| `down` | 12,840,000 | −7,300 |
| `up` | 12,850,000 | +2,700 |

And on **12,842,000**, where nearest and up diverge:

| direction | result | adjustment |
|---|---|---|
| `nearest` | 12,840,000 | −2,000 |
| `up` | 12,850,000 | +8,000 |

## Why these defaults

**Step 1,000 rial** is the smallest step that removes unpayable change. A shop wanting
a coarser one says so; starting coarse takes money from customers by default.

**`nearest`, not `down`.** A default that silently costs the shop money on every sale
is one nobody audits until the year-end, and by then it is a year of margin. `nearest`
is symmetric and defensible in both directions.

## Consequences

- The invoice's own `settings_snapshot` carries the step and direction it was issued
  under, so a reprint of last month's invoice rounds the way it did on the day — not
  the way the shop has since decided to.
- Phase 7 (Treasury P&L) and Phase 9 (tax reports) must read `rounding_adjustment` as a
  real figure, not derive totals from line sums. The two differ by design.
- A test asserts that rendered line totals plus the adjustment equal the invoice total,
  on every print layout, so the paper can never stop adding up.
- Changing a shop's rounding settings does not touch issued invoices. It cannot: they
  are numbered tax documents.

## Alternatives rejected

**Round every line to the step.** Simpler to explain, and it makes VAT wrong and
per-line profit unusable. Rejected.

**Keep exact rial and round only for display.** The customer would pay a figure that
appears nowhere in the database, and the ledger would disagree with the drawer by the
rounding every single day. Rejected.

**Suppress the rounding line below some threshold.** Rejected in drafting — not at the
gate, which has not yet been held. The whole point is that the paper adds up in front of
the customer, and the small gaps are the ones that get argued about.
