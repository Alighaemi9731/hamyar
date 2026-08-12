# Inventory

**Phase 3** · Module `app/Modules/Inventory`

## Purpose

Knows where every item is and, for phones, what happened to it. This module owns the
product's single biggest differentiator: the **IMEI passport**, which answers *bought
from whom → sold to whom → repaired when* for any handset the shop has ever touched.

Two kinds of stock, and the distinction runs through the whole system:

- **Standard goods** (accessories, parts) — a quantity in a warehouse.
- **Serialized units** (phones) — one row per physical device, with its own cost,
  condition and life story. A serialized item is never "3 in stock"; it is three rows.

## Data

### `branches`, `warehouses`

`branches`: name, code, address, phone, is_default.
`warehouses`: name, branch_id, is_default. A branch may hold several warehouses (shop
floor, back room, repair bench).

### `product_units` — the serialized register

| Column | Notes |
|---|---|
| `imei1`, `imei2` | Unique per tenant. Validated with Luhn. `imei2` optional (dual-SIM) |
| `serial` | For devices without an IMEI |
| `product_variant_id` | Model + colour + storage |
| `condition` | `new` · `used` · `refurb` |
| `grade` | Cosmetic grade for used stock (A/B/C) |
| `cost` | **Integer rial.** This exact unit's purchase cost — profit is per-unit, never averaged |
| `status` | `in_stock` · `reserved` · `sold` · `in_repair` · `returned` · `written_off` |
| `warehouse_id` | Current location |
| `acquired_from_party_id` | Supplier, or the customer on a trade-in |
| `acquired_at` | UTC |
| `hamta_status` | `not_required` · `pending` · `done` (see [hamta.md](hamta.md)) |
| `hamta_activation_id` | Recorded, not verified — there is no API |
| `warranty_months`, `warranty_until` | |
| `notes` | |

Media attached via [Files](files.md): seller ID scan, consent form, device photos.

#### IMEI uniqueness — indexes are not enough

Uniqueness is **per tenant** (the same handset legitimately moves between shops) and is
enforced by three things, because the first two miss a case that matters:

1. A partial unique index on `(tenant_id, imei1)`.
2. A partial unique index on `(tenant_id, imei2)`.
3. **A `before insert or update` trigger, `product_units_imei_unique`.**

The trigger exists because the two indexes only stop a number repeating *within the same
column*. A dual-SIM phone's `imei2` could still be registered as a different device's
`imei1` — the same physical handset entered twice, which is precisely what the passport
must never allow. Postgres cannot express "unique across two columns of one table" as an
index, so the trigger checks `new.imei1` and `new.imei2` against both columns of every
other live row for that tenant and raises `unique_violation`.

All three are partial on live rows (`deleted_at is null`), so a soft-deleted write-off
does not block re-registering the same handset if it comes back through the door.

Values are normalised to 15 Latin digits before storage (`App\Support\Imei`), because
Iranian staff type on Persian keyboards — without it, `۳۵۲۰۹۹…` and `352099…` are
different devices to the index and to every later lookup.

> Tests: `app/Modules/Inventory/tests/Feature/SerializedUnitTest.php` —
> *"it refuses an IMEI already registered as another device second SIM"* covers the
> cross-column case specifically, alongside same-column, cross-tenant and
> soft-delete-frees-the-number.

### `product_unit_histories`

Every state transition: `from_status`, `to_status`, `actor_id`, `reference_type`,
`reference_id`, `note`, `created_at`. This table is what makes the passport possible;
it is append-only.

### `stock_movements` — the quantity ledger

| Column | Notes |
|---|---|
| `product_variant_id`, `warehouse_id` | |
| `quantity` | Signed integer: in is positive, out is negative |
| `type` | `purchase` · `sale` · `return` · `transfer_out` · `transfer_in` · `adjustment` · `count` · `repair_consume` · `repair_return` · `write_off` |
| `reference_type`, `reference_id` | Polymorphic — the document that caused it |
| `unit_cost` | Cost snapshot at the time of the movement |
| `occurred_at` | UTC |

**Quantity on hand is a `SUM`, never a column.** Covering index
`(tenant_id, product_variant_id, warehouse_id)`.

### `stock_reservations` — stock that has not moved

`product_variant_id`, `warehouse_id`, polymorphic `holder`, `quantity`, `state`
(`active` · `consumed` · `released`), `closed_at`.

**A hold is not a movement.** A screen protector set aside for tomorrow's repair is
physically on the shelf: a stock count finds it, and writing a `stock_movement` for it
would make the ledger disagree with the shelf — the one thing the quantity ledger exists
to prevent (golden rule 3). It would also have to be reversed on cancellation, leaving
two movements describing an event that never happened. The movement is written **once**,
on consumption, when the goods actually leave.

So the two questions are answered by two methods, and the distinction is deliberate:

| | answers | used by |
|---|---|---|
| `onHand()` | what is physically in the building | stock counts, valuation, reconciliation |
| `available()` | what may be promised to somebody | the POS, and anything that offers stock |

`available()` is `onHand()` minus active holds, floored at zero.

**Why this table lives in Inventory rather than in the module that needed it first.**
Repairs needed holds first, but a hold is one shape with several sources:

- a **repair** with parts planned but not yet fitted (Phase 6.4, the first consumer);
- a **parked sale** — a POS basket saved as a draft while a customer fetches money, whose
  lines should not be sellable to the next person through the door (Phase 5.1 does not
  reserve today; see below);
- an **in-transit transfer**, dispatched from one warehouse and not yet received at the
  other (Phase 3.6 currently handles this by moving the stock in two steps, which is
  correct for quantities and could equally be expressed as a hold);
- a **held storefront order** awaiting payment (Phase 10.2).

If each of those invented its own table, the POS would have to know about repairs, sales
drafts, transfers and the storefront in order to answer "what may I sell" — and the day
somebody adds a fifth, the till silently starts over-promising. One table, one
definition, one place the answer comes from.

**The contract is deliberately generic**, so Phase 5's parked-sale case can adopt it
with no schema change: the holder is polymorphic (`holder_type`/`holder_id`), nothing
in the table or the service references a repair, and `StockReservations::reserve()`
takes any Eloquent model. Making a draft invoice reserve its lines is a call to the
same service with a `SalesInvoice` as the holder — the decision to do that is a product
question about whether parking a basket should hold stock, not a technical one.

### `transfers`, `transfer_items`

Two-step: `dispatched` → `received`. Stock leaves the source on dispatch and arrives
at the destination on receipt, so goods in transit are visible and cannot be sold at
either end.

### `stock_counts`, `stock_count_items`

A counting session, optionally **blind** (the counter cannot see the expected figure).
Closing the session writes `adjustment` movements for the differences — it never
overwrites a quantity.

## Behaviour

### Unit state machine

```
              ┌──────────────┐
   purchase──▶│   in_stock   │◀────────────┐
              └──┬───┬───┬───┘             │
     reserve─────┘   │   └──────┐          │
              ┌──────▼───┐  ┌───▼──────┐   │
              │ reserved │  │ in_repair│───┘
              └────┬─────┘  └──────────┘
                   │ sell
              ┌────▼─────┐   return   ┌──────────┐
              │   sold   │───────────▶│ returned │──┐
              └──────────┘            └──────────┘  │
                                                    │ re-grade
              ┌──────────────┐                      │
              │  written_off │◀─────────────────────┘
              └──────────────┘
```

Illegal transitions throw. `sold → in_stock` directly is illegal: a returned phone
passes through `returned` so it gets a cosmetic re-grade before resale.

Reservation exists so a POS screen can hold a specific handset while payment is taken
without two salespeople selling the same IMEI. Reservations expire.

### IMEI rules

- Unique per tenant, on both `imei1` and `imei2`, enforced by a database constraint —
  not just validation, because two salespeople can submit simultaneously.
- Luhn check on entry, with a clear Persian message on failure.
- Persian/Arabic digits normalised to Latin before validation.
- Searching any IMEI field finds the unit from anywhere in the app.

### Reservations

Reserve when the work is planned, consume when it happens, release if it does not.

```
  active ──consume──▶ consumed     one stock movement, written here and nowhere else
     │
     └────release───▶ released     no movement in either direction; nothing ever moved
```

Reserving is refused when it would exceed `available()`, checked under a row lock against
the holds it is about to join — two benches claiming the last screen at the same moment
is the same race as the serialized double-sell in Sales, and gets the same treatment.

Closed rows are kept rather than deleted. "Why did the shop think it had two of these
last Tuesday" is a question somebody asks.

### Negative stock

Blocked by default. A tenant setting may allow it per warehouse (some shops sell
accessories before the invoice is entered), and when enabled the low-stock report
surfaces every negative balance.

## Screens

- Stock list — by variant, with on-hand per warehouse.
- Serialized unit list — filter by status, condition, grade, warehouse, brand.
- **IMEI passport** — one page per unit: purchase (from whom, when, cost, documents),
  sale (to whom, when, price, invoice, warranty), every repair, transfers, HAMTA
  status, attached media, full history.
- Transfers — dispatch and receive.
- Stock count session.
- Low stock and dead stock lists.
- Label printing — price/barcode, single and batch, at real label sizes.

## Events

Emits: `UnitStatusChanged`, `StockMovementRecorded`, `LowStockDetected`,
`TransferDispatched`, `TransferReceived`, `StockCountClosed`.

Listens: `InvoiceFinalised` (Sales) → reserve/sell units; `InvoiceVoided` → restore;
`PurchaseReceived` (Purchasing) → create units and movements; `RepairPartConsumed`
(Repairs) → decrement.

## Acceptance

- Pasting 10 IMEIs creates 10 units, 10 movements, and stock reconciles.
- Duplicate IMEI within a tenant is rejected; the same IMEI in a different tenant is
  fine.
- Every illegal state transition throws; every legal one is recorded with actor.
- Two-step transfer: after dispatch the unit is at neither end's sellable stock; after
  receipt it is sellable only at the destination.
- Blind count closes with adjustment movements; the on-hand `SUM` matches the count.
- Quantity on hand always equals `SUM(stock_movements.quantity)` — asserted after
  every operation.
- Landed costs from Purchasing land in `product_units.cost`.
- Cross-tenant isolation on every endpoint, including the IMEI search.

## Out of scope

Serial-number tracking for accessories (they are standard goods). Bin/shelf-level
locations. Automatic reordering.
