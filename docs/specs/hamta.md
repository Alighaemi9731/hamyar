# HAMTA (همتا)

**Phase 10** · Module `app/Modules/Hamta`

## Purpose

HAMTA is Iran's national handset registry. When a used phone changes hands, ownership
must be transferred or the buyer eventually finds their device restricted.

**HAMTA has no public API.** This module is therefore *guidance and record-keeping* —
a checklist, a status on the unit, and warnings where a transfer is outstanding. The
UI says this plainly. Promising customers a direct integration would be a lie, and the
first time a transfer silently failed the shop would be the one blamed.

## Data

Extends `product_units` rather than owning its own register:

- `hamta_status` — `not_required` · `pending` · `done`
- `hamta_activation_id` — recorded from the customer's confirmation SMS. **Stored, not
  verified.**
- `hamta_transferred_at`, `hamta_note`

Plus `hamta_checklist_answers` — which steps the salesperson confirmed, with actor and
timestamp. That record is what protects the shop in a dispute.

## Behaviour

### When a transfer is required

| Situation | Status |
|---|---|
| New device sold | `not_required` |
| Used device bought from a customer (incl. trade-in) | `pending` |
| Used device sold to a customer | `pending` |
| Transfer confirmed and activation id recorded | `done` |

### The guided checklist

Shown at the point of a used-device purchase or sale:

1. Confirm the seller is the registered owner.
2. Capture the seller's ID document ([files.md](files.md)).
3. Walk through the transfer: `*#7777#`, the HAMTA app, or hamta.ir.
4. Wait for the customer's confirmation SMS.
5. Record the activation id.
6. Both parties acknowledge.

### Warnings

A unit with `hamta_status = pending` shows a persistent warning on the IMEI passport,
in the stock list and on the invoice preview. A used sale can be completed with the
transfer outstanding — the shop's workflow cannot be held hostage to a third party —
but it is recorded as a deliberate choice, and the pending list is a screen someone
has to clear.

### Instructions page

A plain Persian page explaining the transfer to a shop assistant who has to explain it
to a customer: what `*#7777#` does, what the confirmation SMS looks like, what happens
if it is skipped, and how long it takes.

## Screens

Checklist inside the used-purchase and used-sale flows · pending transfers list ·
HAMTA panel on the IMEI passport · instructions page.

## Events

Emits: `HamtaTransferPending`, `HamtaTransferCompleted`.

Listens: `TradeInRecorded`, `PurchaseReceived` (used condition),
`InvoiceFinalised` (used unit sold).

## Acceptance

- Buying or selling a used device sets `hamta_status = pending`.
- Recording an activation id moves it to `done` with actor and timestamp.
- Pending units show the warning in all three places.
- The checklist answers are stored with who confirmed them and when.
- The UI states explicitly that there is no official API.
- Cross-tenant isolation on every endpoint.

## Out of scope

Any claim of direct HAMTA integration. Automated verification of an activation id.
Scraping hamta.ir.
