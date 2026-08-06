# Cheques (چک)

**Phase 7** · Module `app/Modules/Cheques`

## Purpose

Post-dated cheques are a primary payment instrument in Iranian trade, not an edge
case. A shop may hold dozens at once, endorse them onward to suppliers, and live or
die by which ones clear. This module tracks both directions through their full
lifecycle and posts the right ledger entries at every stage.

## Data

`cheques`:

| Column | Notes |
|---|---|
| `direction` | `received` (from a customer) · `issued` (by the shop) |
| `party_id` | Who gave it to us, or who we gave it to |
| `bank_name`, `branch_name`, `serial`, `sayad_id` | `sayad_id` is the national cheque identifier |
| `amount` | Integer rial |
| `due_date` | UTC date, entered and displayed as Jalali |
| `status` | See the state machine |
| `account_id` | Where it was deposited |
| `endorsed_to_party_id` | When spent onward |
| `bounced_reason`, `bounced_at` | |
| `reference_type`, `reference_id` | The invoice or payment that produced it |
| `attachment_media_id` | Photo of the cheque |

`cheque_status_histories` — append-only, with actor and note.

## State machine

```
                    ┌──────────┐
  received ────────▶│ in_hand  │
                    └─┬──┬───┬─┘
        deposit       │  │   │       endorse
        ┌─────────────┘  │   └────────────────┐
        ▼                │ return to payer    ▼
  ┌───────────┐          │            ┌────────────────────┐
  │ deposited │          ▼            │ spent_to_third_party│
  └─┬───────┬─┘   ┌──────────┐        └─────────┬──────────┘
    │       │     │ returned │                  │ bounces at their end
 clear│  bounce   └──────────┘                  ▼
    ▼       ▼                              ┌─────────┐
┌────────┐ ┌─────────┐                     │ bounced │
│cleared │ │ bounced │◀────────────────────┘─────────┘
└────────┘ └────┬────┘
                │ re-present
                └──────────▶ deposited
```

`issued` cheques run the mirror image: `in_hand` (written, not yet presented) →
`cleared` or `bounced`.

## Ledger posting matrix

Every stage posts, and this table is the specification the tests assert against.

### Received cheques

| Transition | Debit | Credit |
|---|---|---|
| Accepted as payment | Cheques receivable | Customer (party) |
| `in_hand → deposited` | Bank account (uncleared) | Cheques receivable |
| `deposited → cleared` | Bank account | Bank account (uncleared) |
| `deposited → bounced` | Cheques receivable | Bank account (uncleared) |
| `bounced` finalised | Customer (party) | Cheques receivable |
| `in_hand → spent_to_third_party` | Supplier (party) | Cheques receivable |
| `in_hand → returned` | Customer (party) | Cheques receivable |

### Issued cheques

| Transition | Debit | Credit |
|---|---|---|
| Issued to supplier | Supplier (party) | Cheques payable |
| `cleared` | Cheques payable | Bank account |
| `bounced` | Cheques payable | Supplier (party) — and raise an alert |

A bounced cheque **restores the original debt**. It never silently disappears, and the
customer's balance must go back up.

## Behaviour

- Due-date reminders: a list, plus SMS at T-2 days
  ([messaging.md](messaging.md)).
- A calendar view of upcoming due dates — cash-flow planning is the point.
- Printable receipt when a cheque is taken.
- Endorsing onward records the chain, so an endorsed cheque that bounces can be traced
  back to the customer who wrote it.
- Bounced cheques may be re-presented; the history keeps both attempts.

## Screens

Cheque list by direction and status · detail with history and attachments · due-date
calendar · deposit action (choose account) · clear/bounce/endorse actions · receipt
print.

## Events

Emits: `ChequeReceived`, `ChequeStatusChanged`, `ChequeBounced`, `ChequeDueSoon`.

Listens: `InvoiceFinalised` (a cheque payment line creates the cheque record).

## Acceptance

- Every transition in the matrix posts exactly the entries in the table above.
- A bounced cheque restores the customer's balance to its pre-payment figure.
- An endorsed cheque that bounces traces back to the original payer.
- Re-presenting keeps both attempts in the history.
- Due-date reminders fire once, at T-2.
- The seeded reconciliation scenario ties out with cheques in every status.
- Cross-tenant isolation on every endpoint.

## Out of scope

Integration with the Sayad/central bank cheque system. Cheque printing on bank stock.
