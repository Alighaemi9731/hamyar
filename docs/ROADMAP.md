# MobiShop — Roadmap

**This file is the session entrypoint.** Every session: read `CLAUDE.md`, read this
file, take the next unchecked `[ ]` task top-to-bottom, implement it, run
`composer test`, tick the box, and append one line to `docs/PROGRESS.md`.

Rules that override convenience:

- A task is only ticked when `composer test` is green (Pint + Larastan L8 + Pest).
- Every tenant-scoped endpoint ships with a cross-tenant isolation test.
- **DECISION GATE** tasks stop the session. Present the summary, ask the human, wait.

Legend: `[ ]` todo · `[x]` done · `[~]` in progress (only one at a time) · `[!]` blocked

---

## Phase 0 — Foundation & Developer Experience

**Goal:** a booting RTL Laravel app, reproducible dev stack, green CI, and the
design-system skeleton — so every later phase is only about domain work.

### 0.1 Repository & scaffold
- [x] Scaffold Laravel 12 into the pre-seeded folder without clobbering `CLAUDE.md`, `.claude/`, `docs/`, `PROMPT.md`, `START-HERE-FA.md`
- [x] Merge Laravel's `.gitignore` into the existing one (append, never replace)
- [x] `git init` + first commit; keep `.claude/skills/` tracked, ignore `.claude/settings.local.json`
- [x] `.editorconfig`

### 0.2 Documentation baseline
- [x] `docs/ROADMAP.md` (this file — all phases as checkboxes, gates preserved)
- [x] `docs/PROGRESS.md`
- [x] `docs/architecture.md`
- [x] `docs/testing.md`
- [x] `docs/deploy.md`
- [x] `docs/design-system.md` (ported to English from `mobishop-ui` SKILL + sections 1–2 of `docs/03-design-and-claude-setup-fa.md`, including `#landing`)
- [x] `docs/adr/0001-stack.md`
- [x] `docs/adr/0002-single-db-tenancy-rls.md`
- [x] `docs/adr/0003-modular-monolith.md`
- [x] `docs/adr/0004-postgres-only-tests.md` (approved 2026-08-07)
- [x] `docs/adr/0005-rtl-direction-class-gate.md` (approved 2026-08-07)
- [x] `docs/adr/README.md` — ADR index
- [x] `docs/specs/README.md` + one spec file per module (18 modules)

### 0.3 Dev stack
- [x] `compose.yaml`: php-fpm app, nginx, postgres:16, redis:7, minio, mailpit
- [x] Postgres bootstrap: separate non-owner app role (RLS cannot be bypassed) + `_test` database
- [x] `Makefile` targets: `up`, `down`, `fresh`, `test`, `sh` (+ build/logs/psql/artisan/composer)
- [x] Document `*.app.localhost` host resolution in `docs/deploy.md#local-hostnames`
- [x] `.env.example` fully documented (every key commented)

### 0.4 Laravel core configuration
- [x] Locale `fa`, fallback `en`, storage timezone UTC, display timezone `Asia/Tehran` (never stored)
- [x] Postgres as default connection; Redis for cache/session/queue
- [x] `Model::preventLazyLoading()` + `preventSilentlyDiscardingAttributes()` outside production
- [x] `Number`/money helpers: IRR integer in, formatted string out — no floats
- [x] Jalali helpers over `morilog/jalali` (render only; storage stays UTC)

### 0.5 Modules
- [x] `php artisan make:module <Name>` generator (Providers, Http, Models, Services, Events, Policies, database/migrations, tests) + auto-registration
- [x] Create shells for all 18 modules from CLAUDE.md rule 6
- [x] Pest arch test: modules exist; Domain layer does not depend on Http

### 0.6 Quality gates
- [x] Pest v4 + arch plugin, `phpunit.xml` pointed at the `_test` database
- [x] Larastan level 8 (`phpstan.neon.dist`)
- [x] Pint (`pint.json`, Laravel preset + project rules)
- [x] `bin/check-direction-classes` — grep gate failing on any physical direction class
- [x] `composer test` = pint --test → phpstan → pest; `composer test:isolation`
- [x] GitHub Actions CI: lint → stan → test (real Postgres 16) → build assets

### 0.7 Frontend foundation
- [x] Inertia v2 + React 19 + TypeScript + Vite 7
- [x] Tailwind v4 with `@theme` design tokens in `resources/css/app.css`
- [x] RTL base layout: `<html dir="rtl" lang="fa">`, Vazirmatn (body) + Estedad (headings), self-hosted
- [x] Dark mode via CSS variables from day one
- [x] Shared Inertia props: `auth.user`, `tenant`, `features`, `flash`
- [x] `components.json` with `"rtl": true`; shadcn base kit (Button, Input, Select, Dialog, Sheet, Tabs, Table, Sonner, Command, Badge, Tooltip, DropdownMenu, Popover, Skeleton)
- [x] `dir="rtl"` passed to every Radix portal

### 0.8 Domain components (gallery-first)
- [x] `<Money/>` — IRR integer in, formatted out, `tabular-nums`
- [x] `<Num/>` — Persian digits in prose, Latin tabular digits in tables (tenant setting)
- [x] `<JDatePicker/>` — Jalali picker, UTC value out
- [x] `<StatusBadge/>` — single status→semantic-colour map
- [x] `<EmptyState/>` — actionable Persian copy
- [x] `/design` gallery route (dev-only): every component × state matrix (default/hover/focus/disabled/loading/error) × light+dark × 390px/1280px

### 0.9 App shell
- [x] Login placeholder page
- [x] App shell: RTL sidebar (fa-IR nav), topbar, toast host — built strictly from gallery components

### 0.10 AI tooling
- [x] `laravel/boost` installed; generated guidelines merged **below** the golden rules in `CLAUDE.md` (diff reviewed)
- [x] Laravel Boost MCP registered (`docker compose exec -T app php artisan boost:mcp`)
- [x] Playwright MCP registered
- [x] Confirm `.claude/skills/mobishop-ui/SKILL.md` loads

### Phase 0 — Definition of Done
- [x] `make up` then `make fresh` boots an RTL app
- [x] CI green on a PR — [#1](https://github.com/Alighaemi9731/mobishop/pull/1), all four jobs green, merged
      · **CI trigger investigation (resolved, 2026-08-07).** `push`/`pull_request` initially produced no runs, so only `workflow_dispatch` worked. Checked and ruled out: the workflow exists on the default branch (`main`), its `on:` block lists `push: branches: [main]` and an unfiltered `pull_request`, the branch names match, and repo Actions policy is `enabled: true` / `allowed_actions: all`. The real cause was **event-delivery latency on a brand-new private repo**: the `pull_request` runs did eventually appear, roughly 25 minutes behind each push, and the delay has since fallen to about 2 minutes. Nothing to click and nothing to change — it resolved on its own. If it ever recurs: Settings → Actions → General → "Allow all actions", and confirm Actions is not disabled at the account level.
- [x] Arch test asserts module boundaries
- [x] `/design` renders the initial kit
- [x] Boost MCP answers an Application Info call
- [x] Zero physical direction classes (grep gate wired into `composer test`)

---

## Phase 1 — Tenancy, Identity, Onboarding ★ security-critical

### 1.1 Central schema
- [x] `tenants` table (name, slug, status, trial_ends_at, settings)
- [x] `domains` table (subdomain unique, tenant_id, is_primary)
- [x] Platform admin seed (central users, separate guard from tenant users)

### 1.2 Tenant context
- [x] `TenantContext` service — resolve by subdomain; no subdomain → central routes
- [x] Middleware sets context + `SET LOCAL app.tenant_id` per request
- [x] Transaction-safe: context re-applied on every connection/transaction start
- [x] Queue jobs serialise tenant id and restore context before handling (tested)
- [x] Artisan/console context switching (`--tenant=` option) for maintenance commands — `InteractsWithTenants` + `tenancy:sync-permissions` as its first user

### 1.3 BelongsToTenant + RLS
- [x] `BelongsToTenant` trait: global scope, creating-hook fill, `tenant()` relation
- [x] `withoutTenancy()` escape hatch — Platform module only, requires a comment
- [x] Migration helper `$this->enableRls('table')` → policy `USING (tenant_id = current_setting('app.tenant_id')::bigint)` + `FORCE ROW LEVEL SECURITY`
- [x] Apply RLS to `users`
- [x] `php artisan tenancy:check` — fails CI when a tenant-ish table/model lacks the trait or RLS

### 1.4 Auth & onboarding
- [x] Onboarding wizard: shop name → subdomain (availability check) → owner user → demo-data toggle
- [x] Per-tenant login, password reset, remember-me
- [x] Optional TOTP 2FA + recovery codes
- [x] Session management screen (active sessions, revoke)
- [x] Argon2id hashing; rate limits on login/OTP

### 1.5 Roles & permissions
- [x] spatie/laravel-permission with `teams = tenant_id`
- [x] Seed 7 roles: Owner, Manager, Cashier, Salesperson, Technician, Accountant, Warehousekeeper
- [x] Permission catalogue `module.action`, default sets per role
- [x] Policies scaffolded per module (UserPolicy, ActivityPolicy; Owner `Gate::before` with structural-invariant exceptions)

### 1.6 User management UI
- [x] Invite by mobile/email; accept-invite flow
- [x] Activate/deactivate users
- [x] Assign roles (last-Owner guard); branch pivot deferred to Phase 3 with `branches`
- [x] Activity log wired (spatie/laravel-activitylog) + read-only viewer

### 1.7 Tests
- [x] Registration/onboarding flow
- [x] Login, password reset and 2FA covered (37 tests across login/reset/2FA)
- [x] Permission denial matrix for every Identity endpoint; full role × endpoint matrix grows with each module's endpoints
- [x] **Isolation suite v1**: tenant B → tenant A resource ids = 403/404
- [x] Raw-query RLS test proving leakage impossible without the Eloquent scope
- [x] Queued job executes under the correct tenant

### Phase 1 — Definition of Done
- [x] Two demo tenants seeded
- [x] `composer test:isolation` green and wired into CI

> ### ✅ DECISION GATE 1 — CLEARED 2026-08-07
> - **Subdomain scheme approved as designed**: charset rules, no `--`, hostname stored
>   as `domains` rows, 48 reserved names, 30-char ceiling.
> - **`mobishop.ir` is NOT confirmed** — a working name only. The apex domain stays
>   strictly configurable (golden rule 1b); choosing/registering it is a Phase 11 task.
> - **Role list approved**: all seven names, plus both boundaries — Salesperson blind to
>   cost/profit (per-tenant owner override allowed), and `repairs.reveal_passcode` as a
>   separate, always-audited permission.
> - **ADR 0007 approved**; CLAUDE.md golden rule 1 amended to match the implementation.

---

## Phase 2 — Plans, Modules, Billing, Super-admin

### 2.1 Registry & plans
- [x] `modules` registry (code, name_fa, description, is_addonable) seeded from CLAUDE.md rule 6
- [x] `plans` (interval month/quarter/year, price IRR, trial_days)
- [x] `plan_module` pivot
- [x] `plan_limits` (users, branches, invoices_per_month, storage_mb, sms_credit_bonus)

### 2.2 Subscriptions
- [x] `subscriptions` (tenant, plan, status trialing/active/past_due/canceled, period_end)
- [x] Add-on purchases table
- [~] Proration formula written and unit-tested (`ProrationCalculator`, 11 cases); **ADR 0006 is Proposed — needs sign-off at Gate 2** before the upgrade/downgrade flow is wired to payments
- [~] `coupons` table + trial + grace period modelled and enforced by `Subscription::isUsable()`; redemption flow lands with billing

### 2.3 Feature gating (trial rules per Gate 2 item 3: Pro features, 14 days, no card,
zero bonus SMS, Basic invoice cap — `TrialPolicy`)
- [~] Module grants resolved from plan + add-ons via `SubscriptionResolver` (fails closed). Pennant `limit:<key>` flags land with the usage counters below
- [x] `EnsureModuleEnabled` route middleware
- [x] `features` shared Inertia prop; nav hides disabled modules
- [ ] Usage counters service; soft-lock behaviour (warn → block create actions)

### 2.4 Payments
- [x] `payment_attempts` and `subscription_addons` are real tenant tables with FORCE RLS
      (Gate 2 item 0, done before any endpoint existed)
- [x] Zarinpal driver (sandbox) behind a `PaymentGateway` interface, plus `FakeGateway`
      for tests. Talks to Zarinpal's REST API directly rather than through multipay's
      drivers, which redirect and `die()` and cannot be wrapped or tested
- [x] `counters` table + `CounterService` (row lock, per tenant, never MAX+1)
- [x] Subscription invoices; payment init / callback / verify
- [x] Idempotent verification — unique authority + `FOR UPDATE` + status check under
      the lock; replay cannot double-extend the period
- [x] Amount-tampering guard: a gateway-settled amount that differs from the invoice is
      refused rather than trusted
- [x] Receipt page
- [x] Renewal reminders — `SendRenewalReminders` at 7/3/1 days, emits
      `SubscriptionRenewalDue` inside each tenant's context; Messaging picks the channel
- [ ] Coupon redemption flow (table exists; UI and validation land with the panel)

### 2.5 Filament v4 central panel
- [x] Panel restricted to platform admins — central domain only (`->domain()`), own
      `platform` guard, deactivation locks out on the next request
- [x] CRUD: tenants, plans, modules, coupons. Deliberately narrowed: plans and modules
      cannot be deleted (subscriptions and invoices reference them), tenants cannot be
      created by hand (onboarding provisions roles + trial + domain in one transaction),
      modules cannot be created at all (they are code)
- [x] Subscription overview — read-only across every shop. No edit: a subscription is the
      consequence of invoices and payments, and editing it directly would grant access no
      money explains
- [x] Impersonate tenant owner (audited) — mandatory reason, audit row written BEFORE the
      session exists and into the *shop's own* activity log so owners can audit us, then a
      two-minute signed link minted on the shop's hostname
- [x] Announcements — global or targeted at one shop, with a display window; surfaced in
      the tenant app shell
- [x] MRR / churn dashboard widgets — MRR from live subscriptions (not summed invoices,
      which include prorations), plus collected-this-month, trials and past-due
- [ ] SMS credit package sales — **deferred to Phase 8 (Messaging)**. Selling credit
      needs a credit ledger to sell into, and Messaging owns that design; inventing a
      `sms_packages` table now would prejudge it and produce a second balance to
      reconcile. Tracked as a Phase 8 task instead.

### 2.6 Tests
- [x] Plan purchase happy path with a fake gateway (17 cases in `BillingPaymentTest`)
- [x] Proration maths unit tests (11 cases, exact expected rial)
- [x] Feature gating: module off → 403 + hidden nav
- [x] Billing tables isolated: cross-tenant read/write denied by RLS, and the
      `runAsPlatform()` hatch proven narrow (`PlatformBillingIsolationTest`)
- [ ] Limit exhaustion behaviour
- [x] Impersonation writes an audit record (into the tenant's own log, with the reason)

### Phase 2 — Definition of Done
- [x] A plan can be bought in sandbox, features unlock, everything manageable in Filament

> ### ✅ DECISION GATE 2 — CLEARED 2026-08-08
> Plan structure approved as built. Prices approved as **provisional business data**,
> kept Filament-editable, with competitor validation added to the Phase 11 checklist.
> Proration accepted as ADR 0006 stands (truncate to 1 rial; round-half-up considered and
> rejected). Trial confirmed: Pro features, 14 days, no card, zero bonus SMS, Basic
> invoice cap. Storefront confirmed as a real module; golden rule 6 amended to 18.
> Plus item 0: the billing child tables were made properly tenant-scoped *before* any
> 2.4 endpoint existed.

---

## Phase 3 — Catalog, Inventory, Serialized Units, Purchasing

### 3.1 Branches & warehouses
- [x] `branches`, `warehouses` (per-branch defaults, enforced by partial unique indexes)
- [x] Every new shop gets one of each via `TenantProvisioned` → `CreateDefaultLocation`,
      inside the signup transaction (a shop that cannot receive stock is worse than a
      failed signup)
- [x] `counters` gained `branch_id` — the settings spec always said per-branch, and the
      Phase 2.4 table did not. Fixed before Phase 5 numbers a single sales invoice
- [x] User↔branch restriction via `branch_user` + `BranchAccess`. **No rows means every
      branch** — restriction is opt-in, so single-branch shops never configure it

### 3.2 Catalog
- [x] Categories tree (adjacency list), brands with Latin + Persian names
- [x] `products` (type: standard | serialized) — `ProductType` enum
- [x] Variants via `VariantMatrix`: cartesian product, order-independent fingerprint,
      regeneration **deactivates** rather than deletes (stock and invoice history)
- [x] Barcodes/SKUs unique per tenant among LIVE rows only — partial indexes, so NULLs
      do not collide and a retired line does not hold its barcode hostage
- [x] Price levels seeded per tenant on provisioning (مصرف‌کننده/همکار/همکار ویژه);
      `product_prices` append-only so a past month can be re-derived
- [x] `PriceResolver` — newest row whose `effective_from` has passed, falling back to the
      default level; a scheduled increase does not apply early
- [x] Bulk price update: `preview()` and `apply()` share one code path, and `apply()`
      consumes the preview's rows so nothing can change in between
- [ ] Catalog UI screens (category tree, product editor, price grid) — schema and services
      are done and tested; the Inertia pages land with the Phase 3 UI pass

### 3.3 Serialized units
- [x] `product_units`: imei1/imei2/serial, condition, grade, per-unit cost, status enum,
      acquired-from party, HAMTA fields, warranty, notes
- [x] `Imei` helper: Luhn validation, Persian/Arabic digit normalisation, TAC extraction.
      IMEIs are normalised on save, so a number typed in Persian and one scanned from a
      box land in the column identically
- [x] IMEI uniqueness per tenant — partial unique indexes **plus a trigger** for the
      cross-column case (one device's `imei2` reused as another's `imei1`), which indexes
      alone cannot express
- [x] `UnitStateMachine` + `product_unit_histories`; illegal transitions rejected, most
      importantly `sold → in_stock` (undoing a sale is a *return*, with a credit document)
- [x] Every transition writes history in the same transaction — a transition with no
      history row is a hole in the passport
- [ ] Media attachment (seller ID scan) — lands with the Files module wiring
- [ ] IMEI passport page — data model and history are complete and tested; the screen
      lands with the Phase 3 UI pass

### 3.4 Stock ledger

- [x] `stock_movements` (10 types + polymorphic reference to the causing document)
- [x] Quantity-on-hand = SUM with a covering index, never a stored total (golden rule 3).
      `onHandForMany()` for list screens, and an `$at` parameter so a valuation can ask
      what the figure was on a past date — which a stored total could never answer
- [x] Negative stock blocked by default, opt-in per warehouse
- [x] Sign guards: a negative purchase or a positive sale is refused, and a zero-quantity
      movement is rejected by both the service and a CHECK constraint
- [x] Transfers as two movements, one per side; stock counts reconcile by writing the
      *difference*, so the correction stays visible
- [ ] Low-stock threshold + alerts list — `products.low_stock_threshold` exists; the
      alerts screen lands with the Phase 3 UI pass
- [ ] Dead-stock report base — Phase 9 (Reporting)

### 4.1 Parties — **moved ahead of 3.5** (see the note there)
- [x] Unified `parties` (kind: customer/supplier/colleague/both). `kind` is a label for
      filtering, NOT a restriction — the same person sells you a trade-in and buys a
      charger in one visit, and a data-entry dead end at the counter is worse
- [x] `party_contacts` (phone numbers normalised to Latin digits on save, because the
      counter searches by number constantly), `party_addresses`, tags
- [x] Credit limit (nullable — null is "nobody decided", distinct from zero) and
      opening balance, the one stored figure and only a starting point
- [x] National-id uniqueness per tenant, partial so walk-ins without one are fine
- [x] FK from `product_units.acquired_from_party_id`, waiting since 3.3

### 4.2 Ledger engine — **moved ahead of 3.5**
- [x] `ledger_entries` with party and account dimensions, separate debit/credit columns
      (the layout an Iranian bookkeeper expects on a printed statement), polymorphic
      reference and a `batch_id` grouping the lines of one event
- [x] `LedgerService` is the only writer. Postings must balance and need ≥2 lines; the
      database enforces the shape of a row (one side only, at least one subject) and the
      service enforces the balance of a set, which SQL cannot express per-insert
- [x] Balances are `SUM(debit) - SUM(credit)` + opening balance, never stored
- [x] Minimal `accounts` + one default cash account per shop at provisioning
- [x] `statement()` with a running balance whose closing figure is asserted equal to
      `partyBalance()`; a windowed statement folds earlier entries into its opening
- [x] `reverse()` writes the mirror image and never deletes
- [x] Credit limit is a **warning with data**, not a block (spec)
- [ ] Receive/pay quick-forms — the service is done; the screens land with the UI pass

### 3.5 Purchasing
> **⚠️ RUN 4.1–4.2 FIRST — reorder approved 2026-08-09.**
> Purchasing needs suppliers, suppliers are parties, and `parties` is created in
> **Phase 4.1**. Rather than land a minimal `parties` table here and let four later
> phases extend it, Phase 4.1 (parties) and 4.2 (ledger engine) run before this section
> and Purchasing is then built whole. `product_units.acquired_from_party_id` gets its FK
> as part of 4.1. Everything else in Phase 4 keeps its original slot.
> Rationale: a half-built table extended by four phases accumulates migrations and
> assumptions that nobody revisits; the FK either exists or the intake screen cannot
> record who a phone was bought from, which is half the IMEI passport.
- [ ] Suppliers as parties (requires 4.1)
- [ ] Purchase invoices: standard lines and/or bulk serialized intake (paste/scan IMEIs)
- [ ] Landed cost allocation into unit cost
- [ ] Purchase returns
- [ ] GRN print

### 3.6 Movement operations
- [ ] Transfers between warehouses/branches — dispatch + receive confirmation
- [ ] Stock count sessions (blind count option) → adjustment movements

### 3.7 Labels
- [ ] Price/barcode labels, single + batch, printable HTML at label sizes

### 3.8 Tests
- [ ] Ledger invariants (no negative stock unless the allow-flag is set)
- [ ] Unit state transitions incl. illegal ones
- [ ] IMEI uniqueness per tenant
- [ ] Two-step transfer flow
- [ ] Price-level resolution
- [ ] Purchase cost maths including landed costs
- [ ] Cross-tenant isolation for every new endpoint

### 3.9 UI pass — one coherent pass, after 4.1–4.2 and 3.5
Built together rather than piecemeal, so the screens share components instead of
converging on them later.
- [ ] Category tree
- [ ] Product editor with the variant matrix
- [ ] Price-level grid + bulk update with preview
- [ ] **IMEI passport page** — the product's signature screen
- [ ] Stock views + low-stock alerts
- [ ] Label printing (price/barcode, single + batch)
- [ ] Every screen built from `/design` gallery components (golden rule 9)
- [ ] Verified with Playwright at 390 and 1280, light and dark, RTL

### Phase 3 — Definition of Done
- [ ] Receive 10 phones by pasting IMEIs → in stock → print labels → transfer 2 to branch B → count stock, and every number reconciles with movements
- [ ] The 3.9 screens exist, are built from gallery components, and have been verified in
      a real browser at both breakpoints in both themes

---

## Phase 4 — Parties CRM (Customers & Suppliers)

### 4.1 Parties — **relocated to Phase 3, ahead of 3.5** (reorder approved 2026-08-09)
### 4.2 Ledger engine — **relocated to Phase 3, ahead of 3.5**

### 4.3 Engagement
- [ ] 360° timeline component (sales, repairs, sms, payments, notes)
- [ ] Follow-up reminders (assignee, due date, done)
- [ ] Loyalty points table + earn-rule stub

### 4.4 Import
- [ ] Excel customer import with column-mapping wizard + dry-run report

### 4.5 Tests
- [ ] Ledger maths: statement equals the sum of entries
- [ ] Credit-limit block flag on over-limit credit sale
- [ ] Import edge cases (duplicate mobiles, bad-row report)
- [ ] Cross-tenant isolation

### Phase 4 — Definition of Done
- [ ] Customer page shows a true balance and full timeline; a 500-row sheet imports cleanly

---

## Phase 5 — Sales, POS, Trade-in, Installment creation ★ revenue-critical

### 5.1 Invoices
- [ ] Lifecycle draft → final → void
- [ ] Scan-first POS screen (barcode/IMEI box autofocus, Enter submits)
- [ ] Line types: variant + qty | serialized unit picker (only `in_stock` units of this branch)
- [ ] Per-line discount and warranty months
- [ ] Invoice-level discount, VAT toggle, shipping
- [ ] Salesperson field + commission accrual entry
- [ ] Numbering per tenant+branch via the `counters` row-lock service

### 5.2 Payments
- [ ] Split payments: cash / POS terminal / card-to-card / cheque ref / customer credit
- [ ] Change calculation
- [ ] Partial payment → unpaid balance posts to the party ledger automatically

### 5.3 Returns & quotes
- [ ] Returns full/partial; serialized return flips unit returned → in_stock with grade re-check
- [ ] Quotes → convert to invoice

### 5.4 Trade-in
- [ ] "Buy customer device" line inside POS → mini-intake (model, imei, condition, agreed price, ID scan, HAMTA transfer checklist ack)
- [ ] Creates a purchase + `product_unit` (used) and offsets the invoice total

### 5.5 Installment sale
- [ ] Mark invoice as installment → wizard (down payment, count, interval, flat profit %, first due Jalali)
- [ ] Generates `installment_plan` + rows; last row absorbs rounding remainder
- [ ] Optional guarantor party
- [ ] Contract print

### 5.6 Printing
- [ ] Thermal 80mm receipt
- [ ] A5 / A4 official invoice
- [ ] QR to a public invoice view
- [ ] Template settings (logo, footer terms)

### 5.7 Profit engine
- [ ] Serialized = exact unit cost; standard goods = weighted-average cost at sale time
- [ ] Cost snapshot stored on the invoice line
- [ ] Daily Z-report (cash session close)

### 5.8 Tests
- [ ] Full POS happy path
- [ ] Split payment maths incl. change and ledger postings
- [ ] Serialized double-sell race: two parallel finalises → one wins, other gets a clean error
- [ ] Trade-in creates the unit and the totals are correct
- [ ] Installment schedule maths and rounding rule
- [ ] Return restores stock and reverses the ledger
- [ ] Numbering: no gaps/dupes under 50 parallel finalises
- [ ] Cross-tenant isolation

### Phase 5 — Definition of Done
- [ ] End-to-end: buy plan → receive stock → sell a phone with trade-in + 3 cheques + installments → print all papers → every ledger/stock figure reconciles

> ### ⛔ DECISION GATE 3
> Review invoice print templates and rounding rules with the human.

---

## Phase 6 — Repairs ★ flagship module

### 6.1 Intake
- [ ] Customer, device (brand/model/imei — link to a unit if we sold it)
- [ ] Reported issue
- [ ] Configurable checklist + per-tenant template builder
- [ ] Photos
- [ ] Passcode/pattern: encrypted at rest, masked in UI, permission-gated reveal
- [ ] Accessories list, prepaid amount, estimate
- [ ] Printed receipt with tracking code + QR

### 6.2 Workflow
- [ ] State machine: queued → diagnosing → awaiting_approval → awaiting_parts → repairing → ready → delivered | rejected | abandoned
- [ ] Every transition emits an event (SMS hooks in Phase 8)
- [ ] History with actor + note
- [ ] Kanban board + list views
- [ ] Technician assignment, priority, promised date
- [ ] Per-technician workload screen

### 6.3 Estimate & approval
- [ ] Amount cap without approval (tenant setting)
- [ ] Approval via public signed link, or manual "approved by phone" with note

### 6.4 Parts & services
- [ ] Parts consumption from Inventory: reserve → consume on complete → return on cancel
- [ ] Labor/services catalogue (flash, unlock, FRP, data recovery…) with prices
- [ ] Outsource to an external technician party with cost tracking

### 6.5 Delivery
- [ ] Payment settle (reuses the Phase 5 payment box)
- [ ] Signature pad capture stored as an image
- [ ] Warranty-on-repair days
- [ ] Delivered receipt print

### 6.6 Abandoned devices
- [ ] Configurable N days after ready → flag + escalating SMS steps + status

### 6.7 Public tracking
- [ ] No-login page: code → status timeline + shop contact, signed URL

### 6.8 Tests
- [ ] Every legal and illegal transition
- [ ] Approval cap enforcement
- [ ] Parts reserve/consume/rollback
- [ ] Concurrent technician actions
- [ ] Encrypted passcode never appears in logs or JSON
- [ ] Public page leaks nothing tenant-private
- [ ] Abandoned scheduler
- [ ] Cross-tenant isolation

### Phase 6 — Definition of Done
- [ ] Full repair lifecycle demo on seeded data; board usable; tracking page live

---

## Phase 7 — Treasury, Expenses/Incomes, Cheques, Installment collection, Rentals

### 7.1 Accounts
- [ ] Cash boxes, banks, POS terminals, petty cash
- [ ] Opening balances
- [ ] Transfer between accounts
- [ ] Account statement (ledger view)
- [ ] Simple reconciliation check-off

### 7.2 Expenses & incomes
- [ ] Expense/income modules with category trees
- [ ] Recurring templates (rent, salaries)
- [ ] **Rental module**: contracts for leasing shop desk/space (party, amount, period, start/end) → auto-generated periodic income entries + reminder

### 7.3 Cheques
- [ ] Received/issued, full lifecycle: in_hand → deposited → cleared | bounced | spent_to_third_party | returned
- [ ] Due-date reminders list
- [ ] Printable receipt
- [ ] Ledger posting matrix documented in `docs/specs/cheques.md`

### 7.4 Installment collection
- [ ] Due/overdue lists
- [ ] Collect via any payment method
- [ ] Late-fee policy
- [ ] Early settlement recalculation
- [ ] SMS nudge hooks

### 7.5 Closing & reporting base
- [ ] Daily close (Z) across accounts
- [ ] Simple P&L (period revenue, COGS from cost snapshots, expenses)
- [ ] Cash-flow summary

### 7.6 Tests
- [ ] Cheque lifecycle postings at every stage
- [ ] Installment collection and early-settlement maths
- [ ] Recurring generator idempotency
- [ ] P&L reconciles against a fully seeded scenario
- [ ] Cross-tenant isolation

### Phase 7 — Definition of Done
- [ ] A seeded "one crazy month" scenario reconciles to the rial across all reports

---

## Phase 8 — Messaging (SMS) & Notifications

### 8.1 Drivers
- [ ] Driver abstraction
- [ ] Kavenegar driver (pattern/lookup send)
- [ ] sms.ir stub
- [ ] Per-tenant credentials vs platform-pool billing by credits
- [ ] Credit wallet, price tiers, low-credit alert

### 8.2 Templates & automations
- [ ] Template manager with variables (`{name}`, `{ticket_code}`, `{amount}`, `{due_date_j}`…)
- [ ] Automation toggle matrix: invoice finalized, repair status changes, ready, installment T-3/T-0/overdue, cheque T-2, birthday, abandoned-device steps

### 8.3 Campaigns
- [ ] Audience builder over CRM filters (last purchase, brand owned, tags, balance)
- [ ] Schedule + throttled queued sending
- [ ] Per-message status / delivery polling
- [ ] Opt-out list honoured everywhere

### 8.4 In-app
- [ ] Notification centre (bell) fed by the same events

### 8.5 Tests
- [ ] Driver fake asserting exact payloads
- [ ] Automation matrix
- [ ] Credit deduction accuracy incl. refund on gateway failure
- [ ] Campaign filter correctness on a seeded CRM
- [ ] Opt-out respected
- [ ] Cross-tenant isolation

### Phase 8 — Definition of Done
- [ ] Changing a repair status fires the right pattern SMS in sandbox/fake and logs the cost

---

## Phase 9 — Dashboard & Reporting

### 9.1 Dashboard
- [ ] Role-aware widgets per `docs/specs/reporting.md`
- [ ] Fast SQL: indexed, no N+1, measured

### 9.2 Reports (25+)
- [ ] Sales daily/monthly
- [ ] Sales by product / brand / salesperson
- [ ] Profit report
- [ ] Technician performance
- [ ] Dead stock
- [ ] Stock valuation
- [ ] Party balances aging
- [ ] Cheques calendar
- [ ] Installments book
- [ ] Tax/VAT summary
- [ ] SMS usage
- [ ] …remaining reports enumerated in `docs/specs/reporting.md`
- [ ] All with Jalali range filter, print CSS, Excel export
- [ ] Saved-filter presets

### 9.3 Tests
- [ ] Golden-number tests: seeded scenario → exact expected figures per report
- [ ] Query performance budget (<300ms on a 100k-row seed for top reports)

### Phase 9 — Definition of Done
- [ ] Numbers everywhere agree with the Phase 7 reconciliation scenario

---

## Phase 10 — Multi-branch polish, Storefront, HAMTA, Moadian v1, Data tools

### 10.1 Multi-branch audit
- [ ] Every module respects branch context
- [ ] Branch switcher
- [ ] Consolidated vs per-branch reporting toggle
- [ ] Per-branch counters verified

### 10.2 Storefront
- [ ] Public shop landing page + product catalogue with live prices
- [ ] Reseller price-list link (password/expiry, per-price-level)
- [ ] PDF export
- [ ] WhatsApp CTA

### 10.3 HAMTA
- [ ] Guided ownership-transfer workflow on used buy/sell (checklist, activation-id record on unit)
- [ ] "Transfer pending" warnings
- [ ] `*#7777#` instructions page
- [ ] UI states explicitly: no official API — record-keeping and guidance only

### 10.4 Moadian v1
- [ ] Adapter interface + one intermediary-provider driver behind a queue
- [ ] Invoice → e-invoice payload mapping
- [ ] Send / poll status
- [ ] Error inbox + resend
- [ ] Feature-flagged

### 10.5 Data tools
- [ ] Full tenant export (Excel/JSON zip)
- [ ] Products import
- [ ] Backup-request button (admin-side artisan)
- [ ] Audit-log viewer UI with filters

### 10.6 Tests
- [ ] Price-list link security (expiry, password, price level)
- [ ] Storefront leaks nothing private
- [ ] Moadian driver contract tests with a fake
- [ ] Export completeness snapshot

> ### ⛔ DECISION GATE 4
> Choose the real Moadian intermediary provider and confirm storefront scope before building 10.2/10.4.

---

## Phase 11 — Hardening, Performance, Launch

### 11.1 Security
- [ ] OWASP ASVS-L1 checklist in `docs/security.md`
- [ ] Rate limits: login, OTP, public tracking/price-list pages
- [ ] Signed-URL audit
- [ ] Security headers + CSP
- [ ] Dependency audit in CI
- [ ] Secrets hygiene review
- [ ] Encrypted-columns inventory (device passcodes!)
- [ ] Impersonation & RLS re-verification

### 11.2 Performance
- [ ] Seed 50 tenants × realistic volumes
- [ ] Load test top 10 endpoints (k6 or artisan bench)
- [ ] Fix N+1s
- [ ] Add missing composite indexes
- [ ] Queue latency dashboards

### 11.3 Ops
- [ ] Production compose + nginx + SSL guide in `docs/deploy.md`
- [ ] Zero-downtime deploy script
- [ ] Nightly `pg_dump` + WAL archiving
- [ ] Restore drill documented **and performed once** (log committed)
- [ ] Sentry + health endpoint + uptime hook
- [ ] Horizon production config

### 11.4 Launch kit
- [ ] **Choose, register and configure the production apex domain.** `mobishop.ir` is a
      working name only — nothing may hardcode it. Set `APP_DOMAIN`, provision the
      wildcard TLS certificate for `*.<domain>`, migrate existing `domains.hostname`
      rows, and re-check every place a hostname surfaces: printed receipts,
      repair-tracking QR codes, reseller price-list links, SMS templates, emails.
      (CLAUDE.md golden rule 1b.)
- [ ] **Validate final pricing against Iranian competitors before launch.** The Gate 2
      numbers (Basic ۲۹۰k / Pro ۵۹۰k / Enterprise ۱,۱۹۰k toman, and the eight add-on
      prices) were approved as *provisional business data*, not as final. They live in
      the database and are editable in the Filament panel — `PlanCatalogue` only seeds a
      fresh install, so changing them at launch is a panel edit, not a deploy. Check them
      against what Iranian shop-management products actually charge, confirm the add-on
      stack still prices above the plan that contains it, and re-check the ladder against
      inflation since 1405.
- [ ] Demo tenant with rich Persian data
- [ ] 5-minute owner onboarding tour
- [ ] Terms + privacy pages
- [ ] Public landing page per `docs/design-system.md#landing`: signature live thermal receipt hero, specced section order, Blade + Tailwind, JS ≤ 180KB gz, LCP < 2.5s on 4G, `prefers-reduced-motion` honoured

> ### ⛔ DECISION GATE 5
> Show the landing at wireframe and first-styled-pass before polishing.

### Phase 11 — Definition of Done
- [ ] Staging deploy from CI
- [ ] Restore drill log committed
- [ ] Load test report in `docs/`
- [ ] Go-live checklist all green

---

## Anti-goals — do NOT build unless explicitly asked

Native mobile apps · full online-ordering/e-commerce checkout · double-entry GL beyond
the specified ledgers · multi-currency · Kubernetes · GraphQL · microservices.
