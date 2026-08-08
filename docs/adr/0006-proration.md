# ADR 0006 — Subscription proration

- **Status:** **Accepted** at DECISION GATE 2, unchanged
- **Date:** 2026-08-07 (accepted 2026-08-08)
- **Deciders:** Project owner + lead engineer

## Context

A shop on the Basic plan upgrades to Professional eleven days into a monthly period.
What do we charge?

There are three defensible answers and they differ by real money, so the rule has to be
written down once and computed the same way everywhere — the invoice, the preview
screen and the Filament panel must agree to the rial.

Two constraints shape it:

- **Money is integer rial** (golden rule 2). Any division has a remainder, and where
  that remainder lands is a decision, not an implementation detail.
- **Iranian shops are price-sensitive and inflation is high.** A rule that looks like
  it took money the customer did not expect costs far more in trust than the few
  thousand rial it gains.

## Decision

### Upgrade (more expensive plan, mid-period)

```
period_days     = days in the current billing period
remaining_days  = whole days from today to current_period_end   (floor, never negative)

unused_credit   = intdiv(current_plan_price × remaining_days, period_days)
new_charge      = intdiv(new_plan_price     × remaining_days, period_days)

amount_due      = max(0, new_charge − unused_credit)
```

The period end **does not move**. The shop pays the difference for the days it has
left, and its renewal date stays where it was — a changed renewal date is the single
most common billing support ticket in subscription products.

`intdiv` truncates, which rounds **in the customer's favour** on both terms: they are
credited slightly less than a real fraction, but charged slightly less too, and
`new_charge` is the larger number so the net truncation favours them.

### Downgrade (cheaper plan, mid-period)

The change takes effect **at the end of the current period**, not immediately. The
shop keeps what it paid for. No credit is issued, no refund is made, and nothing is
clawed back.

The alternative — switching immediately and issuing credit — means taking away
features the customer already paid for, which is indefensible.

### Credit, never refunds

Where a credit does arise (an admin-initiated plan change, a service failure), it is
stored on `subscriptions.credit_balance` in rial and applied to the next invoice
before payment. **We never refund to the gateway.** Iranian gateway refunds are slow,
manual and error-prone; a credit is instant and visible.

### Add-ons

Bought mid-period, an add-on is prorated with the same formula on its own monthly
price. Removed, it stops at period end like a downgrade.

### Trials

A shop upgrading during its trial pays nothing now. The trial end date is unchanged
and the new plan's price applies from the first real period. Charging during a trial
that was advertised as free is a promise broken.

## Alternatives considered

**Reset the period on upgrade** ("you're now on Professional, your month starts
today"). Simpler to compute, and it is what several competitors do. Rejected: the
shop silently loses the days it already paid for on the old plan, and the renewal date
moves. Both generate support tickets, and the second one generates angry ones.

**Refund the unused portion to the card on downgrade.** Rejected on operational
grounds — see "credit, never refunds".

**Per-second or per-hour proration.** More "correct" and completely unexplainable to a
shop owner. Whole days match how the customer thinks about a monthly subscription.

**Round the remainder up.** Rejected: it takes money the customer did not expect,
which is exactly the class of surprise that erodes trust in a billing system.

**Round half-up to the nearest 1,000 rial**, so every line reads as a round 100-toman
figure. Raised and rejected at Gate 2. It reads better on a Persian invoice, and the
exposure is at most 499 rial, but it reintroduces the possibility of rounding *against*
the customer for a purely cosmetic gain. Truncation stands.

## Consequences

- **Positive.** One formula, integer-only, computable in a test with exact expected
  values. The preview screen and the invoice cannot disagree.
- **Positive.** The renewal date is stable across plan changes.
- **Negative.** Downgrades mean the shop keeps a more expensive plan until period end,
  so support will occasionally be asked to make an exception. That is a deliberate
  trade: a human override on request beats an automatic clawback.
- **Negative.** Truncation means we systematically collect a few rial less than exact
  arithmetic. At Iranian price points this is immaterial and is the correct direction
  to be wrong in.
- **Binding once accepted.** `ProrationCalculator` is the only place this arithmetic
  lives; any screen that shows a proration figure calls it rather than recomputing.
