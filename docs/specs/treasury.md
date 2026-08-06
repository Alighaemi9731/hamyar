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
