# Platform

**Phase 2** · Module `app/Modules/Platform`

## Purpose

The business behind the product: tenants, plans, subscriptions, billing and the
super-admin panel. This is the **only** module that touches central (non-tenant) data,
and therefore the only place `withoutTenancy()` is permitted — with a comment saying
why.

## Data (central — no `tenant_id`)

- `tenants` — name, slug, status (`trialing` · `active` · `suspended` · `archived`),
  `trial_ends_at`, settings.
- `domains` — `subdomain` (unique), `tenant_id`, `is_primary`.
- `modules` — `code`, `name_fa`, `description`, `is_addonable`. Seeded from the module
  list in CLAUDE.md rule 6.
- `plans` — `name`, `interval` (`month` · `quarter` · `year`), `price` (rial),
  `trial_days`, `is_public`.
- `plan_module` — pivot.
- `plan_limits` — `users`, `branches`, `invoices_per_month`, `storage_mb`,
  `sms_credit_bonus`.
- `subscriptions` — `tenant_id`, `plan_id`, `status` (`trialing` · `active` ·
  `past_due` · `canceled`), `current_period_start/end`, `canceled_at`.
- `subscription_addons` — individually purchased modules.
- `subscription_invoices`, `payments_gateway_logs`, `coupons`, `sms_price_tiers`,
  `platform_users`, `announcements`.

## Behaviour

### Feature gating

Pennant resolves `module:<code>` and `limit:<key>` from the active subscription plus
add-ons. Two enforcement points, and both are required:

- `EnsureModuleEnabled` middleware on the route → 403.
- The `features` shared Inertia prop → the nav item is hidden.

Hiding the UI is not authorization. A disabled module must 403 on a direct URL.

### Limits

A usage-counter service tracks users, branches, invoices this month and storage. On
exhaustion the behaviour is **soft-lock**: warn as the limit approaches, then block
*create* actions while leaving everything read-only and exportable. A shop that hits
its invoice cap mid-month must still be able to look up a customer.

### Proration

Documented in `docs/adr/0004-proration.md` (written when Phase 2 starts, signed off at
**DECISION GATE 2**). The rule to implement:

```
unused_credit = plan_price × remaining_days ÷ period_days      (integer rial, truncated)
amount_due    = new_plan_price − unused_credit                 (floor 0)
```

Truncation favours the customer. A downgrade produces credit, never a refund.

### Payments

shetabit/multipay with the Zarinpal driver, sandbox first. Flow: create a subscription
invoice → init payment → redirect → callback → verify → activate.

**Verification is idempotent.** A replayed callback must not extend the subscription
twice or double-credit a wallet. Every gateway interaction is logged with its raw
payload for dispute resolution — Iranian gateways do go down mid-transaction.

Grace period: `past_due` keeps the tenant working for N days with escalating reminders
before features lock.

### Impersonation

A platform admin can enter a tenant as its owner for support. Every impersonated
session is audited — who, which tenant, when, and for how long — and the tenant's own
activity log shows the action was performed by support, not by the shop.

## Screens (Filament v4, central panel only)

Tenants · plans · modules · coupons CRUD; subscription overview; impersonate;
announcements; MRR and churn widgets; SMS credit package sales.

Filament renders **only** the central panel. It never renders a tenant screen.

## Events

Emits: `TenantCreated`, `SubscriptionActivated`, `SubscriptionExpired`,
`PlanChanged`, `LimitExceeded`, `ImpersonationStarted`.

## Acceptance

- Plan purchase happy path against a fake gateway.
- Proration maths, including same-day upgrade and downgrade-to-credit.
- Replayed payment callback is a no-op.
- Module disabled → 403 on the route **and** absent from the nav.
- Limit exhaustion warns, then blocks creates while reads keep working.
- Impersonation writes an audit record visible to the tenant.

## Out of scope

Multi-currency. Reseller/agency billing. Self-service plan design.
