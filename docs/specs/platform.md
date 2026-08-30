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
- `plan_limits` — one row per (plan, metric): the monthly credit that plan grants.
  `NULL` means unlimited.
- `usage_counters` — one row per (tenant, metric, period key), the spend so far.
  `tenant_limit_overrides` — a per-shop grant that beats the plan. `usage_events` — what
  the panel reports on.
- `subscription_invoices`, `payments_gateway_logs`, `coupons`, `sms_price_tiers`,
  `platform_users`, `announcements`.

`subscription_addons` and `plan_module` were here until 0.16.0. They encoded the old
product — a plan as a *bundle of modules*, with the rest sold one at a time — and were
dropped one release after the last code that read them (the blue/green rule in
`docs/VERSIONING.md`).

## Behaviour

### Feature gating — the kill-switch only

Since DECISION GATE 6 a plan does not decide which modules a shop may reach; every module
is open to every shop. What remains is `modules.is_enabled`, a **platform** switch for a
module with nothing behind it (Moadian, ADR 0011) or one we have taken down:

- `EnsureModuleEnabled` middleware on the route → 403.
- The `features` shared Inertia prop → the nav item is hidden.

Hiding the UI is not authorization. A switched-off module must 403 on a direct URL.

### Limits — what a plan actually sells

`QuotaGuard` meters 24 metrics (ADR 0018) across two windows:

- **`Month`** — a credit that refills on the 1st of each Jalali month at 00:00 Tehran.
  Invoices, repair intakes, SMS, imports, and so on. Counted at the moment of the write.
- **`Total`** — a standing capacity measured, not counted: seats, branches, live
  price-list links, storage. Deleting frees the slot, because the cap is on how much
  exists at once rather than on how often you act.

The limit for a shop is the first of: a `tenant_limit_overrides` row, its plan's
`plan_limits` row, the free plan's row. A lapsed subscription therefore falls back to the
free rung rather than locking the shop out (Gate 6).

Consumption is one statement — `INSERT … ON CONFLICT … DO UPDATE … WHERE used +
EXCLUDED.used <= limit RETURNING used` — so two concurrent tills cannot both take the last
credit. It runs **inside the transaction that writes the row it counts**, so a failed save
never spends a credit and a spent credit always has a row behind it.

On exhaustion the behaviour is **soft-lock**: warn from 80%, then block the *create* while
leaving everything read-only and exportable, with the next plan up named in the block.
A shop that hits its invoice cap mid-month must still be able to look up a customer.
Voids and returns do not refund quota (Gate 6); a repair-delivery invoice is exempt from
`sales.invoices`, because it bills work already metered as a repair.

### Proration

Documented in `docs/adr/0006-proration.md` (written when Phase 2 starts, signed off at
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

Plus the quota screens: a plan's **limit matrix** edited in place (one field per metric,
blank = unlimited), a per-shop **override** that beats the plan without moving it, a usage
page showing what each shop has spent this month, and a **blocked shops** widget — the
one that turns a limit into a sales conversation instead of a silent churn.

Filament renders **only** the central panel. It never renders a tenant screen.

## Events

Emits: `TenantCreated`, `SubscriptionActivated`, `SubscriptionExpired`,
`PlanChanged`, `ImpersonationStarted`.

Quota emits two, both carrying tenant, metric, limit and spend: `QuotaWarning` when a
shop crosses 80% of a metric, and `LimitReached` when a consume is refused. They are
events rather than direct calls because the interesting listeners are in other modules —
Messaging wants to warn the owner, Platform wants the widget — and neither belongs inside
the write path of a sale.

## Acceptance

- Plan purchase happy path against a fake gateway.
- Proration maths, including same-day upgrade and downgrade-to-credit.
- Replayed payment callback is a no-op.
- Module switched off → 403 on the route **and** absent from the nav. Switched on → every
  shop reaches it, on every plan, including the free one.
- Limit exhaustion warns from 80%, then blocks creates while reads keep working, naming the
  next plan up in the block.
- **A refused consume writes no counter row, and a rolled-back write leaves none either.**
  Both directions, because the meter is only trustworthy if a spent credit always has a row
  behind it and a row always has a credit's work behind it.
- Two concurrent consumes of the last credit: exactly one succeeds. The counter is never
  read-then-written; it is one `INSERT … ON CONFLICT … WHERE used + EXCLUDED.used <= limit`.
- A lapsed subscription falls back to the free plan's limits rather than locking the shop
  out (Gate 6).
- The month rolls at 00:00 Tehran on the Jalali 1st — not at UTC midnight, and not on the
  Gregorian 1st. Period keys are stored as the Gregorian date of that boundary; a Jalali
  string is never stored (golden rule 5).
- Impersonation writes an audit record visible to the tenant.

## Out of scope

Multi-currency. Reseller/agency billing. Self-service plan design.
