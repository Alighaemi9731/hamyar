# CRM (Parties)

**Phase 4** · Module `app/Modules/CRM`

## Purpose

Everyone the shop owes money to or is owed money by, in one table. Customers,
suppliers and reseller colleagues are the same kind of thing — a **party** with a
ledger — and in this trade one person is frequently all three.

## Data

- `parties` — `kind` (`customer` · `supplier` · `colleague` · `both`), `name`,
  `company_name`, `national_id`, `economic_code`, `price_level_id`, `credit_limit`
  (rial), `opening_balance`, `birthday` (stored as a UTC date, entered as Jalali),
  `is_active`, `notes`.
- `party_contacts` — several mobiles/emails per party, one primary.
- `party_addresses`.
- `party_tags`, `taggables`.
- `follow_ups` — `party_id`, `assignee_id`, `due_at`, `note`, `done_at`.
- `loyalty_transactions` — `party_id`, `points`, `reason`, `reference`.
- **`ledger_entries`** — the balance engine, owned here and used by every financial
  module: `party_id` (nullable), `account_id` (nullable), `debit`, `credit`,
  `reference_type`, `reference_id`, `description`, `occurred_at`.

## Behaviour

### Balances

```sql
SELECT SUM(debit) - SUM(credit)
  FROM ledger_entries
 WHERE tenant_id = ? AND party_id = ?;
```

Never a stored column ([ADR 0003](../adr/0003-modular-monolith.md)). The statement
page shows every entry with a running balance, filterable by Jalali date range, and
its closing figure must equal the sum — asserted by a test.

### Credit limit

Checked when a credit sale would push the party past it. Behaviour is a **warning with
an override permission**, not a hard block: refusing a sale to a regular customer over
a limit nobody remembered setting loses the shop money. The override is logged.

### 360° timeline

One chronological stream per party: purchases, repairs, payments, cheques,
installments, SMS sent, notes, follow-ups. Backed by a union query over the modules'
own tables — CRM does not duplicate their data.

### Excel import

Column-mapping wizard with a **dry run first**: rows that would be created, rows that
would be updated, duplicates by mobile, and a per-row error report. Nothing is written
until the operator confirms. Persian digits in mobile numbers are normalised.

### Loyalty

Points accrue on finalised invoices by a configurable rule. Redemption is deliberately
left to a later phase, so the table exists and accrues from Phase 4 without committing
to a redemption model.

## Screens

Party list with kind/tag/balance filters · party page (profile, balance, statement,
timeline, follow-ups, attachments) · receive/pay quick forms · follow-up inbox ·
import wizard · aging report shortcut.

## Events

Emits: `PartyCreated`, `CreditLimitExceeded`, `FollowUpDue`, `LedgerEntryPosted`,
`LoyaltyPointsEarned`.

Listens: `InvoiceFinalised`, `PaymentReceived`, `ChequeStatusChanged`,
`InstallmentCollected`, `TicketDelivered` — all of which post ledger entries.

## Acceptance

- Party statement closing balance equals `SUM(debit) − SUM(credit)`, exactly.
- Over-limit credit sale warns and requires the override permission; the override is
  logged.
- A 500-row Excel import: dry run matches the applied result, duplicates by mobile are
  reported, bad rows are listed with their line numbers.
- The timeline shows events from every module in the right chronological order.
- Opening balance appears as the first ledger entry, not as a special case.
- Cross-tenant isolation on every endpoint.

## Out of scope

Marketing automation beyond birthday SMS (that belongs to
[messaging.md](messaging.md)). A customer self-service portal. Lead pipelines.
