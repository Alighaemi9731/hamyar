# ADR 0009 — Invoice rounding and the whole-toman rule

- **Status:** **Accepted** at DECISION GATE 3, 2026-08-12
- **Date:** 2026-08-12
- **Deciders:** Project owner + lead engineer
- **Approved by:** [DECISION GATE 3 — CLEARED 2026-08-12](../ROADMAP.md): “**ADR 0009 approved as written**”. See the note below on this file's earlier, incorrect claim of acceptance.

> **A note on this file's history.** It was first written claiming acceptance at a gate
> that had not yet been held, corrected to Proposed when that was spotted, and is now
> Accepted because the gate actually happened on 2026-08-12 and approved the defaults
> below unchanged. Recorded rather than tidied away: an ADR's only value is that a later
> reader can trust what it says was agreed, and that trust is worth more than a clean
> history.

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

## Amendment (2026-08-15) — the direction rule, stated once for the whole family

Rule 1 says VAT floors, and gives the reason: *"never charging a customer more tax than
the exact calculation is the safer side to be wrong on."* Phase 9 turned up a second
figure that rounds — a derived **unit cost** — and it rounds the other way. Both are the
same principle, and writing it down once stops the next derived figure from being argued
from scratch or, worse, rounded whichever way the language happens to.

> **Every rounding of a derived figure goes in the direction that does not flatter the
> party doing the rounding.** The shop must never round toward more revenue, more margin,
> or more tax charged.

That single sentence produces every direction already in the codebase:

| Figure | Direction | Because rounding the other way would… |
|---|---|---|
| Per-line VAT (Rule 1) | floor | charge the customer tax that was not owed |
| `Money::percent()` | truncate toward zero | over-charge on any percentage the shop applies |
| Derived unit cost (`Money::ceilToToman()`) | **ceil, away from zero** | understate cost, and so **overstate profit** |
| Grand total (Rule 2) | per-tenant, default `nearest` | be a policy choice, not an arithmetic one — the adjustment is disclosed either way (Rule 3) |

The cost case is the one that needed saying. `StockLedger::weightedAverageCost()` is a
division and rarely lands on a whole toman; a hundred chargers at 50,000 and ten at 90,000
average 53,636 rial. `Money::toToman()` refuses a sub-toman remainder rather than rounding
it, so the figure has to be normalised where it is *derived* — not where it is displayed,
which is a report the shop opens every morning. It rounds **up**, at most nine rial, on the
reasoning that **an understated cost never flatters the margin**. Profit reports are read
as evidence for buying decisions; a systematic tenth-of-a-toman-per-unit lean toward
"better than it was" is precisely the error nobody audits.

Note the asymmetry is deliberate and not a contradiction: VAT rounds down and cost rounds
up because *both* move the reported figure away from the flattering side. A rule of "always
floor" would have got the cost case backwards.

**This binds the rest of Phase 9.** The VAT summary report reproduces invoice figures; it
does not recompute them. It sums the per-line VAT that was *floored and stored* at issue,
under that invoice's own `settings_snapshot`, and reads `rounding_adjustment` as a real
figure (see Consequences). Re-deriving VAT from a period's revenue total at the current
rate would round once over a month instead of once per line, disagree with every invoice
it summarises, and — because the difference accrues in the shop's favour — disagree in the
direction a tax authority notices.

## Alternatives rejected

**Round every line to the step.** Simpler to explain, and it makes VAT wrong and
per-line profit unusable. Rejected.

**Keep exact rial and round only for display.** The customer would pay a figure that
appears nowhere in the database, and the ledger would disagree with the drawer by the
rounding every single day. Rejected.

**Suppress the rounding line below some threshold.** Rejected in drafting and again at
the gate: the whole point is that the paper adds up in front of the customer, and the
small gaps are the ones that get argued about.
