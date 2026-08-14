# Treasury

**Phase 7** · Module `app/Modules/Treasury`

## Purpose

Where the money physically is, and what it did. Cash boxes, bank accounts, POS
terminal accounts and petty cash; expenses and incomes; and the shop's own P&L.

## Data

- `accounts` — `name`, `type` (`cash` · `bank` · `pos_terminal` · `petty_cash`),
  `branch_id`, `opening_balance`, `bank_name`, `account_number`, `iban`,
  `terminal_id`, `is_active`.
- `account_transfers` — `from_account_id`, `to_account_id`, `amount`, `fee`,
  `occurred_at`.
- `expense_categories`, `income_categories` — self-referencing trees.
- `expenses`, `incomes` — `category_id`, `account_id`, `party_id` (nullable),
  `amount`, `occurred_at`, `description`, `attachment_media_id`.
- `recurring_templates` — `type`, `category_id`, `amount`, `interval`, `next_run_at`,
  `last_generated_at`, `is_active`.
- `rental_contracts` — `party_id`, `title` (the desk or display case being let),
  `amount`, `interval`, `starts_at`, `ends_at`, `is_active`.
- `rental_schedules` — generated income entries with due dates.

Account balances come from `ledger_entries` filtered by `account_id`. Never a stored
column.

## Behaviour

### Transfers

Between accounts, with an optional fee. Two ledger entries plus a third for the fee.
Balances always tie out.

### Recurring templates

A scheduled job generates the next expense/income when due. **Idempotent**: a job that
runs twice — because a worker restarted or a deploy overlapped — must not create the
rent expense twice. Guarded by `last_generated_at` plus a unique constraint on
(template, period).

### Rentals (اجاره میز)

Iranian phone shops routinely sublet a desk or display case to another trader. A
contract generates periodic income entries and a reminder. Modelled as a first-class
thing rather than a note, because it is a real and recurring revenue line.

### Daily close (Z report)

Per branch, per day: opening balance, receipts by method, payments, expected closing,
counted closing, and the difference. The difference is recorded, never silently
absorbed.

### P&L

```
revenue      = SUM(finalised invoice totals in period)
COGS         = SUM(cost_snapshot on invoice lines in period)
gross_profit = revenue − COGS
net_profit   = gross_profit + other_income − expenses
```

COGS comes from the snapshots Sales wrote at finalisation, which is why profit stays
correct under inflation ([sales.md](sales.md)).

### Reconciliation

A simple check-off: mark ledger entries as reconciled against a bank statement, with a
running unreconciled total. Not a full bank-feed import.

## The period-keyed idempotency pattern

**Reusable. Phase 8's SMS automations need the same shape — name it, do not reinvent it.**

Anything that must happen once per period and no more — rent, wages, a rental contract's
monthly income, and shortly a birthday text or an instalment reminder — is generated
against a **Jalali period key under a unique index**, never against a stored pointer.

```
cash_transactions.generated_key = "template:{id}:1405-06"   -- unique per tenant
                                  "rental:{id}:1405-06"
                                  (Phase 8) "birthday:{party}:1405"
                                            "installment-due:{row}:1405-06-15"
```

The generator asks *which periods between the start and today have not been booked*, and
books them. A collision on the insert is the answer "somebody already did this", and it is
swallowed rather than thrown — the caller is asking whether something needs doing, and "no"
is a normal outcome for a scheduled command.

### Why not `last_run_at`

A pointer is a stored position, and stored positions drift — golden rule 3's argument,
applied to time instead of money. The first job that dies halfway leaves it pointing at a
period that was only partly written, and the shop finds out by being billed twice or not at
all. It also cannot answer the question that actually comes up: a template switched off for
three months and switched back on should either catch up its missed periods or not,
according to a rule somebody chose — not according to wherever a pointer happened to stop.

### Why the database and not the service

`SELECT` then `INSERT` is sufficient for one worker and useless for two: both read "not
yet", both write. The guarantee has to live where the race is decided. Same mechanism as
the abandoned-device sweep in Phase 6, which records the escalation step **before** it
announces it — announce-then-record sends the message and loses the record if the write
fails, which is the same bug with worse timing.

### Why the period key is Jalali

A Gregorian `Y-m` key would be wrong for exactly the reason `Jalali` exists: a Jalali month
straddles two Gregorian ones, so «اجاره مرداد» would land in two different buckets
depending on which day it was booked. `Jalali::monthKey()` owns the format; `Jalali::dayInMonthOf()`
clamps a due day to the month's length, so a template set to the 31st still fires in a
30-day month rather than skipping it and quietly losing a month's rent every Esfand.

### The three properties any implementation must keep

1. **Running it twice changes nothing.** Tested by running it three times and asserting the
   row count is unmoved.
2. **A gap is caught up, not skipped.** Tested by generating from a start date months in the
   past and asserting every intervening period exists.
3. **An end date stops it.** Tested by asserting nothing is generated past `ends_on` or
   `terminated_on`, whichever came first.

## Screens

Account list with live balances · account statement · transfer form · expense and
income entry with category trees · recurring template manager · rental contracts ·
daily close · P&L · cash-flow summary.

## Events

Emits: `AccountTransferred`, `ExpenseRecorded`, `IncomeRecorded`,
`RecurringGenerated`, `DailyCloseCompleted`.

Listens: `PaymentReceived`, `ChequeStatusChanged`, `InstallmentCollected`.

## Acceptance

- Account balance always equals its ledger sum.
- A transfer with a fee produces balanced entries.
- The recurring generator run twice produces one entry.
- A rental contract generates the right number of scheduled incomes across its term.
- Daily close records the difference between expected and counted.
- **The "one crazy month" seeded scenario reconciles to the rial** across P&L,
  cash-flow and every account statement.
- Cross-tenant isolation on every endpoint.

## Out of scope

Double-entry general ledger beyond these ledgers (explicit anti-goal). Payroll beyond
simple commission. Bank feed imports. Multi-currency.
