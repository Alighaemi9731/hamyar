# چک — cheques, and exactly what each event posts

**Status:** specification. Written before the code, on purpose.

This is the table the Cheques module is built against. It exists first because a posting
matrix is the kind of thing that looks right in prose and is wrong in a debit: if the code
comes first, the document becomes a description of whatever was built, and a wrong debit
acquires a paragraph explaining why it is correct.

Three independent derivations were produced — from a shop bookkeeper's framing, a
double-entry purist's, and an edge-case hunter's — and reconciled here. Where they
disagreed, the disagreement and its resolution are recorded rather than smoothed over.

Cheques are not a marginal payment method in this market. A large share of wholesale and
instalment trade is settled with post-dated paper, often endorsed onward to a supplier
instead of ever being banked. The module has to be right.

---

## 1. The central decision: a received cheque settles the debt when it is taken

**On receipt, not on clearing.** Taking a cheque credits the customer's party balance in
full and debits a cheque asset account. The shop has swapped an open claim on a person for
a dated claim on paper; it is no richer and no poorer at that instant.

All three derivations reached this independently. The arguments, in order of force:

**The alternative makes the statement lie.** `FinaliseInvoice` skips any payment whose
method is not `settlesImmediately()`, so a 100,000,000 cheque against a 100,000,000 invoice
already posts `DEBIT party 100,000,000` and nothing else. If receipt posts nothing, the
customer's statement shows them owing the full amount with no record anywhere that the shop
is holding their cheque — for four months. A shopkeeper hands that across the counter and
the customer is right to argue.

**Endorsement is otherwise impossible.** خرج کردن چک — passing the cheque to a supplier to
kill a payable — needs something to credit. If the value still sits on the customer's
balance, endorsing would have to credit *the customer* in order to debit the supplier,
which reads on their statement as "your debt was cleared because we paid our supplier".
A cheque must be its own asset before it can be spent.

**It is what the دفتر has always shown.** When the customer hands over a three-month
cheque, the bookkeeper writes two things in two books: the customer's حساب معین goes to
zero, and a line appears in the دفتر اسناد دریافتنی with the bank, the serial and the due
date. One event, two books, neither a summary of the other.

**A bounce is expressible.** Restoring the debt is a new dated event. The statement then
reads sale → cheque received → cheque bounced: three rows, each true on its own day. Under
settle-on-clearing there is nothing to bounce *from* — the customer never stopped owing, so
the most important event in the shop's month leaves no trace in the ledger at all.

### The honest objection, and the two things that must be built because of it

Settling on receipt makes the customer's balance read zero while a large amount of their
paper might yet bounce. That is true, and it is not the party balance's job to say so — it
never was on paper either. **Exposure is a second number.** It lives in the `cheques` table
and never in `ledger_entries`:

```
exposure(party) = partyBalance(party)
                + Σ face of that party's received cheques
                  where status ∈ {in_hand, deposited, spent_to_third_party}
```

`spent_to_third_party` is included because endorsement does not discharge recourse: if the
cheque bounces at the endorsee, the shop is liable and the customer is still the drawer.

Two consequences are **binding requirements on this phase**, not observations:

1. **`LedgerService::creditCheck()` must take exposure, not balance.** It currently reads
   `partyBalance()` alone (`LedgerService.php:268`). Left as it is, a customer who has paid
   entirely in post-dated cheques has a zero balance and therefore unlimited further credit
   — which is precisely the customer a credit limit exists to stop.
2. **`VoidInvoice` must refuse an invoice with a live cheque against it.**
   `VoidInvoice::reverseLedger()` reverses only batches whose `reference_type` is
   `SalesInvoice` (`VoidInvoice.php:199`). Voiding a cheque-paid invoice today would credit
   the customer the full amount and leave the cheque asset standing — the shop would owe a
   customer whose cheque is still in its drawer. The operator must return the cheque first,
   exactly as `VoidInvoice` already refuses an invoice with returns against it.

### One row, one subject

`ledger_entries` requires *at least* one subject, not exactly one. It is tempting to put
`party_id` **and** `account_id` on the cheque-asset row so the cheque "shows on the
customer's page". Do not: a row with both counts toward the party balance *and* the account
balance, so the debit cancels the credit within the same batch, the settlement silently does
not happen, and the balance looks right by accident until somebody deposits the cheque.

**Every ledger row written by this module names exactly one subject.** The customer's name
belongs in `description` and on the cheque record. This is enforced by a test.

---

## 2. Accounts this module needs

One row per tenant, created by the module's migration.

| account | type | meaning |
|---|---|---|
| `چک‌های نزد صندوق` | `cheques_receivable` | received cheques physically in the shop's drawer |
| `چک‌های در جریان وصول` | `cheques_in_collection` | received cheques lodged with a bank |
| `چک‌های برگشتی نزد ما` | `cheques_returned` | dishonoured paper the shop holds after an endorsee returned it |
| `اسناد پرداختنی` | `cheques_payable` | liability for cheques the shop has written |
| `کارمزد بانکی` | `expense` | bank charges (shared with Treasury 7.2) |

**None of these may appear in `Account::moneyHoldingTypes()`**, and none may be selectable
in the POS payment-account picker (`PaymentOptions`). An operator who *can* pick "cheques
receivable" as a cash destination eventually will, and the drawer invariant dies that day.

The invariant worth stating, because it is the only cheap way a shop ever discovers a lost
or stolen cheque: **the balance of `cheques_receivable` equals the face value of the paper
physically in the drawer.**

---

## 3. The matrix — received cheques

Face value is `F` throughout. Every posting is one batch unless stated.

| # | from | to | debit | credit | amount |
|---|---|---|---|---|---|
| R1 | — | `in_hand` | `cheques_receivable` | party (drawer) | F |
| R2 | `in_hand` | `deposited` | `cheques_in_collection` | `cheques_receivable` | F |
| R3 | `deposited` | `cleared` | bank account (the deposit destination) | `cheques_in_collection` | F |
| R3f | — | (clearing fee) | `expense` (bank charges) | bank account | fee |
| R4 | `in_hand` | `cleared` | cash **or** bank account | `cheques_receivable` | F |
| R5 | `deposited` | `bounced` | party (drawer) | `cheques_in_collection` | F |
| R5f | — | (bounce fee) | `expense` (bank charges) | bank account | fee — **separate batch** |
| R6 | `deposited` | `bounced` (partial) | bank account `p` + party (drawer) `F−p` | `cheques_in_collection` | F |
| R7 | `bounced` | `deposited` (re-present) | `cheques_in_collection` | **party (drawer)** | F or shortfall |
| R8 | `in_hand` | `spent_to_third_party` | party (endorsee) | `cheques_receivable` | F |
| R9 | `spent_to_third_party` | `settled` | — none — | — | — |
| R10 | `spent_to_third_party` | `returned_by_endorsee` | `cheques_returned` | party (endorsee) | F |
| R11 | `returned_by_endorsee` | (chase drawer) | party (drawer) | `cheques_returned` | F |
| R12 | `in_hand` | `returned` (to customer) | party (drawer) | `cheques_receivable` | F |
| R13 | `bounced` | `written_off` | `expense` (bad debt) | party (drawer) | amount |

### The rows that are easy to get wrong

**R2 — depositing posts.** It is not a status change. The tempting error is to debit the
*bank account* instead of `cheques_in_collection`: the bank will not pay until وصول, so the
account a shop uses to decide whether a transfer will clear becomes inflated by paper the
bank has not honoured, reconciliation can never tie out, and a bounce would have to credit a
bank account for money it never held. The opposite error — posting nothing — leaves a cheque
that is physically at the bank indistinguishable from one in the drawer, which is the
difference between a cheque you can still endorse and one you cannot.

**R3 vs R5f — why the clearing fee is folded and the bounce fee is not.** The derivations
disagreed here. Resolved by asking what the bank statement shows, since that is what
reconciliation ties against: a collection fee is netted into the same credit line, so it is
folded into the clearing batch; a returned-item charge appears as its own debit on its own
day, so it is its own batch. It also gives the right reversal granularity — a shop that
disputes a bounce fee can reverse the fee without touching the restoration of the debt.

**R4 — cashing at the drawee branch is legal.** The shopkeeper walks the cheque to the
issuing bank and is paid across the counter. Routing this through `cheques_in_collection`
for symmetry would make the deposit-slip report show a deposit no bank ever received. If
this path is forbidden, operators fake a same-day deposit-and-clear, which corrupts the
in-collection account anyway.

**R5 — a bounce is a new event, never a reversal.** Calling `LedgerService::reverse()` on
the receipt batch produces the right numbers on the wrong subject: it credits
`cheques_receivable`, but the cheque is in *collection*, not in hand. The receivable account
goes permanently negative, the collection account is stuck holding a cheque that no longer
exists, and neither can be reconciled again. It must also be **one batch** — restoring the
debt and clearing the collection account in separate batches means that, in between, the
books claim the shop both holds a good asset and is owed nothing, and a crash leaves it
that way.

**R6 — partial payment is one batch of three lines.** Under Iranian practice the bank pays
what is in the account and issues a گواهینامه عدم پرداخت for the rest. Modelling this as a
full bounce plus a separate cash receipt dates the recovery wrongly and invents a payment
the customer never made. `0 < p < F`, and both are whole toman, so the shortfall is too.

**R7 — re-presentation does NOT post the same lines as R2.** This is the row most specs
miss, and it was caught by two derivations independently. After a bounce, `cheques_receivable`
holds nothing for this cheque — the bounce credited *collection* and debited the *party*. The
value now sits on the customer, so the customer is what must be credited. Copying R2's lines
would drive the drawer account negative by the face value and destroy the drawer invariant
permanently. Nor may a bounce be "un-done" by reversing its batch: that would also reverse
the fee and erase the fact that it ever bounced.

**R8 — endorsing debits the supplier.** Because a positive party balance means the party
owes the shop, debiting the endorsee moves what the shop owes them toward zero. If the
cheque is larger than the payable, the supplier's balance goes positive — they owe the shop
change — which is the truth and must not be clamped. Crediting the customer again here is
the classic double-settlement: it gifts them the face value.

**R9 — after endorsement, nothing.** The cheque clearing in the endorsee's bank is an event
between two other parties. There is nothing to close out; the endorsement already zeroed
this cheque's contribution to every account. Any posting here invents value.

**R10/R11 are two batches on purpose.** The endorsee tells the shop the cheque bounced
(R10); the paper comes back later, or never (R11). The liability revives on *notification*,
not on delivery. Between the two the books read: a dishonoured instrument claimable, and a
payable to the supplier — which is exactly the shop's position. R11 is refused if R10 has
not been posted, or `cheques_returned` goes negative.

---

## 4. The matrix — issued cheques

| # | from | to | debit | credit | amount |
|---|---|---|---|---|---|
| I1 | — | `issued` | party (payee) | `cheques_payable` | F |
| I2 | `issued` | `presented` | — none — | — | — |
| I3 | `issued`/`presented` | `cleared` | `cheques_payable` | drawn-on bank account | F |
| I3f | — | (fee) | `expense` (bank charges) | drawn-on bank account | fee — same batch |
| I4 | `issued`/`presented` | `bounced` | `cheques_payable` | party (payee) | F |
| I4f | — | (returned-item charge) | `expense` (bank charges) | drawn-on bank account | fee — same batch |
| I5 | `bounced` | `settled_in_cash` | party (payee) | cash or bank account | amount |
| I6 | `bounced` | `replaced` | — none on this cheque — | (new cheque posts I1) | — |
| I7 | `issued` | `cancelled` | `cheques_payable` | party (payee) | F |

**I2 posts nothing, and the asymmetry with R2 is deliberate.** An implementer will read it
as an inconsistency, so it is recorded as considered: on the received side, the in-collection
account buys a real invariant (the drawer count). On the issued side there is no equivalent
to buy — the shop's obligation is the same size whether or not the payee has banked the
cheque, the money is still sitting in the bank account and still mis-spendable, and no report
distinguishes the two states. Clearing and bouncing are both reachable directly from
`issued`, so a shop that never records presentation is fully supported.

**I4 is the most serious event in the module.** The shop's own cheque was dishonoured.
It raises a high-severity alert and fires `ChequeBounced`. Where the payee has endorsed the
cheque onward and a different holder presents the claim, that holder must be recorded
explicitly and credited instead of the original payee.

---

## 5. Illegal transitions

| attempt | why it is refused |
|---|---|
| `in_hand → bounced` | An unpresented cheque cannot bounce. A teller verbally refusing to pay is a note, not a state change: the paper is still in the drawer and the ledger already says so. |
| `deposited → spent_to_third_party` | The bank holds the paper. There is nothing to hand over; wait for clearing or bouncing. |
| `bounced → spent_to_third_party` | Endorsing paper known to be dishonoured is not a data-entry mistake, it is passing a bad cheque onward. The software must not be the tool that does it. Refused with its own message. |
| `bounced → cleared` | A clear must always have a dated deposit behind it, or bank reconciliation shows a receipt with no presentation. Go through R7. |
| `deposited → returned` | The shop does not hold the cheque and cannot hand it back. |
| any transition on a `cancelled` or `written_off` cheque | Terminal. Correct forward, never by editing. |
| endorsing to the drawer | That is `returned` (R12), a different transition with different lines. |

Depositing to the **wrong bank account** is a correction, not a transition: reverse the
deposit batch and re-post against the right account. The status stays `deposited`.

---

## 6. Replay and idempotency

Every transition runs inside a transaction that takes `SELECT … FOR UPDATE` on the cheque
row and **re-reads the status under the lock** — the same shape as the delivery race fixed
in Phase 6, and for the same reason: two operators in two tabs both hold a stale model, both
pass a guard read from it, and both post.

The status check under the lock *is* the idempotency key. A repeat is refused with a Persian
message naming the cheque, never silently ignored and never posted twice.

Two further guards:

- **Duplicate paper.** A unique partial index on `(tenant_id, direction, sayad_id)` where
  `sayad_id is not null and status <> 'cancelled'`, plus a soft warning on
  `(tenant_id, direction, bank_name, serial, amount)`. Entering the same physical cheque
  twice credits the customer twice and is discovered months later, when a cheque nobody holds
  fails to clear.
- **Retried listeners.** A cheque created from `InvoiceFinalised` is keyed on the
  `invoice_payment` row id, so a retried job cannot mint a second asset.

Each transition records its `batch_id` on the cheque's history row, so a statement can show
every attempt and a correction can find every row it must undo.

---

## 7. Dates

`occurred_at` is the date the **bank** says the thing happened, entered as Jalali — never
`now()`. A cheque cleared on the 5th and keyed on the 7th posts on the 5th, or the daily
close for the 5th is wrong forever.

`due_date` is a property of the paper and drives the reminder list. It is never the posting
date: a post-dated cheque is received, and posts, on the day it is handed over.

Clearing before the due date is allowed with a warning — a صیادی cheque is legally payable
on sight and banks do pay early.

---

## 8. Rounding

ADR 0009 barely applies here, and saying why matters. **A cheque's face value is a fact
about a piece of paper, never a derived figure**, so the floor-and-carry rule has nothing to
divide. No rounding ever happens inside a cheque posting.

The one binding rule is the whole-toman multiple: `amount > 0 and amount % 10 == 0`,
validated in the FormRequest *and* the service, because `Money::toArray()` refuses to render
a rial figure that is not a whole number of toman — and it would refuse it at print time,
with the customer watching. The only derived figure in the module is the shortfall on a
partial payment, and it is whole toman by construction.

A cheque whose face exceeds the invoice leaves a real credit balance on the party. It is
never a rounding adjustment: ADR 0009 rounds an invoice total, once, and nothing else.

---

## 9. Worked example — a full bounce-and-recover cycle

Hasan buys a phone for **450,000,000 rial** and pays with a three-month cheque. It bounces,
the bank charges **300,000**, he re-presents, and it clears.

| # | event | debit | credit | party balance after | statement line |
|---|---|---|---|---|---|
| 1 | invoice finalised | party Hasan 450,000,000 | sales 450,000,000 | **450,000,000** | فاکتور INV-000042 |
| 2 | cheque taken (R1) | `cheques_receivable` 450,000,000 | party Hasan 450,000,000 | **0** | بابت چک ۴۴۵۶۷۸ بانک ملت سررسید ۱۴۰۵/۰۸/۲۲ |
| 3 | deposited (R2) | `cheques_in_collection` 450,000,000 | `cheques_receivable` 450,000,000 | 0 | — (no party line) |
| 4 | bounced (R5) | party Hasan 450,000,000 | `cheques_in_collection` 450,000,000 | **450,000,000** | برگشت چک ۴۴۵۶۷۸ |
| 5 | bank fee (R5f) | `expense` 300,000 | bank 300,000 | 450,000,000 | — |
| 6 | re-presented (R7) | `cheques_in_collection` 450,000,000 | party Hasan 450,000,000 | **0** | ارائه مجدد چک ۴۴۵۶۷۸ (نوبت ۲) |
| 7 | cleared (R3) | bank 450,000,000 | `cheques_in_collection` 450,000,000 | 0 | — |

Read the statement column downward: Hasan owed, then did not, then did again, then did not.
Four rows, each true on its day, and the fact that his cheque once bounced is permanently
legible. Nothing was ever edited or erased.

Check the accounts at the end: `cheques_receivable` 0, `cheques_in_collection` 0, bank
+449,700,000, expense 300,000, party 0, sales 450,000,000. Assets less the fee equal
revenue. The books close.

---

## 10. What this module must not do

- Never debit cash or bank on receipt. Paper is not money until a bank says so.
- Never credit sales on a cheque event. The revenue was recognised by the invoice;
  recognising it again doubles the month's turnover.
- Never write a ledger row naming both a party and an account.
- Never reverse a batch to express a *new* fact. Reversal is for correcting a mistake; a
  bounce, a return and an endorsement are all things that genuinely happened.
- Never infer a status from a date passing. A due date arriving does not clear a cheque,
  and a spent cheque is never auto-settled — somebody has to be told.

---

## Acceptance

- **Registration is the only door, and it is one transaction.** `POST /cheques` →
  `RegisterCheque` creates the row, spends the credit and posts R1 (received) or I1 (issued)
  together. A cheque that exists without its opening posting is counted by `ChequeExposure`
  toward a customer's credit while the ledger does not know it — the two answers a shop gets
  about the same customer then disagree, which is worse than no cheque at all.
- **Quota.** Registering a cheque spends one `cheques.cheques` credit, in the transaction
  that writes it. Every later event in the cheque's life — deposit, bounce, endorsement,
  settlement — is **free**: the credit buys the cheque, not each thing that happens to it,
  and a shop must never be unable to record a bounce (ADR 0018).
