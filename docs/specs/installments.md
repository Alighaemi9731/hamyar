# Installments (اقساط)

**Phase 5** (creation) · **Phase 7** (collection) · Module `app/Modules/Installments`

## Purpose

Selling a phone on a payment plan, and getting the money in. Iranian phone shops run
substantial installment books, often with a guarantor, and the arithmetic has to be
exact: the printed contract is what the customer holds you to.

## Data

- `installment_plans` — `sales_invoice_id`, `party_id`, `guarantor_party_id`
  (nullable), `principal` (invoice total − down payment), `down_payment`,
  `profit_percent`, `profit_amount`, `total_payable`, `count`, `interval`
  (`monthly` · `weekly` · `custom_days`), `first_due_at`, `status`
  (`active` · `settled` · `defaulted`), `late_fee_policy` (JSON).
- `installments` — `plan_id`, `sequence`, `due_at`, `amount`, `paid_amount`,
  `paid_at`, `late_fee`, `status` (`pending` · `due_soon` · `overdue` · `paid`).
- `installment_payments` — `installment_id`, `account_id`, `method`, `amount`,
  `received_at`, `cheque_id` (nullable).

## Behaviour

### Schedule generation

```
principal     = invoice_total − down_payment
profit_amount = principal × profit_percent ÷ 100        (integer rial, truncated)
total_payable = principal + profit_amount
rows          = Money::split(total_payable, count)      (remainder on the LAST row)
```

Flat profit, not reducing-balance — that is how these contracts are written in the
market, and pretending otherwise would produce numbers the customer does not
recognise.

Due dates step by the interval from `first_due_at`, which is picked in Jalali. Month
stepping clamps to the end of a short month: a plan starting on Esfand 30 falls on the
last day of any shorter month rather than rolling into the next one.

**The schedule always sums to exactly `total_payable`.** This is asserted for every
combination the tests can generate.

### Collection

A due/overdue cartable. Collecting accepts any payment method (including a cheque,
which creates a [cheque](cheques.md) record) and posts to the party ledger and the
receiving account.

Partial payment is allowed: the row stays open with a reduced balance.

### Late fees

Policy per plan: fixed amount or a daily percentage, with a grace period. Applied when
the row becomes overdue, capped by a tenant setting. Recorded as a separate `late_fee`
so the principal and profit stay legible.

### Early settlement

The customer pays off the remainder. Unearned profit on **future** rows is discounted
by the tenant's rule (default: full discount of unaccrued profit, which is the fairest
and the easiest to explain at the counter). Recalculation is shown before it is
committed — the customer is standing there.

### Contract print

A printable contract with the schedule table in Jalali dates, both parties, the
guarantor, and the terms text from [settings.md](settings.md).

## Screens

Plan wizard inside the POS · plan detail with the schedule · collection cartable
(due today, due this week, overdue) · collect form · early-settlement calculator ·
contract print · installments book report.

## Events

Emits: `InstallmentPlanCreated`, `InstallmentCollected`, `InstallmentOverdue`,
`PlanSettledEarly`, `PlanDefaulted`.

Listens: `InstallmentPlanRequested` (Sales).

## Acceptance

- Generated schedules sum to exactly `total_payable`, remainder on the last row, for
  every tested combination of principal, count and profit percentage.
- Month stepping clamps correctly across Esfand and short months.
- Collection posts to the party ledger and the account, and the balances tie out.
- Partial payment leaves the row open with the right remaining balance.
- Late fee applies after the grace period, respects the cap, and is recorded
  separately.
- Early settlement discounts only unaccrued profit and the preview matches what is
  committed.
- SMS nudges fire at T-3, T-0 and overdue, once each
  ([messaging.md](messaging.md)).
- Cross-tenant isolation on every endpoint.
- **Quota.** Opening an installment plan spends one `installments.plans` credit in the
  transaction that writes the schedule. Recording an installment *payment* is free — a shop
  that has hit its cap must still be able to take money it is owed (ADR 0018).

## Out of scope

Credit scoring. Reducing-balance interest. Integration with bank loan products.
