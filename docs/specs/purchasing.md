# Purchasing

**Phase 3** · Module `app/Modules/Purchasing`

## Purpose

Getting stock in, and knowing what it truly cost. The headline capability is **bulk
IMEI intake**: a shop receives twenty phones and should be able to paste or scan
twenty IMEIs and be done.

## Data

- `purchase_invoices` — `number`, `party_id` (supplier), `branch_id`, `warehouse_id`,
  `status` (`draft` · `received` · `void`), `issued_at`, `subtotal`, `discount`,
  `vat_amount`, `total`, `notes`.
- `purchase_invoice_items` — `product_variant_id`, `quantity`, `unit_cost`,
  `line_total`.
- `purchase_unit_items` — one row per serialized unit received: `imei1`, `imei2`,
  `condition`, `grade`, `unit_cost`.
- `landed_costs` — `purchase_invoice_id`, `type` (freight, customs, courier, other),
  `amount`, `allocation` (`by_value` · `by_quantity`).
- `purchase_returns`, `purchase_return_items`.

## Behaviour

### Bulk IMEI intake

A textarea and a scanner both feed the same parser. It accepts one IMEI per line or
separated by spaces/commas, normalises Persian and Arabic digits to Latin, validates
Luhn, and reports **per line**: accepted, invalid checksum, or already exists (with a
link to the existing unit).

Nothing is committed until the whole batch is valid or the operator explicitly skips
the bad rows. Half-received shipments are how stock stops reconciling.

### Landed costs

Freight, customs and courier charges allocate into each unit's `cost`, so profit
reflects what the phone actually cost to have on the shelf.

- `by_value` — proportional to line value (the default; a phone carries more of the
  customs bill than a case).
- `by_quantity` — split evenly.

Allocation is integer rial and the remainder goes to the **largest** line, so the sum
of allocated costs equals the total charge exactly.

### Receiving

Marking an invoice `received` writes stock movements and, for serialized lines,
creates `product_units` with `status = in_stock`, `acquired_from_party_id` set to the
supplier and `acquired_at` recorded. This is the beginning of every IMEI passport.

### Returns

Return to supplier reverses movements, moves units to `written_off` (or back out of
stock with a reason), and posts the credit to the supplier's ledger.

## Screens

Purchase invoice list · editor with standard lines and/or bulk IMEI intake · landed
cost allocator with preview · GRN print · purchase returns · supplier balance
shortcut.

## Events

Emits: `PurchaseReceived`, `PurchaseReturned`, `LandedCostAllocated`.

## Acceptance

- Pasting 10 IMEIs (mixed valid, invalid checksum, and duplicate) reports each line
  correctly and commits only what was accepted.
- Landed cost allocation sums exactly to the charge, with the remainder on the largest
  line.
- Receiving creates units, movements and ledger entries; stock reconciles.
- A purchase return reverses everything it should and nothing it should not.
- Persian and Arabic digits in the IMEI box are normalised before validation.
- Cross-tenant isolation on every endpoint.
- **Quota.** Both credits are spent at **receipt**, not at draft creation, and that is the
  whole design: `POST purchasing/invoices` and pasting IMEIs onto the draft spend nothing,
  because a draft is a person typing and a shop must be able to prepare a delivery it cannot
  yet afford to receive. `ReceivePurchaseInvoice` then spends one `purchasing.invoices` and a
  batch of N `inventory.units` in one transaction — so a paste of ten devices that crosses
  the ceiling is refused whole, takes the document credit back with it, and leaves the ten
  lines sitting in the draft where the operator typed them. Receiving twice is refused before
  the service is reached, so it costs nothing either (ADR 0018).

## Out of scope

Purchase orders and supplier approval workflows. EDI. Automatic reordering.
