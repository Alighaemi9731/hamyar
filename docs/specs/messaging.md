# Messaging

**Phase 8** · Module `app/Modules/Messaging`

## Purpose

SMS is how an Iranian shop talks to its customers — not email, not push. "Your phone
is ready" is the single most valuable message this product sends. This module owns
drivers, templates, event automations, campaigns and the credit wallet.

## Data

- `sms_templates` — `key`, `name_fa`, `body`, `variables` (JSON), `pattern_code`
  (the provider's approved pattern id), `is_active`.
- `sms_messages` — `party_id`, `mobile`, `template_key`, `body`, `status`
  (`queued` · `sent` · `delivered` · `failed`), `provider`, `provider_message_id`,
  `cost` (rial), `error`, `sent_at`, `delivered_at`.
- `sms_campaigns` — `name`, `filters` (JSON), `template_key`, `scheduled_at`,
  `status`, `recipient_count`, `sent_count`.
- `sms_credit_wallet`, `sms_credit_transactions` — balance and movements.
- `notification_settings` — the per-tenant automation toggle matrix.
- `sms_opt_outs` — `mobile`, `opted_out_at`.

## Behaviour

### Drivers

An interface with Kavenegar first, sms.ir stubbed, plus `log` and `null` drivers for
development and tests. Iranian providers require **pattern (template) sends** for
transactional messages, so the driver interface is pattern-first rather than
free-text-first.

Two billing modes: the tenant supplies its own provider credentials, or it buys
credits from the platform pool ([platform.md](platform.md)).

### Credit accounting

A message reserves credit before sending and commits on success. **On gateway failure
the credit is refunded** — a shop must never pay for a message that was not sent.
Balance is a sum over `sms_credit_transactions`, not a stored column.

Low-credit alerts at a configurable threshold, because a shop that runs out silently
stops telling customers their repairs are ready.

### Templates

Variables: `{name}`, `{shop}`, `{ticket_code}`, `{amount}`, `{due_date_j}`,
`{invoice_number}`, `{imei}`, `{tracking_url}`, `{balance}`. Dates render Jalali;
amounts render through the money formatter. Rendering is the same code path in preview
and in send, so what the operator previews is what is delivered.

### Automation matrix

A per-tenant on/off grid:

| Event | Default |
|---|---|
| Invoice finalised | off |
| Repair status changed | off |
| Repair ready for pickup | **on** |
| Installment due T-3 | on |
| Installment due T-0 | on |
| Installment overdue | on |
| Cheque due T-2 | on |
| Customer birthday | off |
| Abandoned device, steps 1–3 | on |

Each automation fires **once** per event — the reminder scheduler is idempotent, and
that is tested. Nothing is more corrosive to trust than a customer getting the same
reminder four times.

### Campaigns

Audience built from CRM filters: last purchase date, brand owned, tags, balance,
purchase count. Preview shows the recipient count before scheduling. Sending is queued
and throttled to the provider's rate limit, with per-message delivery polling.

### Opt-out

Honoured everywhere, including campaigns **and** automations, except where a message
is a legal or financial necessity (an overdue notice). The opt-out list is checked at
send time, not at audience-build time, so a late opt-out still works.

## Screens

Template manager with live preview · automation matrix · campaign builder with
audience preview · message log with status and cost · credit wallet and top-up ·
opt-out list · in-app notification centre (bell).

## Events

Emits: `SmsQueued`, `SmsSent`, `SmsFailed`, `CreditLow`.

Listens: everything — `InvoiceFinalised`, `TicketStatusChanged`,
`TicketReadyForPickup`, `TicketAbandoned`, `InstallmentOverdue`, `ChequeDueSoon`,
`PartyBirthday`. This module is the main consumer of the event bus, which is exactly
why cross-module contact is event-based ([ADR 0003](../adr/0003-modular-monolith.md)).

## Acceptance

- A fake driver asserts the exact payload, pattern code and variable values.
- Every automation in the matrix fires on its event, once, and not when toggled off.
- Credit is deducted on send and **refunded** on gateway failure.
- Campaign audience filters return exactly the expected parties on a seeded CRM.
- Opt-out is respected at send time by campaigns and automations alike.
- No test ever reaches a real provider.
- Cross-tenant isolation on every endpoint.

## Out of scope

Email marketing. Web push (a later phase). WhatsApp/Telegram sending. Two-way SMS
conversations.
