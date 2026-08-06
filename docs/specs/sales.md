# Sales

**Phase 5** · Module `app/Modules/Sales` · ★ revenue-critical

## Purpose

The counter. A salesperson with a customer waiting must be able to scan, price, take
mixed payment and print in well under a minute. Everything else in this module serves
that.

## Data

### `sales_invoices`

`number` (per tenant + branch, from `counters` with a row lock), `status`
(`draft` · `final` · `void`), `branch_id`, `party_id`, `salesperson_id`, `issued_at`
(UTC), `subtotal`, `discount_amount`, `vat_amount`, `shipping_amount`, `total`,
`paid_total`, `type` (`invoice` · `quote`), `notes`, `settings_snapshot`.

All money columns are `BIGINT` rial.

### `sales_invoice_items`

`product_variant_id` **or** `product_unit_id` (exactly one), `description`,
`quantity`, `unit_price`, `discount_amount`, `vat_rate`, `line_total`,
`warranty_months`, **`cost_snapshot`**.

`cost_snapshot` is written at finalisation and never recomputed. Profit reported
months later must reflect what the item actually cost at the time, not today's price —
under Iranian inflation these differ enormously.

### `invoice_payments`

`method` (`cash` · `pos_terminal` · `card_to_card` · `cheque` · `credit`),
`account_id`, `amount`, `reference`, `cheque_id`, `received_at`.

### `sales_returns`, `sales_return_items`, `trade_ins`, `quotes`

`trade_ins`: `invoice_id`, `party_id`, device details, `agreed_price`,
`product_unit_id` (the used unit it created), `id_scan_media_id`,
`hamta_ack` (boolean — the salesperson confirmed they walked the customer through the
ownership transfer).

## Behaviour

### Lifecycle

`draft → final → void`. Only finalisation touches stock and ledgers. Void reverses
everything it did — it never deletes rows, because a tax invoice number must remain
accounted for.

### Finalisation (one transaction)

1. Allocate the number from `counters` with `SELECT … FOR UPDATE`.
2. Re-check every serialized unit is still `in_stock`/`reserved` **for this branch**.
3. Write stock movements and flip units to `sold`.
4. Snapshot costs on each line.
5. Write payment rows and the ledger entries.
6. Post any unpaid balance to the party ledger.
7. Emit `InvoiceFinalised`.

Two salespeople finalising the same IMEI: one succeeds, the other gets a clean,
specific Persian error naming the handset. Never a 500, and never a double-sale.

### Pricing

Price level comes from the party (`consumer` · `reseller` · `vip`), overridable per
line with the permission. Discounts are per-line and invoice-level; the invoice-level
discount distributes across lines proportionally so per-line profit stays truthful.

VAT is a tenant setting, per line, and off by default — many shops are not registered.

### Profit

- Serialized: exact `product_units.cost`. Specific-identity, always.
- Standard goods: weighted-average cost at the moment of sale, snapshotted.

### Trade-in (معاوضه)

Inside the POS screen: a "buy customer device" line opens a mini-intake — model, IMEI,
condition, agreed price, ID scan, HAMTA transfer checklist acknowledgement. It creates
a purchase document and a `product_unit` with `condition = used`, and offsets the
invoice total. The customer walks out having paid the difference.

### Installment sale

Marking an invoice as installment opens the wizard: down payment, count, interval,
flat profit percentage, first due date (Jalali). It generates the plan — see
[installments.md](installments.md). **The last row absorbs the rounding remainder**,
so the schedule always sums to the contract total.

### Returns

Full or partial. A serialized return moves the unit `sold → returned`, prompts for a
cosmetic re-grade, and only then allows `returned → in_stock`. Ledger entries are
reversed, never deleted.

### Numbering

Per tenant **and** branch. `counters` row lock. No gaps, no duplicates, under
concurrency — this is a legal requirement on a tax invoice, and it is tested with 50
parallel finalisations.

## Screens

- **POS** — scan box autofocused and always refocused; barcode or IMEI; Enter submits;
  compact density; running total and change always visible; keyboard-first.
- Invoice list with Jalali range filter and status.
- Invoice detail — items, payments, ledger effect, print actions.
- Quote → convert.
- Returns.
- Daily close (Z report) per cash session.

## Printing

Thermal 80mm, A5, A4 official — all from `PrintLayout.*`. QR links to a public invoice
view. Logo and footer terms from [settings.md](settings.md).

## Events

Emits: `InvoiceFinalised`, `InvoiceVoided`, `SaleReturned`, `TradeInRecorded`,
`InstallmentPlanRequested`, `PaymentReceived`.

Listens: `CustomerCreditLimitChanged` (CRM).

## Acceptance

- Full POS happy path, scan to print.
- Split payment across all five methods; change correct; ledger entries balance.
- Two parallel finalisations of one IMEI: exactly one wins, the other errors cleanly.
- 50 parallel finalisations produce 50 consecutive numbers, no gaps or duplicates.
- Trade-in creates the used unit and the invoice total is right.
- Installment schedule sums exactly to the contract total, remainder on the last row.
- Return restores stock and reverses the ledger.
- Void leaves the number allocated and every effect reversed.
- Cross-tenant isolation on every endpoint.

## Out of scope

Online checkout. Multi-currency. Loyalty redemption at the till (Phase 4 accrues
points; redemption is a later decision).
