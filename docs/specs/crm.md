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

## The timeline contract — how other modules contribute

**Implemented 2026-08-10.** Phase 5 (Sales), 6 (Repairs) and 8 (Messaging) implement
their contributors against this; nothing in CRM changes when they do.

The customer page shows what happened with a party, and most of that is owned by other
modules. CRM may not import them (ADR 0003), so contribution is inverted: each module
registers a contributor for its own records with
`App\Support\Timeline\TimelineRegistry`, and the page asks the registry.

### The rules

1. **Register in your own service provider's `boot()`**, keyed by module name:

   ```php
   $this->app->make(TimelineRegistry::class)->contribute(
       'Sales',
       static function (int $partyId, ?CarbonImmutable $from, ?CarbonImmutable $to): array {
           // → list<TimelineEntry>
       }
   );
   ```

2. **You are handed a party id, never a `Party`.** The model is a CRM class and is
   exactly the dependency this exists to avoid. If you need more than the id, you need
   an event, not a timeline entry.

3. **Honour the window and cap yourself.** `$from`/`$to` may be null; return at most
   ~60 rows. The registry sorts and slices the union, but a contributor that returns
   ten thousand rows makes every customer page slow for everyone.

4. **Only things that happened.** A draft invoice is a document someone is still
   writing. `PurchaseInvoice` contributes only when `received`, and the same test
   applies to a sale that is not final or a ticket not yet intaken.

5. **`amount` is signed integer rial from the party's point of view** — positive means
   they owe the shop more — matching the ledger convention so a page showing both does
   not reconcile two conventions. Null for anything that is not money.

6. **`kind` is a stable key**, mapped to an icon and a tone once in
   `resources/js/components/domain/timeline.tsx`. Add a key there when you add one here;
   do not colour anything from a page.

### Failure is named, never silent

A contributor that throws is caught, reported, and its **module name is returned in
`failed[]` and shown on the page**. Two things follow, and both are deliberate:

- A customer page that cannot render because one module had a bad day is worse than the
  same page missing that module's lines.
- A page *silently* missing its repair history is how somebody concludes a device was
  never brought in. The gap has to be visible to be safe.

So a contributor must not swallow its own errors to look tidy — let it throw and be
named.


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
