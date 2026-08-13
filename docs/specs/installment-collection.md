# اقساط — collecting instalments, late fees, and settling early

**Status:** specification. Written before the code, like `cheques.md`, and for the same
reason: these are formulas where a plausible-looking mistake produces a number a customer
will dispute at the counter.

The schedule generator already exists and this document does not contradict it. Read
`InstallmentScheduler.php` first: profit is a **flat markup** on the financed amount
(«۲۰٪ سود روی ۶ قسط»), every row floors to a whole toman, and **the last row absorbs the
remainder** so the parts sum to the whole exactly.

Everything below inherits ADR 0009: every figure a customer sees is a whole number of toman,
`Money::toArray()` refuses anything else, and derived figures floor rather than round.

---

## 1. What an instalment row is made of

A row has one `amount`. For collection it must be understood as two parts, because early
settlement rebates one of them and not the other:

```
row.amount = principal_part + profit_part
```

Neither is stored. Both are derived, and the derivation must sum back to the row exactly:

```
profit_part(row) = floor_toman(plan.profit_amount × row.amount ÷ plan.total_payable)
principal_part(row) = row.amount − profit_part(row)
```

**Why derive rather than store.** Two stored columns that must sum to a third is an
invariant nobody checks until it breaks. Deriving means the split is always consistent with
the row a customer signed for, and `principal_part` is defined as the remainder so the two
parts sum exactly by construction — the same trick the scheduler uses for the last row.

**The residue rule.** Flooring every `profit_part` means their sum can fall a few toman
short of `plan.profit_amount`. The shortfall lands on the **last row's** profit part, which
is computed as `plan.profit_amount − Σ(profit_part of earlier rows)`. Same rule, same
place, same reason as the scheduler: the row still in the future is the one that should
carry the arithmetic.

---

## 2. Late fees

### The policy

| setting | default | meaning |
|---|---|---|
| `late_fee_percent_per_month` | `0` (off) | flat monthly rate on the **overdue row**, not the whole plan |
| `late_fee_grace_days` | `5` | no fee at all before this many days late |
| `late_fee_cap_percent` | `20` | the fee stops growing at this share of the row |

**Off by default, deliberately.** A shop that has not decided its late-fee policy must not
have one applied on its behalf, and a fee appearing on a customer's account that the owner
never configured is the kind of surprise that ends a relationship.

### The formula

Per-day accrual, computed from the row and never compounded:

```
days_late  = max(0, floor(days between due_at and settlement date) − grace_days)
daily_rate = percent_per_month ÷ 30          (kept as integers: see below)
raw_fee    = row.amount × percent_per_month × days_late ÷ (100 × 30)
cap        = floor_toman(row.amount × cap_percent ÷ 100)
fee        = min(floor_toman(raw_fee), cap)
```

All integer arithmetic in rial. `floor_toman(x) = x − (x mod 10)`. The multiplication is
performed **before** any division, so the intermediate never loses precision — the classic
error here is computing a daily rate first, flooring it, and multiplying by the day count,
which under-charges by up to nine rial per day and produces a fee that does not match a
hand calculation.

### Compounding: never

Interest on unpaid interest is ربا in the reading most of this market follows, and a shop
that charges it will be told so by a customer. The fee is computed on **`row.amount`**,
which is fixed at contract time, and never on `row.amount + accrued_fee`. An unpaid fee
never grows a second fee.

This is also why the fee is not a rate on an outstanding *balance*: it is a per-row charge
on a specific late payment, and it stops at the cap.

### Worked example

A row of **5,000,000 rial**, 22 days late, at 2%/month, 5 days grace, 20% cap.

```
days_late = 22 − 5 = 17
raw_fee   = 5,000,000 × 2 × 17 ÷ (100 × 30) = 170,000,000 ÷ 3,000 = 56,666 (integer division)
floored   = 56,660
cap       = floor_toman(5,000,000 × 20 ÷ 100) = 1,000,000
fee       = 56,660
```

Same row 400 days late: `raw_fee` = 1,316,660 → capped at **1,000,000**.

---

## 3. Early settlement

The customer walks in and pays off everything remaining today. The flat profit was quoted
for the full term; settling in month two of six means the shop held the money for a third
of the time it priced.

### The rule: rebate the profit on the unelapsed term, pro rata by instalment

```
remaining_rows      = rows with status ∈ {pending, overdue}
unrebated_profit    = Σ profit_part(row) for remaining_rows
elapsed_fraction    = (count of rows already due) ÷ plan.installment_count
rebate              = floor_toman(unrebated_profit × (1 − elapsed_fraction))
                    = floor_toman(unrebated_profit × unelapsed_count ÷ installment_count)
settlement_amount   = Σ principal_part(remaining_rows)
                    + (unrebated_profit − rebate)
                    + Σ outstanding late fees already accrued
```

**Why pro rata by instalment count rather than by days.** The customer was quoted a
per-instalment figure, not a rate. A day-count rebate produces a settlement figure that
changes if they come in on Tuesday instead of Monday, which is impossible to quote over the
phone and reads as the shop making the number up. Counting instalments is what «سه قسط
مونده» means to both sides of the counter.

**Why the rebate is not the whole remaining profit.** The shop financed the purchase and
carried the risk for the elapsed term; the customer keeps the benefit of the instalments
they already had. Rebating everything would mean a customer who pays off in month five gets
the same profit treatment as one who pays in month one.

**Late fees are never rebated.** They are a charge for a breach that happened; settling
early does not un-happen it.

### Worked example

Financed **60,000,000 rial**, 20% flat profit, 6 monthly instalments.

```
profit_amount = floor_toman(60,000,000 × 20 ÷ 100) = 12,000,000
total_payable = 72,000,000
each row      = floor_toman(72,000,000 ÷ 6) = 12,000,000   (divides exactly)
profit_part   = floor_toman(12,000,000 × 12,000,000 ÷ 72,000,000) = 2,000,000 per row
principal_part = 10,000,000 per row
```

Two rows paid, four remaining, customer settles today:

```
unrebated_profit  = 4 × 2,000,000 = 8,000,000
unelapsed_count   = 4
rebate            = floor_toman(8,000,000 × 4 ÷ 6) = floor_toman(5,333,333) = 5,333,330
settlement_amount = (4 × 10,000,000) + (8,000,000 − 5,333,330) + 0
                  = 40,000,000 + 2,666,670
                  = 42,666,670 rial   (4,266,667 toman)
```

Against the 48,000,000 that would have been collected over the remaining four months, the
customer saves **5,333,330** and the shop keeps 2,666,670 of profit for the term it
actually financed. Every figure is a whole toman.

---

## 4. Partial payment against a row

A customer pays less than the row. **Order of settlement: late fee first, then profit, then
principal.**

Fee first because it is the charge most likely to be disputed later, and settling it first
means a customer who pays *something* has cleared it — there is no running fee balance to
argue about. Profit before principal because the shop's earnings should not be the last
thing collected from a customer who is already struggling; it is also the order every
Iranian lender uses, so it matches expectation.

A row with any unpaid remainder stays `overdue` (or `pending` if not yet due). It does not
become a new row: the schedule the customer signed is not rewritten by how they pay.

---

## 5. Ledger postings

Party balance convention: **positive means the party owes the shop**, so debiting a customer
increases their debt.

| event | debit | credit | amount |
|---|---|---|---|
| L1 collection | cash / bank / POS account | party (customer) | amount received |
| L2 late fee charged | party (customer) | `income` (late fee) | fee |
| L3 early-settlement rebate | `income` (financing profit) | party (customer) | rebate |
| L4 write-off | `expense` (bad debt) | party (customer) | amount |

**L1 is the only one that touches money.** The others move value between the customer and an
income heading; no cash changes hands.

**L2 is posted when the fee is charged, not as it accrues.** A fee that accrues silently in
the ledger every night would write thousands of rows nobody reads and would make the party
balance change without an event. Accrual is a *calculation* — `days_late` against the clock
— and it becomes a ledger fact at the moment somebody collects or a statement is issued.

**L3 credits the customer because the rebate reduces what they owe.** The debit lands on the
income account the profit was originally recognised into, which is what makes a P&L for the
month show financing income net of rebates given.

**No posting is made when a plan is created.** The invoice already debited the customer for
the whole `total_payable`; the schedule is a promise about *when*, not an additional debt.
Posting again at plan creation would double the customer's balance — and it is the single
most tempting wrong entry in this module, because a schedule feels like it ought to be
recorded somewhere.

---

## 6. Rounding, and the proof that it holds

Three invariants, each of which is a test:

1. **The schedule sums to the total.** `Σ row.amount == plan.total_payable`. Guaranteed by
   the scheduler's last-row rule.
2. **The split sums to the row.** `principal_part + profit_part == row.amount`, for every
   row, by construction — `principal_part` is defined as the remainder.
3. **The profit parts sum to the plan profit.** `Σ profit_part == plan.profit_amount`, via
   the same last-row residue rule.

And every figure a customer sees — row amount, fee, settlement quote — is a whole number of
toman, so `Money::toArray()` can render it. A figure that cannot be rendered cannot be
printed on the contract, and the failure happens with the customer watching.

---

## 7. What this must not do

- Never compound a late fee. The fee is on `row.amount`, always.
- Never rebate a late fee on early settlement.
- Never post a ledger entry when a plan is created.
- Never recompute a past row's amount. The schedule is what the customer signed; a change
  in settings, profit rate or rounding step does not reach back into an issued contract —
  the same rule `settings_snapshot` enforces for invoices.
- Never quote a settlement figure that depends on the day of the week it is asked for.
