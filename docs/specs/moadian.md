# Moadian (سامانه مودیان)

**Phase 10** · Module `app/Modules/Moadian` · feature-flagged

## Purpose

Iran's tax authority requires electronic invoices to be submitted to the Moadian
system. The rules and the technical interface change; several intermediary providers
(کیسان، مالیتور و…) sit between shops and the authority.

So this module is an **adapter first**. The MVP ships one intermediary driver behind a
queue, with a fake for tests. When the rules change — and they will — the change is
one driver, not the invoicing system.

## Data

- `moadian_invoices` — `sales_invoice_id`, `payload` (JSON, exactly what was sent),
  `status` (`pending` · `sending` · `accepted` · `rejected` · `failed`),
  `reference_number`, `tax_id`, `error_code`, `error_message`, `sent_at`,
  `confirmed_at`, `attempts`.
- `moadian_settings` — per tenant: `memory_id`, economic code, private key reference,
  provider selection, enabled flag.

The private key is encrypted at rest and never appears in a log or a response.

## Behaviour

### Adapter

```php
interface MoadianDriver
{
    public function send(MoadianPayload $payload): SubmissionResult;
    public function status(string $reference): SubmissionStatus;
    public function cancel(string $reference, string $reason): SubmissionResult;
}
```

Drivers: one real intermediary (chosen at **DECISION GATE 4**), plus `fake` for tests
and local development. `MOADIAN_ENABLED=false` by default — no development machine
ever submits a real tax document.

### Mapping

A sales invoice maps to the authority's payload: seller identity, buyer identity
(national/economic id where present), line items with tax codes, amounts in **rial**,
totals, discounts, VAT, and the invoice type. Mapping is pure and unit-tested against
fixture invoices, separately from any network call.

### Queue and retry

Submission is queued on the dedicated `moadian` queue with exponential backoff. The
service is genuinely unreliable, and a failed submission must never block a sale — the
shop finalises the invoice and the tax submission catches up.

### Error inbox

Rejections and failures land in a screen the shop owner can actually act on: what was
rejected, why, in Persian, with a resend button and, where the fix is a field on the
invoice, a link to it. Silent failures are the worst possible outcome — the shop finds
out at audit time.

### Cancellation and correction

Voiding a submitted invoice submits a cancellation. A corrected invoice submits as an
amendment referencing the original.

## Screens

Settings (provider, credentials, enable) · submission list with status filter · error
inbox with resend · per-invoice submission panel.

## Events

Emits: `MoadianSubmitted`, `MoadianAccepted`, `MoadianRejected`.

Listens: `InvoiceFinalised`, `InvoiceVoided`.

## Acceptance

- Driver contract tests pass against the fake, covering accept, reject and transport
  failure.
- Payload mapping matches fixtures exactly, with money as integer rial.
- A failed submission retries with backoff and never blocks invoice finalisation.
- Rejections appear in the error inbox with an actionable Persian message.
- Resend is idempotent — it does not create a duplicate submission.
- The private key never appears in a log, response or export.
- Disabled by feature flag → no submission attempted, no error surfaced.
- Cross-tenant isolation on every endpoint.

## Out of scope

Direct submission without an intermediary (a later phase). Full VAT return
preparation. Historical back-filling of pre-adoption invoices.
