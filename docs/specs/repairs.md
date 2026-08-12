# Repairs

**Phase 6** · Module `app/Modules/Repairs` · ★ flagship

## Purpose

The module shops choose this product for. A repair bench runs on trust: the customer
hands over a device worth months of income and wants to know what is happening to it.
Everything here serves two people — the technician who needs the queue to be honest,
and the customer who wants a link they can check without phoning.

## Data

### `repair_tickets`

`code` (short, printable, from `counters`), `party_id`, `branch_id`,
`product_unit_id` (nullable — set when it is a device this shop sold, which is how the
IMEI passport links repairs to sales), device brand/model/imei, `reported_issue`,
`status`, `technician_id`, `priority`, `promised_at`, `estimate_amount`,
`approved_amount`, `approved_at`, `approved_via` (`link` · `phone`),
`prepaid_amount`, `warranty_days`, `delivered_at`, `abandoned_at`,
**`device_passcode`** (encrypted at rest), `accessories` (JSON), `tracking_token`.

### `ticket_status_histories`

`from_status`, `to_status`, `actor_id`, `note`, `created_at`. Append-only.

### `ticket_checklist_answers`

Answers to the tenant's intake template — cosmetic and functional condition at drop-off.
This is what settles "the screen was already cracked" three weeks later.

### `ticket_photos`, `signatures`

Intake photos and the delivery signature, stored via [Files](files.md).

### `ticket_parts`, `ticket_services`

`ticket_parts`: `product_variant_id`, `quantity`, `unit_cost`, `state`
(`reserved` · `consumed` · `returned`).
`ticket_services`: priced labour from the service book — flash, unlock, FRP, data
recovery, board-level work.

### `outsource_jobs`

`ticket_id`, `party_id` (external technician), `sent_at`, `returned_at`, `cost`,
`notes`. Their balance runs through the normal party ledger.

## Behaviour

### State machine

```
  queued ──▶ diagnosing ──▶ awaiting_approval ──▶ awaiting_parts ──▶ repairing ──▶ ready
     │            │                │                                    │           │
     │            └────────────────┴────────────────────────────────────┘           │
     │                                                                              ▼
     └──────────────────────────────▶ rejected                            delivered │
                                                                                    │
                                     abandoned ◀────── N days after ready ──────────┘
```

Every transition emits an event, is recorded with actor and note, and may fire an SMS
([messaging.md](messaging.md)). Illegal transitions throw.

### Estimate and approval

A tenant setting sets the amount a technician may spend **without** customer approval.
Above it, the ticket must pass through `awaiting_approval`, and work cannot start
until approval is recorded — either by the customer on a signed public link, or
manually as "approved by phone" with the staff member's note and timestamp.

**The cap fails closed.** Unset, negative, malformed or zero all mean *everything needs
approval*, never *nothing does*. A shop that has not told us what is safe to spend on
somebody else's phone has not authorised any amount, and guessing generously is guessing
with a customer's money. Note the consequence in the implementation: the check is
`cap > 0 && estimate <= cap`, not the obvious `estimate <= cap` — under a zero cap the
latter lets a ticket quoted at zero through, and a zero quote is not a free job, it is a
job nobody has priced yet.

### The consent pattern: a customer approves a frozen figure, never a live record

**This shape recurs, and it is the general rule rather than a detail of repairs.**
Whenever the product asks a customer to agree to something through a link, the thing
they agree to is captured at the moment the link is minted — never read live from the
record when they answer.

The failure it prevents, concretely: a shop quotes ۴٬۵۰۰٬۰۰۰ and texts an approval link.
Before the customer taps it, somebody edits the estimate to ۹٬۰۰۰٬۰۰۰. The customer taps
approve. If approval copied the *current* estimate, they have just agreed to a number
they were never shown — and the shop has a signed-looking record of consent that the
customer would not recognise.

So the rule has three parts, and all three are load-bearing:

1. **Freeze the figure with the token.** `approval_quoted_amount` is written when the
   request is made; the public page renders that field and the approval records it.
   Nothing in the consent path reads the live estimate.
2. **Changing the offer mints a new token and invalidates the old one.** A re-quote is a
   *new question*, not a substitution inside an old one. The previous link 404s, which is
   the honest outcome — better a customer who has to re-open a fresh message than one who
   approves a figure that changed under them.
3. **The token is single-use and separate from any other link.** Cleared the moment it is
   used, so a forwarded message or a photographed screen cannot re-authorise. It is not
   the tracking token: tracking is printed on a receipt that lives in a pocket, while
   this authorises spending.

**Where this returns:**

- **Installment contracts** (Phase 5.5, and collection in 7.4). A customer agreeing to a
  schedule must agree to the rows they were shown, not to whatever the plan says when
  they tap. Re-scheduling is a new contract.
- **Any future customer-consent link** — quote acceptance on a storefront order, a
  price-list link a reseller acts on, a Moadian correction a customer acknowledges.

If you are adding a link that asks a customer to say yes, copy this shape. The test that
matters is the one that edits the record after minting the link and asserts the customer
still approved the original figure.

### Parts

Reserved when planned, consumed on completion, returned to stock if the job is
cancelled. Repair profit = labour + parts margin − outsource cost, using the same cost
snapshot discipline as Sales.

### Device passcode

Encrypted at rest. Masked in the UI. Revealing it requires a specific permission and
writes an audit entry. **It never appears in logs, JSON responses, or exports** — this
is asserted by a test, because a leaked unlock code is a stolen phone.

### Abandoned devices (رسوبی)

N days after `ready` (tenant setting), the ticket is flagged and an escalating SMS
sequence starts. After the final step the status becomes `abandoned`. This is a real
and expensive problem for Iranian shops — devices pile up for months.

### Public tracking

A signed, no-login URL: code → status timeline, promised date, amount due, shop
contact. It exposes **nothing** else — no other tickets, no customer list, no prices
beyond this job's own total. Rate-limited.

## Screens

- Intake — customer, device, issue, configurable checklist, photos, passcode,
  accessories, prepaid, estimate. Prints a receipt with barcode and tracking QR.
- **Kanban board** by status, and a list view. Drag to transition, with the same rules
  as the API.
- Ticket detail — history, parts, services, photos, costs, actions.
- Technician workload.
- Delivery — settle payment (reuses the Sales payment box), capture signature, print.
- Abandoned list.
- Intake checklist template builder ([settings.md](settings.md)).
- Public tracking page.

## Events

Emits: `TicketCreated`, `TicketStatusChanged`, `TicketApproved`, `TicketReadyForPickup`,
`TicketDelivered`, `TicketAbandoned`, `RepairPartConsumed`, `RepairPartReturned`.

Listens: `UnitStatusChanged` (Inventory) to link a repair to a unit we sold.

## Acceptance

- Every legal transition succeeds; every illegal one throws.
- Work above the approval cap is blocked until approval is recorded, with the method
  and actor stored.
- Parts reserve → consume on completion; cancelling returns them to stock and stock
  reconciles.
- Two technicians acting on one ticket concurrently: one wins, cleanly.
- The encrypted passcode appears in no log, no JSON response and no export.
- The public tracking page leaks nothing tenant-private and is rate-limited.
- The abandoned scheduler flags at exactly N days and escalates in order.
- Repair profit reconciles against parts, labour and outsource cost.
- Cross-tenant isolation on every endpoint, including the public page.

## Out of scope

Mail-in and on-site repair logistics. A customer web portal beyond the tracking page.
Automatic parts ordering from suppliers.
