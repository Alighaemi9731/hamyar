# Hamyar — Roadmap

**This file is the session entrypoint.** Every session: read `CLAUDE.md`, read this
file, take the next unchecked `[ ]` task top-to-bottom, implement it, run
`composer test`, tick the box, and append one line to `docs/PROGRESS.md`.

Rules that override convenience:

- A task is only ticked when `composer test` is green (Pint + Larastan L8 + Pest).
- **A box describing user-facing behaviour ticks only when a route and a screen reach
  it.** A green service is not a shipped feature. Phase 6 nearly closed with
  `TicketParts` — reserve, consume, release, and the cross-module test proving a reserved
  screen is invisible to the till, all passing — and *no HTTP route at all*. The only way
  to plan a part into a job was `php artisan tinker`. The tests were honest; the
  checkbox would not have been. Ask "can a shopkeeper do this?", and if the answer needs
  a terminal, the box stays open.
- A box that stays open gets a reason and a phase written beside it, not silence. Three
  did in Phase 6 — the checklist template builder, the labour catalogue, outsourcing —
  each deferred to where its missing infrastructure lands.
- Every tenant-scoped endpoint ships with a cross-tenant isolation test.
- **DECISION GATE** tasks stop the session. Present the summary, ask the human, wait.

Legend: `[ ]` todo · `[x]` done · `[~]` in progress (only one at a time) · `[!]` blocked ·
`[→]` moved to another phase, with the destination named on the line

**Never tick a box to make a phase read tidy.** A skipped item rendering as done is the same
family as the three rules in CLAUDE.md that this project keeps re-learning: nothing crashes,
the wrong thing silently wins. `[→]` exists so a deferred item leaves a trail instead of a
gap.

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
- [x] `docs/design-system.md` (ported to English from `hamyar-ui` SKILL + sections 1–2 of `docs/03-design-and-claude-setup-fa.md`, including `#landing`)
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
- [x] Confirm `.claude/skills/hamyar-ui/SKILL.md` loads

### Phase 0 — Definition of Done
- [x] `make up` then `make fresh` boots an RTL app
- [x] CI green on a PR — [#1](https://github.com/Alighaemi9731/hamyar/pull/1), all four jobs green, merged
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
> - **`hamyar.ir` is NOT confirmed** — a working name only. The apex domain stays
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
- [x] Proration formula written and unit-tested (`ProrationCalculator`, 11 cases).
      **ADR 0006 accepted at Gate 2** (2026-08-08) — truncate to 1 rial, round-half-up
      considered and rejected. The line above said "Proposed — needs sign-off" for four
      days after the gate had already signed it off; corrected in the 2026-08-12 ADR audit
- [~] `coupons` table + trial + grace period modelled and enforced by `Subscription::isUsable()`; redemption flow lands with billing

### 2.3 Feature gating (trial rules per Gate 2 item 3: Pro features, 14 days, no card,
zero bonus SMS, Basic invoice cap — `TrialPolicy`)
- [~] Module grants resolved from plan + add-ons via `SubscriptionResolver` (fails closed). Pennant `limit:<key>` flags land with the usage counters below
- [x] `EnsureModuleEnabled` route middleware
- [x] `features` shared Inertia prop; nav hides disabled modules
- [→] Usage counters service; soft-lock behaviour (warn → block create actions) — moved to
      Phase 12 (12.3–12.11), which supersedes the "a plan bundles modules" premise of 2.1/2.3
      ([ADR 0018](adr/0018-metered-plans.md))

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
- [→] Limit exhaustion behaviour — moved to Phase 12 (12.3, 12.7–12.11)
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
- [x] Catalog UI screens (category tree, product editor, price grid) — Phase 3.9

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
- [x] IMEI passport page — Phase 3.9. Nothing truncates, events group by Jalali day,
      and each line is named in the words a shop uses

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
- [x] Low-stock threshold + alerts list — opt-in by threshold, and "out" is separated
      from "low" because one costs a sale today and the other is a purchase order
- [x] Dead-stock report base — landed in Phase 9.2 as a cut of the inventory report

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
- [ ] Receive/pay quick-forms — the service is done; the screens land with Treasury (Phase 7)

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
- [x] Suppliers as parties (4.1 landed first, per the reorder)
- [x] Purchase invoices: draft until received. Standard lines and serialized lines are
      separate tables — a standard line has a quantity, a serialized line has N handsets
      each with its own IMEI and cost
- [x] `ImeiBatchParser`: paste or scan, any separator, Persian digits, Luhn-checked, with
      a per-line verdict (accepted / invalid / duplicate-in-batch / already-exists with a
      link to the device). Nothing commits until the batch is clean or rows are skipped
- [x] `LandedCostAllocator`: by value or by quantity, remainder to the largest line so
      the allocation sums to the charge **exactly**; per-unit split for serialized lines
- [x] `ReceivePurchaseInvoice`: one transaction that allocates costs, writes stock
      movements, creates `product_units` with the first line of their passport, and
      credits the supplier. Refuses a second receipt
- [x] An `inventory` account so received stock debits something real — without it the
      entry would net the supplier against itself and record nothing
- [x] Purchase returns — a new document, never an edit of the purchase: the shipment did
      arrive, and collapsing the two would rewrite a month that may be closed. A returned
      handset becomes `written_off` rather than deleted, because its IMEI was registered
      to this shop and the passport has to keep saying so
- [x] GRN print — every IMEI on its own line with a tick box, and costs quoted
      **including** their share of freight, which is what the goods actually cost

### 3.6 Movement operations
- [x] Two-step transfers — stock leaves on dispatch, arrives on receipt, and belongs to
      neither in between. A one-step transfer makes a van full of phones sellable in two
      shops at once
- [x] A shortfall on receipt is **recorded**, not reconciled away — five dispatched and
      three received is something to investigate
- [x] Serialized transfers move the unit (reserved in transit, relocated on arrival) and
      deliberately write **no** stock movement; a phone counted in both the unit register
      and the quantity ledger is counted twice
- [x] Stock count sessions, **blind by default** — a number on the screen is a number
      people count towards. Expected quantity is snapshotted per line; uncounted lines
      are skipped rather than written off

### 3.7 Labels
- [x] Price/barcode labels, single + batch, printable HTML at label sizes. The sheet on
      screen IS the sheet that prints; barcodes are server-rendered Code 128

### 3.8 Tests
- [x] Ledger invariants (no negative stock unless the allow-flag is set)
- [x] Unit state transitions incl. illegal ones
- [x] IMEI uniqueness per tenant
- [x] Two-step transfer flow
- [x] Price-level resolution
- [x] Purchase cost maths including landed costs
- [x] Cross-tenant isolation for every new endpoint

### 3.9 UI pass — one coherent pass, after 4.1–4.2 and 3.5
Built together rather than piecemeal, so the screens share components instead of
converging on them later.
- [x] Category tree
- [x] Product editor with the variant matrix
- [x] Price-level grid + bulk update with preview
- [x] **IMEI passport page** — the product's signature screen
- [x] Stock views + low-stock alerts
- [x] Label printing (price/barcode, single + batch)
- [x] Every screen built from `/design` gallery components (golden rule 9)
- [x] Verified with Playwright at 390 and 1280, light and dark, RTL — zero horizontal
      overflow and no console errors; three defects found and fixed (see PROGRESS 3.9f)

### Phase 3 — Definition of Done
- [x] Receive 10 phones by pasting IMEIs → in stock → print labels → transfer 2 to branch B
      → count stock, and every number reconciles with movements.
      **Walked end-to-end in a real browser on 2026-08-09**, not asserted from services:
      ten IMEIs pasted with per-line verdicts → received (each unit costed 79,000,000
      toman, i.e. 78,000,000 plus its 1,000,000 share of freight) → labels printed →
      two handsets scanned onto TRF-000001, dispatched and received → CNT-000001 counted
      blind and applied. The ledger reconciles line by line: chargers `purchase +12`,
      `count −2`, on hand 10; cases `purchase +4`, `count +1`, on hand 5; and **zero**
      quantity movements for the serialized variants, so no handset is counted twice.
- [x] The 3.9 screens exist, are built from gallery components, and have been verified in
      a real browser at both breakpoints in both themes

---

## Phase 4 — Parties CRM (Customers & Suppliers)

### 4.1 Parties — **relocated to Phase 3, ahead of 3.5** (reorder approved 2026-08-09)
### 4.2 Ledger engine — **relocated to Phase 3, ahead of 3.5**

### 4.3 Engagement
- [x] Party screens: list, customer page, create/edit. The customer page is what the
      phase is for — balance, statement, timeline, notes, follow-ups and points on one
      screen, because a shop opens it to answer one of three questions
- [x] 360° timeline component (sales, repairs, sms, payments, notes) — assembled through
      a `TimelineRegistry` in the shared kernel, the same shape as `DocumentRegistry`:
      each module contributes its own events and CRM imports none of them (ADR 0003).
      CRM, Purchasing and Inventory contribute today; Sales and Repairs are one
      `contribute()` call each when those phases land, with no change to CRM.
      A contributor that throws is **named on the page**, not swallowed — a customer
      page quietly missing its repair history is how somebody concludes a device was
      never brought in
- [x] Follow-up reminders (assignee, due date, done) + the follow-up desk. `done_at` is
      a timestamp, not a boolean: "when was this dealt with" is the question that gets
      asked. A reminder that only appears on one customer's page is a reminder nobody
      sees, so the cross-party desk is the primary screen
- [x] Loyalty points ledger + earn-rule stub. Golden rule 3 applied to points: the
      balance is `SUM(points)` and expiry writes a negative entry rather than deleting
      a positive one. One active rule per shop (partial unique index) — the earn
      calculation has to have exactly one answer. Redemption refuses to overdraw:
      points are not credit
- [x] `crm.manage_loyalty` added to the permission catalogue. Granting points is worth
      money and Salesperson holds `crm.update`, so the two cannot share a permission —
      the same separation `inventory.view_cost` and `repairs.reveal_passcode` already
      make

### 4.4 Import
- [x] Excel customer import with column-mapping wizard + dry-run report. Upload →
      guessed mapping → per-row verdict → commit, with the dry run being the import
      itself stopped before the write, so what it reports and what happens cannot
      differ. Handles what a real shop sends: Persian digits, a UTF-8 BOM, a semicolon
      delimiter from a Persian Windows Excel, a mobile number Excel stored as a float,
      the same person twice, and a nameless row
- [x] `maatwebsite/excel` installed (2026-08-10), realising the stack declaration that
      has been in CLAUDE.md since day one. Both readers sit behind a
      `SpreadsheetReader` contract with a registry, so the import service knows about
      neither format — and a `.csv` and an `.xlsx` of the same data are asserted to
      produce identical results

### 4.5 Tests
- [x] Ledger maths: statement closing figure asserted equal to `partyBalance()`, and the
      page's headline balance asserted equal to the statement's first row
- [x] Credit-limit warning on an over-limit party — a warning with data, never a block
      (spec), asserted as such
- [x] Import edge cases: duplicate mobiles within one file, bad rows, BOM, semicolons,
      Persian digits, matching an existing customer without overwriting them
- [x] Cross-tenant isolation for every new endpoint, including one proving an import
      token cannot be pointed at another shop's upload or escaped with `../`

### Phase 4 — Definition of Done
- [x] Customer page shows a true balance and full timeline; a 500-row sheet imports
      cleanly.
      **Walked end-to-end in a real browser on 2026-08-10.** The customer page shows a
      balance summed from the ledger with its statement beside it, and a timeline
      carrying the opening balance, a note written on the page, and a follow-up created
      through the Jalali picker. A real `.xlsx` of deliberately messy Persian data was
      dragged through the wizard: headers guessed, dry run reporting 3 new / 1
      duplicate-in-file (naming the line it duplicates) / 1 error, then committed — and
      the three created parties verified, with `۰۹۱۲۳۳۳۴۴۵۵` normalised to
      `09123334455`, `0912-777-8899` stripped to `09127778899`, `۲٬۵۰۰٬۰۰۰` toman
      stored as 25,000,000 rial, and the ZWNJ half-space in «کوروش‌ زند» preserved.
      Checked at 390 and 1280, light and dark, RTL, zero horizontal overflow.
      Five defects found and fixed — see PROGRESS 4.6

---

## Phase 5 — Sales, POS, Trade-in, Installment creation ★ revenue-critical

### 5.1 Invoices
- [x] Lifecycle draft → final → void. Void reverses rather than deletes: mirror ledger
      entries, opposite stock movements, and the handset walks `sold → returned →
      in_stock` because the state machine refuses to let a status flip pretend a sale
      was not a sale. The number survives — a missing tax-invoice number is a gap
      somebody has to explain
- [x] Scan-first POS screen. **One** box, not two pickers: `PosScanner` resolves an
      IMEI, a serial, a barcode, an SKU or a typed product name, because the person
      holding the reader does not know which of those our schema calls it. Exact matches
      win outright, and a sold or reserved handset resolves **with its reason attached**
      rather than to nothing
- [x] Line types: variant + qty | serialized unit, both from the same scan box, resolved
      against the branch's sellable warehouse
- [x] Per-line discount and warranty months
- [x] Invoice-level discount (distributed across lines by value, remainder to the largest
      line), VAT toggle, shipping. VAT is now a per-shop setting defaulting to **off** —
      most small shops are not registered, and collecting ten percent a shop cannot remit
      is discovered by the customer, at the counter
- [x] Salesperson field + commission accrual entry. **A percentage of margin, not of
      turnover** (Gate 3): a seller paid on turnover has no reason to hold a price —
      discount a 100,000,000 phone to 90,000,000 and the sale falls 10% while the margin
      falls 25%, and the commission has to fall with the margin or the incentive points
      the wrong way. Computed net of VAT (tax collected is the state's money, not a
      share to pay out), floored to a whole toman, zero on a loss and never clawed back,
      and snapshotted with its rate so a rate changed in Mehr cannot restate what was
      earned in Shahrivar. Defaults to **0%** — a shop that never opened the settings
      screen has not agreed to owe anybody anything.
      Shown only to `sales.view_profit`, **including to the seller who earned it**:
      commission is a known percentage of margin, so revealing one reveals the other,
      and Gate 1 made Salesperson blind to cost and profit. A shop that disagrees grants
      the permission — the same per-tenant override Gate 1 allowed
- [x] Numbering per tenant+branch via the `counters` row-lock service

### 5.2 Payments
- [x] Split payments: cash / POS terminal / card-to-card / cheque ref / customer credit,
      each row carrying the evidence its method leaves behind
- [x] Change calculation. The drawer keeps the **settled** amount; the tendered figure is
      stored beside it (new column, with a CHECK that tendered never falls below settled)
      so a reprint next week still says what change was given
- [x] Partial payment → unpaid balance posts to the party ledger automatically

### 5.3 Returns & quotes
- [x] Returns full/partial. A return is a **new numbered credit document**, never an edit
      of the sale — the sale happened, and a closed month must keep saying so. A returned
      handset goes to `returned`, not straight back on the shelf: it becomes sellable
      only when somebody ticks that they have checked it, and records the grade. Void is
      refused on an invoice that has returns against it
- [x] Quotes → convert to invoice, carrying the quote's own settings snapshot

### 5.4 Trade-in
- [x] "Buy customer device" inside POS → mini-intake (model, catalogue variant, IMEI with
      Luhn validation, grade, agreed price, HAMTA transfer checklist ack)
- [x] Creates a used `product_unit` costed at the agreed price, acquired from the buyer,
      and offsets the invoice as a **tender, not a discount** — a discount would compute
      VAT on a smaller base and understate both the sale and the tax
- [ ] ID scan on the trade-in intake — blocked on the Files module wiring, exactly like
      the seller-ID attachment in 3.3. The HAMTA acknowledgement and the identity check
      are recorded; the image is not yet stored

### 5.5 Installment sale
- [x] Mark invoice as installment → wizard (down payment, count, interval, flat profit %,
      first due Jalali), live-previewing the schedule as it is typed
- [x] Generates `installment_plan` + rows; last row absorbs the rounding remainder so the
      rows sum to the contract total exactly
- [x] Optional guarantor party
- [x] Contract print — one `ScheduleTable` shared by the screen and the paper, because the
      whole point of a contract is that the two say the same thing

### 5.6 Printing
- [x] Thermal 80mm receipt
- [x] A5 / A4 official invoice
- [x] QR to a public invoice view. Signed and deliberately never expiring — a customer
      photographs their receipt and opens it eight months later. The hostname comes from
      the shop's own `domains` row, never a literal apex (golden rule 1b)
- [x] Template settings (logo, footer terms, QR on/off), snapshotted onto the invoice with
      rounding and VAT so a reprint carries the terms that were in force on the day

### 5.7 Profit engine
- [x] Serialized = that device's own cost; standard goods = weighted average at sale time
- [x] Cost snapshot stored on the invoice line, written once at finalisation and never
      recomputed — under Iranian inflation, a report quoting today's cost for last
      month's sale is not a report with a small error in it, it is a fabrication
- [x] Daily Z-report (cash session close). Built around **one** question — how much cash
      should be in this drawer — so the takings break down by method *and* by account,
      cheques and trade-ins are reported but kept out of the expected-cash figure, cash
      refunds are subtracted and shown, and voided invoices are counted rather than hidden

### 5.8 Tests
- [x] Full POS happy path (`PosSaleTest`, 17 cases through real HTTP)
- [x] Split payment maths incl. change and ledger postings
- [x] Serialized double-sell race: two parallel finalises → one wins, the other gets a
      Persian sentence naming the device
- [x] Trade-in creates the unit and the totals are correct
- [x] Installment schedule maths and rounding rule
- [x] Return restores stock and reverses the ledger
- [x] Numbering: no gaps/dupes under 50 parallel finalises, and per-branch sequences
- [x] Cross-tenant isolation on every new endpoint, including the public invoice page

### Phase 5 — Definition of Done
- [x] End-to-end walked in a real browser on 2026-08-12, not asserted from services.
      An iPhone 15 Pro Max scanned by IMEI at ۱۰۰٬۰۰۰٬۰۰۰ تومان → سمیرا احمدی selected →
      her old iPhone 13 taken in معاوضه at ۳۵٬۰۰۰٬۰۰۰ (creating unit #11, grade B, costed
      at the agreed price, acquired from her) → three cheques of ۱۵٬۰۰۰٬۰۰۰ with their
      serials → `INV-000002` issued → the ۲۰٬۰۰۰٬۰۰۰ remainder put on `INS-000001`, six
      monthly instalments at 12% flat, ۳٬۷۳۳٬۳۳۳ × 5 and ۳٬۷۳۳٬۳۳۵ on the last row →
      thermal, A5, A4 and the contract all printed, and the receipt's QR scanned through
      to the public page on a 390px viewport.
      **Every figure reconciles**: payments sum to `paid_total`; `total − paid` equals
      outstanding; the line's `cost_snapshot` equals the device's own cost; the plan's
      principal equals the invoice's outstanding balance; its rows sum to its total; the
      invoice's own ledger batch balances (Dr inventory ۳۵٬۰۰۰٬۰۰۰ + Dr party
      ۶۵٬۰۰۰٬۰۰۰ = Cr sales ۱۰۰٬۰۰۰٬۰۰۰); and the whole ledger balances to the rial.
      Three defects found and fixed — see PROGRESS 5.9

> ### ✅ DECISION GATE 3 — CLEARED 2026-08-12
> - **ADR 0009 approved as written**: step 1,000 rial (100 toman), direction `nearest`
>   (half-up), per-shop configurable. Every figure floors to a whole toman, the grand
>   total rounds once at the very end and never a line, and «گرد کردن» prints at any
>   non-zero amount with **no threshold** — the paper has to add up in front of the
>   customer. The ADR's status was corrected to Proposed first: it had been written
>   claiming acceptance at this gate before the gate was held.
> - **All four print layouts approved**: thermal 80mm, A5, A4, and the installment
>   contract — with the shop's logo, footer terms and the QR to the signed public copy.
> - **Commission = a percentage of margin**, closing the last open item in 5.1. See the
>   task above for the full rule and why the seller does not see their own figure.
> - **VAT default confirmed as off**, flagged at the gate rather than assumed: most
>   small mobile shops are not registered, and collecting ten percent a shop cannot
>   remit is discovered by the customer, at the counter. The 10% rate is carried ready
>   to tick.
> - **Still open, and not part of this gate**: the trade-in ID scan, blocked on the
>   Files module wiring exactly like the seller-ID attachment in 3.3.

---

## Phase 6 — Repairs ★ flagship module

### 6.1 Intake
- [x] Customer, device (brand/model/imei — link to a unit if we sold it)
- [x] Reported issue
- [x] Configurable checklist — **the per-tenant template builder is not built**; the
      eight-item list is a server-side default. Deferred to Phase 9 (Settings) with the
      other tenant-configurable screens, since it needs the settings UI to be worth
      building. The intake screen already renders whatever the server sends.
- [x] Photos
- [x] Passcode/pattern: encrypted at rest, masked in UI, permission-gated reveal
- [x] Accessories list, prepaid amount, estimate
- [x] Printed receipt with tracking code + QR

### 6.2 Workflow
- [x] State machine: queued → diagnosing → awaiting_approval → awaiting_parts → repairing → ready → delivered | rejected | abandoned
- [x] Every transition emits an event (SMS hooks in Phase 8)
- [x] History with actor + note
- [x] Kanban board + list views
- [x] Technician assignment, priority, promised date
- [x] Per-technician workload screen

### 6.3 Estimate & approval
- [x] Amount cap without approval (tenant setting) — fails closed: unset, zero, negative
      or malformed all mean *everything* needs approval
- [x] Approval via public non-enumerable link, or manual "approved by phone" with note

### 6.4 Parts & services
- [x] Parts consumption from Inventory: reserve → consume on complete → return on cancel
- [ ] Labor/services catalogue (flash, unlock, FRP, data recovery…) with prices —
      labour is free-text with an amount at delivery today, which works but retypes the
      same eight services every day. Needs Catalog work; deferred to Phase 9.
- [ ] Outsource to an external technician party with cost tracking — deferred to Phase 7,
      where the party ledger it posts against lives

### 6.5 Delivery
- [x] Payment settle (reuses the Phase 5 payment box — no parallel payment path)
- [x] Signature pad capture stored as an image (pointer events; walked at 390px with a finger)
- [x] Warranty-on-repair days
- [x] Delivered receipt print (the Phase 5 invoice print, all papers)

### 6.6 Abandoned devices
- [x] Configurable N days after ready → flag + escalating SMS steps + status
      (idempotent: the step is recorded under a unique index before it is announced)

### 6.7 Public tracking
- [x] No-login page: code → status timeline + shop contact, non-enumerable token, rate-limited

### 6.8 Tests
- [x] Every legal and illegal transition
- [x] Approval cap enforcement
- [x] Parts reserve/consume/rollback
- [x] Concurrent technician actions
- [x] Encrypted passcode never appears in logs or JSON — **or in the session store**,
      which is where an adversarial review found it leaking on a failed intake
- [x] Public page leaks nothing tenant-private
- [x] Abandoned scheduler
- [x] Cross-tenant isolation

### Phase 6 — Definition of Done
- [x] Full repair lifecycle demo on seeded data; board usable; tracking page live

## Phase 7 — Treasury, Expenses/Incomes, Cheques, Installment collection, Rentals

### 7.1 Accounts
- [x] Cash boxes, banks, POS terminals, petty cash
- [x] Opening balances
- [x] Transfer between accounts (fee is a third line, never a smaller amount)
- [x] Account statement (ledger view, running balance in display order)
- [x] Simple reconciliation check-off (per-entry, un-tickable)

### 7.2 Expenses & incomes
- [x] Expense/income modules with category trees (one table, a direction column)
- [x] Recurring templates (rent, salaries) — period-keyed idempotency, no `last_run_at`
- [x] **Rental module**: contracts for leasing shop desk/space → periodic income entries
- [ ] Rental reminder — deferred to Phase 8, which owns every notification channel;
      building a bespoke reminder here would be building it twice

### 7.3 Cheques
- [x] Received/issued, full lifecycle: in_hand → deposited → cleared | bounced | spent_to_third_party | returned
- [x] Due-date reminders list (overdue derived from the date, issued side leads)
- [x] Ledger posting matrix documented in `docs/specs/cheques.md` — **written before the
      code**, and pinned row-for-row by `ChequePostingMatrixTest` (R1–R13, I1–I7)
- [x] Binding constraint: `creditCheck()` counts uncleared cheques toward exposure
- [x] Binding constraint: `VoidInvoice` refuses an invoice with a live cheque
- [ ] Printable cheque receipt — deferred to Phase 9 with the other print layouts, which
      share a thermal/A4 template the print module owns

### 7.4 Installment collection
- [x] Due/overdue lists (collection desk)
- [x] Collect via any payment method
- [x] Late-fee policy — never compounding, off by default, capped
- [x] Early settlement recalculation — pro rata by instalment count, never by days
- [x] Maths specified in `docs/specs/installment-collection.md` before the code and pinned
      section by section, both worked examples asserted to the rial
- [ ] SMS nudge hooks — Phase 8 owns the channel; the events (`InstallmentCollected`)
      are emitted and ready to listen on

### 7.5 Closing & reporting base
- [x] Daily close (Z) across accounts — opening + movement = closing, shown
- [x] Simple P&L (period revenue, COGS from cost snapshots, expenses)
- [x] Cash-flow summary (movement per account per day, in the close)

### 7.6 Tests
- [x] Cheque lifecycle postings at every stage — every matrix row, not a sample
- [x] Installment collection and early-settlement maths
- [x] Recurring generator idempotency
- [x] P&L reconciles against a fully seeded scenario
- [x] Cross-tenant isolation

### Phase 7 — Definition of Done
- [x] A seeded "one crazy month" scenario reconciles to the rial across all reports

---

## Phase 8 — Messaging (SMS) & Notifications

### 8.1 Drivers
- [x] Driver abstraction (transport only — no policy decisions in a driver)
- [x] Kavenegar driver (pattern/lookup send; token contract documented in the file)
- [ ] sms.ir stub — deferred: a second driver with no account to test against is a guess
      at an API. The abstraction is proven by the fake and Kavenegar; adding sms.ir when a
      shop needs it costs one class.
- [x] Per-tenant credentials vs platform-pool billing by credits
- [x] Credit wallet, low-credit suppression (an empty wallet suppresses, never fails)
- [ ] Price tiers — deferred to Phase 11 with billing; one platform rate today

### 8.2 Templates & automations
- [x] Template manager with variables, ordered tokens, unknown-variable refusal
- [x] Automation toggle matrix — nine automations on the events Phases 5–7 already emit,
      every toggle OFF by default, per-automation opt-out asserted

### 8.3 Campaigns
- [x] Audience builder over CRM filters (kind, tags, recent purchase, balance)
- [x] Throttled queued sending (`per_minute` per campaign)
- [ ] Per-message delivery polling — deferred to Phase 11: it needs a scheduled poller and
      a real gateway account to poll. Provider references are stored, so the data is ready.
- [x] Opt-out honoured everywhere — excluded from the audience count AND refused at the door

### 8.4 In-app
- [ ] Notification centre (bell) — deferred to Phase 9, which builds the dashboard the bell
      belongs on. The message log ships here and answers the same question.

### 8.5 Tests
- [x] Driver fake asserting exact payloads (template id, token ORDER, normalised recipient)
- [x] Automation matrix
- [x] Credit deduction accuracy incl. refund on gateway failure
- [x] Campaign filter correctness on a seeded CRM
- [x] Opt-out respected — per automation, all nine, as a dataset
- [x] Cross-tenant isolation — two shops' jobs interleaved on one worker

### Phase 8 — Definition of Done
- [x] Changing a repair status fires the right pattern SMS in fake and logs the cost —
      walked as a busy day: one customer with a repair ready, an instalment due and a
      birthday; a second with all three who opted out; three sweeps. Six rows, three sent
      at 300 toman each, three suppressed at zero.

---

## Phase 9 — Dashboard & Reporting

### 9.1 Dashboard
- [x] Role-aware widgets per `docs/specs/reporting.md` — all eight, on `/dashboard`.
      Two gates per widget and they are different questions: the **plan** must include
      the module and the **user** must hold the permission. A widget the viewer may not
      see is absent from the payload, never sent as zeros — «هیچ چکی سررسید ندارد» is an
      answer, and putting it in front of somebody who was never allowed to ask is how a
      cashier tells the owner there are no cheques due. Margin obeys `ReportAccess`, one
      predicate shared with the report viewer, so the same person cannot see cost on one
      screen and not the other
- [x] No N+1 — the dashboard renders in a bounded number of queries over a deliberately
      wide seed (sixty variants, twenty tickets, ten cheques), asserted as a budget so
      adding a widget is not a failure but a per-row loop is. **The millisecond half of
      this line is 9.3's**: it needs the 100k-row seeder, which does not exist yet, and
      a query-count guard is not a latency guarantee

### 9.2 Reports (25+)
- [x] Report index — grouped the way a shop files reports, and listing only reports that
      exist. No «به‌زودی» rows: a greyed-out promise teaches the reader that the index
      cannot be trusted, after which they stop opening it
- [x] Sales daily — with the report viewer it establishes for the rest: Jalali range
      filter, three cuts over one range, the A4 print sheet, and the xlsx export
- [x] Sales monthly — a month-per-row grouping over a year, folded by **Jalali** month.
      Postgres has no Jalali calendar, so `date_trunc('month', …)` would group by the
      Gregorian month, which straddles two Jalali ones and makes «فروش مرداد» part Tir.
      The daily rows are folded in PHP by `Jalali::monthKey()` — at most 366 rows, so the
      calendar is right by construction and the cost is nothing.
      **Found and fixed alongside it:** the daily report bucketed by `date(issued_at)`,
      which is the *UTC* day. Anything sold between midnight and 03:30 Tehran landed on
      the previous day's row, and eleven times a year on the previous month's — under the
      monthly report, in the wrong month entirely. Now shifted to the shop's wall clock
      before it is truncated, with a test that moves a sale to 00:30 Tehran
- [x] Sales by product / salesperson
- [x] Sales by brand — Persian name leading, Latin as fallback. A line with no variant
      behind it (a service, or a handset sold off its own unit record) has no brand and is
      **kept** under one unnamed row: dropping it would make the brand cut disagree with
      every other cut of the same range, which the «sums to the same revenue» invariant
      now asserts across all five cuts
- [x] Profit report — its own screen at `/reporting/profit`, three cuts: by product, by
      brand, and **per IMEI**. Ordered by margin **in SQL, before the LIMIT**: re-sorting
      a revenue-ordered top-fifty in PHP answers "the fifty best sellers arranged by
      profit" to somebody who asked for the fifty most profitable, and discards exactly
      the low-volume high-margin lines the report exists to surface. Pinned as an
      *ordering*, on a fixture where the biggest sale in the shop (a 400,000,000 handset)
      is the smallest margin (20,000,000) and therefore last.
      Per-IMEI is the cut only this product can offer — every handset carries its own cost
      and its own `cost_snapshot`, so the margin on one device is exact rather than an
      average. It reads the **line's** snapshot, never `product_units.cost`, which is
      today's figure and would restate a past month every time somebody re-graded a unit.
      **The screen is refused, not stripped**, for a viewer without margin: a profit report
      with the profit removed is an empty table under a heading that promises otherwise.
      `ReportAccess` decides, and the same predicate hides the rows from the index — a
      listed row that 403s when clicked is worse than no row
- [x] Technician performance — delivered count, jobs on the bench today, average
      turnaround and parts cost, at `/reporting/technicians`.
      **Delivered, not worked-on**: a ticket counts in the period it was *finished* in, so
      a device brought in on the 29th of Mordad and repaired in Shahrivar does not make
      Mordad look busy and Shahrivar idle. **Turnaround is intake→delivery wall-clock**,
      not time-in-repairing, which would flatter every technician by excluding the days a
      device sat waiting for a part — the customer experienced the whole wait. **«روی میز»
      ignores the range** on purpose: open work has no date to be inside, and a ticket
      from two months ago that is still open is open today. Parts cost is the shop's
      `unit_cost`, not the customer's price, so the figure beside somebody's name does not
      move when a price list is edited — and it is dropped, not the screen refused, for a
      viewer without margin, because the counts stand on their own
- [x] Dead stock — lines and handsets that nothing has left in N days, dated from the
      **last outward movement**, not from when the stock arrived. A line restocked last
      week that has not sold since Farvardin is dead stock with fresh purchase dates all
      over it, and dating it from arrival hides exactly the case a shop needs to see.
      Handsets get one row each, because a shop discounts *this* phone rather than
      «iPhone 13s in general»
- [x] Stock valuation — at cost, at a date, and across **both registers**. This is the
      one that had a wrong answer waiting: standard goods are a SUM over
      `stock_movements` and handsets are rows in `product_units` with no movement written
      for them (Phase 3.6), so a valuation that reads movements alone values a
      mobile-phone shop's phones at **zero**. The fixture makes that loud — devices are
      half the total — and both the split and the total are asserted. The as-of date
      exists because "what was this worth on the last day of the year" is what an
      accountant asks, and only a SUM over movements can answer it
- [x] Party balances aging — 30/60/90, at `/reporting/financial?cut=aging`, both directions.
      **A payment settles the oldest debt first**, and that rule is the report: without it
      the two obvious implementations are both badly wrong in ways that look plausible.
      Bucketing the debits and ignoring the credits reports every invoice ever issued as
      outstanding, so a customer with a spotless twelve-year history becomes the worst debt
      in the shop; putting the whole balance in the bucket of the oldest unpaid entry lets
      one ancient rounding remnant drag a current balance into «۹۰+», and a report that
      points everywhere points nowhere. The FIFO runs **in SQL** as a window function —
      `remaining(lot) = clamp(cumulative − settled, 0, lot)` — because the per-party loop it
      replaces drags 75,000 ledger rows into PHP to produce forty. `opening_balance` joins
      the entry stream as a signed pseudo-entry, which is what makes the conservation claim
      hold: **outstanding − unapplied credit = the sum of `partyBalance()`, exactly**,
      asserted rather than trusted
- [x] Cheques calendar — a day per row, not a cheque per row: the Cheques index already
      lists individual cheques with every filter, and what it cannot answer is «چه روزی پول
      کم می‌آورم». **Only open cheques count toward the net** — a cleared cheque's money
      arrived, and counting it again promises cash the shop already spent — while cleared
      and bounced totals stay visible in their own columns. Overdue-and-still-open cheques
      are reported **outside the range**, the same decision «روی میز» makes in the technician
      report: a cheque that failed in Mordad has no future date to sit inside Shahrivar's
      calendar, and surfacing it only when somebody scrolls back is how it is forgotten
- [x] Installments book — a row per instalment, because a «دفتر» is read down and ticked
      off. Collected is a SUM over `installment_collections` (golden rule 3) **minus
      `unapplied`**: an overpayment sits on the party as credit, not on this row, and
      counting it would show a row as over-collected while the money is somewhere else. A
      settled instalment that was paid late is not overdue — it is history, and colouring it
      red sends somebody to chase a customer who already paid
- [x] Tax/VAT summary — monthly (Jalali-folded) and by rate, at `/reporting/tax`.
      **It reproduces the invoices; it does not recompute them** — the rule is now written
      into [ADR 0009](adr/0009-invoice-rounding.md)'s Amendment, because the invoice side has
      to honour the same one. Re-deriving VAT from a period's revenue at today's rate rounds
      once over a month instead of once per line, applies a rate the invoice may not have
      been issued under, and lands **eighteen rial** off on the two-line fixture — in the
      shop's favour, which is the direction a tax authority notices. Void invoices keep their
      number and lose their money; quotes were never sales
- [x] SMS usage — per template, at `/reporting/operations`. **Segments, not messages**: a
      Persian SMS is 70 characters per segment against 160 for Latin, so a template that
      grew by one polite word doubled the bill on everything it sends, and a report counting
      messages ranks the templates the wrong way round. Suppressed is counted apart from
      failed — an opt-out the shop honoured is a success, and a red number beside it is a
      lie. The wallet balance rides along and is labelled «فعلی», because «چقدر اعتبار دارم»
      is a question about this minute
- [x] …remaining reports enumerated in `docs/specs/reporting.md` — **17 rows over 7 screens**,
      past the 25 the heading asks for once cuts are counted the way the index lists them.
      What is left in the spec is Phase 10's or later: account statements, expense/income by
      category, P&L and cash-flow (Treasury owns the first four and they need a screen, not a
      query), user activity and audit trail
- [x] All with Jalali range filter, print CSS, Excel export — now true of all seven screens,
      so the box closes. Money exports as **two** columns, integer rial and the formatted
      string, and the string goes through the same `Money::toArray()` the screen calls so a
      spreadsheet cannot quote rial while the page quotes toman
- [x] Saved-filter presets — `saved_filters`, per **user** and per screen. A preset is a
      bookmark and **grants nothing**: applying one is a `router.get`, so the URL stays
      shareable and the screen gates itself through `ReportAccess` exactly as it does for a
      typed URL — pinned by a test where a Cashier saves a preset for the tax screen and
      still gets a 403 opening it. Keyed by screen rather than by catalogue row, because
      `sales.daily` and `sales.by_brand` are one screen with a `cut` filter and keying by row
      would give one saved range three entries that each restore a different tab

### 9.3 Tests
- [~] Golden-number tests: seeded scenario → exact expected figures per report.
      **Found and fixed here:** `CrazyMonthSeeder` — the Phase 7 scenario the golden
      numbers are pinned against — contains no sales invoices at all, so the two sales
      assertions in `GoldenNumbersTest` were comparing zero to zero and passing. They now
      assert that emptiness explicitly, so the day somebody adds a sale to the scenario
      they are pointed at `SalesReportScreenTest`, which pins the sales arithmetic
      (290,000,000 revenue · 180,000,000 cost · 110,000,000 profit) against a fixture
      built for it and checks every cut sums to the same revenue
- [x] **The 100k-row bulk seeder** — `BulkVolumeSeeder` fills a shop with a year of
      trading: 40,000 invoices · 100,000 invoice lines · ~100,000 stock movements ·
      ~75,000 ledger rows, every value a deterministic function of the row's ordinal so
      the same seed produces the same rows and the same plan on every run. It is a
      fixture for **timings, never for figures** — every amount in it is arithmetic the
      seeder invented, and pinning a report to it would be pinning the report to that
      invention. Two shops are filled to the same size, because on a single-tenant table
      a sequential scan and an index scan do identical work and the budget would pass
      with every index dropped. It ANALYZEs after each step rather than once at the end:
      the sale-movement insert against unanalysed tables was still running after seven
      minutes, versus 1.4 seconds with statistics
- [x] Query performance budget (<300ms on a 100k-row seed for top reports) — the harness
      is `ReportLatencyTest`, and it now covers **26 measurements over every report the
      catalogue lists**, at **1–93ms against the 300ms budget**, with the fixture's row
      counts asserted before any clock starts. Found and fixed a real defect on the way: a
      thirty-day sales report read 75,200 index entries and 12,533 heap rows to keep 3,093,
      because `(tenant_id, status)` stops before the date and `(tenant_id, branch_id,
      issued_at)` cannot be entered without a branch — so the cost grew with the shop's whole
      history rather than with the range asked for. Replaced by `(tenant_id, status,
      issued_at)`.
      **Closing it honestly meant growing the fixture, not widening the claim.** The budget
      previously covered four reports because `BulkVolumeSeeder` held only invoices, items,
      movements and ledger rows — timing a cheque calendar against an empty `cheques` table
      measures `select … where false` and passes by a factor of a thousand, which is the
      *green without witness* this suite argues hardest against. So the seeder grew handsets,
      cheques, instalment plans/rows/collections and messages, and with them the two cuts it
      had explicitly deferred in writing (`profit.per_imei`, both inventory cuts) became
      measurable and are measured.
      One more fixture defect surfaced while doing it: the ledger wrote **debits only**, so
      `settled` was always zero and the FIFO clamp collapsed to `lot` on every row — the
      expensive branch never ran, and the payable direction read an empty set at full speed.
      The first measurement said so out loud, 84.8ms receivable against 20.5ms payable, and
      part-payments against every third invoice fixed it.
      **Still not measured, and named rather than quietly omitted:** `repairs.technicians`.
      The seeder writes no repair tickets, and it goes in with that fixture, not before it

### Phase 9 — Definition of Done
- [x] Numbers everywhere agree with the Phase 7 reconciliation scenario — walked, not
      assumed. `GoldenNumbersTest` now runs the Phase 9.2 reports against `CrazyMonthSeeder`
      as well as the Phase 7 ones, and the shape of each assertion is chosen by whether the
      scenario contains the subject:
      · **Aging** reconciles to `LedgerService::partyBalance()` over the crazy month's real
        party debt — two wholesale sales on credit, a bounced-and-chased cheque, one endorsed
        to a supplier — with the witness (`outstanding + credit > 0`) asserted above the
        reconciliation so it cannot pass on an empty world.
      · **The cheque calendar** pins the month's headline cheque: 450,000,000 received, due
        on day 20, bounced on 22, cleared on 28 — reported on its due date and contributing
        **zero to the net**, because that money arrived. A calendar that counted it as
        incoming would promise this shop 45,000,000 toman it had already banked. The endorsed
        280,000,000 is asserted absent from both the range and the overdue block, since its
        due date is still in the future.
      · **VAT and SMS** are zero, and say *why* they are zero — the scenario has no sales and
        sends no messages — each pointing at the suite that pins its arithmetic instead. That
        is the second option in `docs/testing.md`'s green-without-witness rule, never the
        third: an exact figure asserted against a fixture nobody checked

---

## Phase 10 — Multi-branch polish, Storefront, HAMTA, Moadian v1, Data tools

### 10.1 Multi-branch audit
- [x] Every module respects branch context — **and the audit's first finding was that
      branch context had no on-ramp.** `branch_user` had existed since Phase 2,
      `BranchAccess` read it correctly, and Sales/Repairs/Inventory enforced it — but
      nothing could write to the table, there was no way to create a second branch, and no
      switcher. Five modules (Cheques, Installments, Purchasing, Treasury, Messaging) had
      **no branch filter at all**, and the reports had none either. The gap was invisible
      precisely because it was unreachable: with nobody assignable to a branch, every user
      was unrestricted and every screen looked right.
      Fixed by `BranchContext`, which keeps the two questions apart: **access is a floor
      that always applies; the switcher is a lens on top.** Conflating them is what turns
      «همه شعب» into a privilege-escalation button, and that case is the test this work
      exists for
- [x] Branch switcher — in the app chrome, rendering **nothing** for a single-branch shop
      (which is almost every shop): a control offering one choice is a control that does
      nothing. Plus the screens that had to exist first — branch CRUD and per-branch staff
      assignment, gated on `settings.*` and living in Inventory because `branch_user` is
      Inventory's table (golden rule 6)
- [x] Consolidated vs per-branch reporting toggle — the reporting services took `?int
      $branchId` and the controllers passed **nothing**, so a manager restricted to one
      branch read the whole shop the moment they opened a report. A single id also cannot
      express "the two branches this regional manager is allowed", so the parameter became
      `list<int>|null` down through `ProfitEngine`. `DailyCloseReport` and `ProfitAndLoss`
      deliberately kept their `?int` and wrap at the call: a close is **one** till
- [x] Per-branch counters verified — `counters` already carried `branch_id` and
      `CounterService` already locked on it; what was missing was a shop able to have two
      branches to prove it with. Now covered by `BranchContextTest`
- [x] **Found on the way:** `BranchAccess` was never registered as a singleton, so its
      per-user memo was per-instance and every `forget()` was a **silent no-op** — including
      the one in the new assignment screen, which exists so a staffing change takes effect
      immediately. Bound as a singleton, with the reasoning written where the binding is

### 10.2 Storefront — scope fixed at Gate 4 part 1: **no cart, no checkout, no accounts**
- [x] Public shop landing page + product catalogue with live prices — Blade and inline CSS,
      no React and no bundle: these pages open on an Iranian mobile connection, often on a
      link forwarded through WhatsApp. Availability is **coarse** («موجود» / «تماس بگیرید»),
      never a count — a quantity on a public page is stale within the hour, invites haggling
      on the last one, and tells a competitor how deep the shop is on a line. On-hand reads
      BOTH registers, or a shop selling only handsets would show as empty
- [x] Reseller price-list link (password/expiry, per-price-level) — and the token is treated
      as what it is: a **bearer credential**. Stored hashed with a short non-secret lookup
      prefix, shown exactly once at creation, so a database dump does not hand over every
      live price list. Expiry is NOT NULL with a 90-day ceiling; a link outliving its price
      list is worse than an expired one. The price level is a column on the row and there is
      nowhere in the request for one to come from — «cannot escalate to another level» is a
      property of the schema, pinned by a test that tries three query parameters
- [x] PDF export — a print-optimised HTML sheet rather than a generated binary. Every browser
      turns it into a PDF on one tap, and «the PDF matches the web list exactly» is then true
      *by construction* rather than by two renderers agreeing. It runs the same gates: a print
      route that skipped the password would be the whole security model with a suffix on it
- [x] WhatsApp CTA — built from the stored canonical number, so «۰۹۱۲ ۱۲۳ ۴۵۶۷» typed on a
      Persian keypad still opens a chat. `PhoneNumber` moved from Messaging to `App\Support`
      on the way: Storefront needed it, and importing Messaging for a normaliser would have
      crossed a module boundary for a `preg_replace` (golden rule 6)
- [x] Every security rule in the spec, tested by name — 410 for expired *and* revoked (not
      404: the link was real, and «منقضی شده» tells a colleague to ask for a new one), 403 and
      rate limiting on the password, one link's password never opening another, a wrong token
      indistinguishable from a missing one, and the view log that lets a shop see a list
      travelling further than they sent it
- [x] **`price_list_links` opts into `allowPlatform`**, and it is one of very few tables that
      does. A visitor holding a token has no tenant to be scoped by — that is what the token
      is *for* — so resolution runs exactly one indexed lookup under `runAsPlatform()` and
      then enters that link's tenant; everything after is scoped normally. The isolation test
      opens a link on **another shop's hostname** and asserts it renders the minting shop's
      catalogue and none of the host's

### 10.3 HAMTA
- [x] Guided ownership-transfer workflow on used buy/sell — six steps from the spec, each
      answerable **«انجام نشد»** as a first-class answer. A checklist that only records
      success forces the salesperson to tick something untrue or abandon the record, and the
      shop's protection in a dispute is the honest version. Answers are **append-only**: a
      correction is a new row and both are shown, because evidence that can be edited
      afterwards proves only what somebody wanted it to say later
- [x] Activation-id record on the unit — stored **verbatim**, with no format rule. There is
      no published contract to validate against, so a shape check would assert knowledge this
      product does not have, and a rejected id sends a salesperson hunting a bug with a
      customer at the counter. Optional, too: a shop often watches the transfer complete on
      the customer's phone before the SMS with the id in it is forwarded
- [x] "Transfer pending" warnings — a banner on the IMEI passport that **links to the
      checklist that clears it**, and the pending list as the screen somebody works through.
      The `StatusBadge` map already had `hamta_pending` in danger tone since Phase 3; it had
      simply never had a device to fire on
- [x] `*#7777#` instructions page — written for the assistant who has just been asked «همتا
      یعنی چی؟», not for a developer: the three transfer routes, what the SMS looks like, how
      long it takes, and what to do when the customer leaves before it completes
- [x] UI states explicitly: no official API — the same notice component on every HAMTA
      screen, and the success message after recording a transfer says «این ثبت است، نه
      استعلام از همتا». A shop that believes the software handles it stops doing the
      transfers and finds out months later from a customer whose phone stopped working
- [x] **Found on the way, and it is the 10.1 pattern again:** `product_units.hamta_status`
      and `hamta_activation_id` shipped in Phase 3 and **nothing ever wrote to either** —
      every device in every shop read `not_required`, used ones included, for seven phases.
      The writers are new; the columns are not. Now driven by two listeners: a new
      `UnitAcquired` event (dispatched by every door a device comes in through, so a fourth
      acquisition path is covered without remembering this module exists) and
      `InvoiceFinalised` on the way out. The status is about the **current outstanding
      transfer**, so a device goes `pending → done → pending` when the shop buys it,
      transfers it in, and sells it on — treating `done` as terminal loses the transfer the
      customer actually walks out with

### 10.4 Moadian v1 — **adapter only, no provider** ([ADR 0011](adr/0011-moadian-adapter-without-a-provider.md))
- [x] Adapter interface behind a queue — `MoadianDriver` (send · status · cancel), designed
      against the **specification and a fake**, never against one vendor's API. Gate 4 part 2
      ruled out a real provider for launch: these customers are mostly on presumptive
      taxation, and picking an intermediary before one has been asked for buys an integration
      the first real request is likely to contradict. The contract's sharpest rule is that a
      **rejection is a return value and a transport failure is an exception** — an answer is
      not a failure, and retrying a refused document gets an identical refusal
- [~] One intermediary-provider driver — **deliberately not built.** `FakeMoadianDriver` is
      the only implementation, covering accept, reject and transport failure. Backlog:
      *when the first paying tenant requests Moadian, select a provider and build the real
      driver against the existing contract.* Left `[~]` rather than `[x]`: the line asks for
      something that does not exist, and ticking it would claim otherwise
- [x] Invoice → e-invoice payload mapping — pure, no HTTP/clock/queue, unit-tested against
      fixture invoices. It **reproduces** the invoice and never recomputes it: per-line VAT
      was floored to a whole toman at issue (ADR 0009 amendment), and the test pins both the
      stored figure and the naive recompute it must not equal — a gap that only exists
      because the fixture prices lines at 8,881,990 rial
- [x] Send / poll status — polling is a separate question from sending, because a document
      accepted at submission can be rejected later, and collapsing them would make
      «پذیرفته‌شده» mean two things a day apart
- [x] Error inbox + resend — the spec calls silent failure the worst possible outcome, so a
      rejection lands with its Persian reason beside the invoice it belongs to. Resend
      **rebuilds the payload** from the invoice as it stands now (the shop fixed what was
      rejected) and is idempotent against a partial unique index, because two workers both
      reading "not yet submitted" is exactly the race a queue makes likely
- [x] Feature-flagged — **off for every plan at launch**, two switches: the deployment-wide
      `MOADIAN_ENABLED` so no development machine can file a real tax document, and the
      shop's own setting. Reported separately on screen, because «چرا کار نمی‌کند؟» needs an
      answer that tells them apart. Plan copy says «به‌زودی»
- [x] **Found on the way:** the 23505 rule in CLAUDE.md has a sharper edge than it said —
      the `try` must sit **outside** `DB::transaction()`. A closure that catches its own
      unique violation never triggers the savepoint rollback, so the recovery query runs on
      an aborted connection and dies with the very 25P02 the wrapper exists to prevent.
      Third occurrence; now written down with both shapes

### 10.5 Data tools — **deferred, deliberately** (see [the 11a/11b split](#phase-11--hardening-performance-launch))
> Not skipped for time. Export and import are the two features most tightly coupled to
> schema shape, and Phase 11 hardening moves schema and tenant-isolation boundaries — build
> them before hardening and you build them twice, the second time under launch pressure.
> Two of the four boxes below left this section rather than staying and rotting:
> **products import → 11b** (onboarding-blocking: ~40–50 evaluators arrive with an existing
> Excel catalog from Iranian accounting software, and one that cannot be loaded is an
> evaluator lost on day one) and **audit-log viewer → 11c** (a support tool at that headcount,
> not a compliance feature).

- [ ] Full tenant export (Excel/JSON zip) — post-launch. It is what a shop asks for the
      first time they consider leaving, which is not week one of an evaluation
- [→] Products import — **moved to [11b](#11b-products-import)**, built on the settled schema
- [ ] Backup-request button (admin-side artisan) — **decide after the 11d restore drill.**
      A button that requests a backup nobody has ever restored is a button that produces
      false confidence; the drill answers whether this is worth building at all
- [x] Audit-log viewer UI with filters — **delivered in [11c](#11c-audit-log-viewer)**

### 10.6 Tests
- [x] Price-list link security (expiry, password, price level) — `PriceListSecurityTest`,
      sixteen cases, each naming the spec line it pins. The consumer and reseller prices in
      the fixture differ **deliberately**: a fixture where they matched would let a screen
      serve the wrong level and every assertion would still pass
- [x] Storefront leaks nothing private — the catalogue query is an **allow-list of columns**
      rather than a filtered `select *`, so a later migration adding a cost column to
      `product_variants` cannot leak by default
- [x] Moadian driver contract tests with a fake — accept, reject and transport failure, plus
      the disabled default (writes nothing, surfaces nothing) which is the launch
      configuration for every shop
- [ ] Export completeness snapshot — belongs with tenant export, which is post-launch (10.5)

> ### ✅ DECISION GATE 4 — CLEARED 2026-08-16
>
> **Part 1 — storefront scope.** v1 is: public shop page · live-price catalogue · reseller
> price-list links (password/expiry, per-price-level) · PDF export · WhatsApp CTA.
> **No cart, no online checkout, no customer accounts** — those are post-launch backlog.
> 10.2 is built to exactly that.
>
> **Part 2 — the Moadian provider: there is not one.** Ruling: “**NO real Moadian provider
> for launch.** My customers are mostly on presumptive taxation and won't use e-invoicing at
> first.” 10.4 ships the adapter contract, the payload mapping, the queue, the status inbox
> and the error handling — **all against a `FakeProvider` only** — and then stops. No
> provider research, no sandbox, no real driver. Feature-flagged **off for every plan** at
> launch; plan copy says «به‌زودی».
> Recorded as [ADR 0011](adr/0011-moadian-adapter-without-a-provider.md).
> Post-launch backlog: *when the first paying tenant requests Moadian, select a provider and
> build the real driver against the existing contract.*

---

## Phase 11 — Hardening, Performance, Launch

> **Phase 11 runs in four passes, and the order is the point.**
>
> Launch is ~40–50 of the owner's own customers evaluating concurrently, not a handful of
> pilots. That changes two things. **Tenant isolation stops being theoretical**: with three
> pilots a cross-tenant leak might never surface; with fifty concurrent evaluators it will,
> and the worst place to discover it is in front of a customer. And **schema-coupled
> features must not be built twice** — anything that reads or writes the shape of the
> database wants the boundaries settled first.
>
> | pass | what | why here |
> |---|---|---|
> | **11a** | the hardening that moves schema and tenant-isolation boundaries | isolation first, before anything is built on top of the shape it settles |
> | **11b** | products import (from 10.5) | onboarding-blocking, and built once on the settled schema |
> | **11c** | audit-log viewer (from 10.5) | «چرا این عوض شد؟» is a weekly support question at fifty evaluators; `spatie/activitylog` has collected since Phase 2 with no UI over it |
> | **11d** | the rest of launch hardening, **including a real end-to-end restore drill** | if nobody has restored this system end to end, that is the largest unhardened thing in the project |
>
> The restore drill reports the **RTO actually observed**, never an estimate. With fifty
> customers' data in it, a failed restore is not a bug — it is the launch.

### 11a — Schema and tenant-isolation hardening

*Isolation first, because fifty concurrent evaluators will find a leak three pilots never
would, and the worst place to discover one is in front of a customer.*

- [x] **Cache audit ([ADR 0012](adr/0012-tenant-keyed-caches.md)).** RLS guards the database;
      a memo is a read that never reaches it. Every stateful singleton now names the tenant
      in its key **or says at the key why it does not** — `PriceResolver` gained the tenant
      (a real leak, since a crafted request can supply another shop's variant and level ids),
      `BranchAccess` carries a justification (its key is a `User` model RLS already returned,
      not an id off a request), and the three boot-time registries say they hold code rather
      than shop data. The audit is written into the isolation section of `docs/testing.md`,
      where somebody is already thinking about leaks
- [x] **Queued-job tenancy is enforced, not remembered** — `bin/check-queued-tenancy`. A job
      runs on a worker that has just finished another shop's job, so "neither `TenantAware`
      nor declared platform-wide" is the one state that must not exist. `SendRenewalReminders`
      is genuinely platform-wide and now says so with `@platform-wide` and the reasoning,
      which is the difference between *considered* and *forgotten*
- [x] **Unique indexes must include `tenant_id`** — added to `php artisan tenancy:check`,
      which already runs in CI. An unscoped unique index is a constraint **one shop can
      impose on every other**: the first to register a national id or an SKU takes it from
      the other 49, and the failure surfaces at their counter as a validation error about a
      record they cannot see. It is also an existence oracle, which is the enumeration the
      404-not-403 rule exists to prevent, arriving through the schema instead of a route.
      The check resolves transitive scoping (an index leading with a foreign key to a
      tenant-owned row cannot collide) so it reports **zero** findings on a clean schema
      rather than ten — a gate that cries wolf is one somebody comments out. Four genuinely
      global indexes are allow-listed with a reason each: two bearer credentials, one
      gateway-issued id, one public URL segment

### 11.1 Security — **done, 11d pass 1**

> Audited rather than asserted: [`docs/security.md`](security.md) records what was
> **checked**, with the file or test that proves each line, and names the gaps it did not
> close. A checklist ticked because its items sound true converts an unknown into a false
> known.

- [x] OWASP ASVS-L1 checklist in [`docs/security.md`](security.md) — V1–V14, each line
      either verified with its evidence or named as a gap with what closing it costs
- [x] Rate limits: login, OTP, public tracking/price-list pages — **verified, not built.**
      All were already in place, login throttled per *credential and IP* rather than per
      route (so rotating usernames from one address does not reset the counter). Written
      down in the checklist so the next reader does not re-derive it
- [x] Signed-URL audit — five link types tabulated with signature, expiry and extra
      control. Two deliberate non-signatures recorded with their reasons: the receipt QR
      never expires because the paper does not, and the tracking/price-list tokens are
      capabilities printed on paper that must survive a key rotation
- [x] **Security headers + CSP** — `SecurityHeaders` middleware: nonce-based `script-src`
      with no `'unsafe-inline'`, `frame-ancestors 'none'`, `base-uri`, `form-action`,
      `Permissions-Policy`, and HSTS only on an already-secure production request.
      Registered **globally, not in the `web` group** — group middleware runs only after
      a route matches, so a 404 came back with no policy at all. Found by a test asking
      for a page that does not exist; every real screen looked correctly protected
- [x] **Dependency audit in CI** — `composer audit` and `npm audit --omit=dev`. It found
      a live high-severity advisory the day it was added (`nanoid`, reachable through
      `postcss` from the `shadcn` CLI). Patched; the CLI sitting in `dependencies` rather
      than `devDependencies` is a proposed one-line change, left for approval because it
      alters the dependency manifest
- [x] Secrets hygiene review — `.env*` gitignored bar `.env.example`; no credential
      literals in the tree
- [x] **Encrypted-columns inventory — derived, not listed.**
      `tests/Feature/SecretColumnsTest.php` asks every model and asserts the invariant
      that matters: **an `encrypted` attribute must also be `$hidden`.** The cast
      decrypts on access, so one that is not hidden reaches every `toArray()`, JSON
      response and `Log::info($model)` in plaintext — encryption doing its job while the
      value ships anyway. Verified by planting a leak
- [x] Impersonation & RLS re-verification — the 11c pass rewrote the `activity_log`
      policy and added the assertion that an impersonation stays visible to the shop it
      was performed on ([ADR 0002 amendment 3](adr/0002-single-db-tenancy-rls.md))
- [x] **Not on the list, found while checking it:** three screens inject server-generated
      SVG with `dangerouslySetInnerHTML`, and one takes an operator-supplied barcode that
      the products import can set in bulk. The generators escape correctly —
      `InlineSvgIsInertTest` now proves it by **parsing** the output, after a first
      attempt that searched for the substring `script`, matched `&lt;script&gt;` in the
      escaped description, and reported a vulnerability that was never there

### 11.1b Browser testing in CI — pay off the mechanism-level layout guards
- [x] **Wire Pest 4 browser testing into CI** — `tests/Browser/`, a `Browser smoke
      (Chromium)` job beside the others, and Node + Chromium in the dev image so a
      rendered assertion can be debugged locally instead of push-and-pray. Four things
      had to line up, and three of them fail *silently*:
      - the shop is given `127.0.0.1` as its own `domains.hostname`, because Pest's
        server binds there and every screen resolves from the hostname. The plugin's
        `withHost()` looks like the answer and moves where the server listens, not the
        Host header the page is fetched with
      - `Tests\BrowserTestCase` leaves `@vite` alone. The base `TestCase` stubs it out —
        right for feature tests, fatal here: the page arrives with its Inertia payload
        and no script to consume it, so the body is blank, nothing errors, and the
        failure reads "expected to see text … but it was not found"
      - **the measurement waits for React to mount.** Without it the suite measured an
        empty `<div id="app">` and passed all eight cases — including with a 2000px
        element planted in the page. Green without witness, on the suite written to
        stop exactly that
      - `tests/Browser` sits outside every declared testsuite, so a default `pest` on a
        machine that has not run `npm run build` does not fail
- [→] **Rendered assertion added; the replacement itself moves to post-launch, destination:
      print-media emulation.** The bullet asks for the source test to be *replaced*, and
      that literal claim is not met — so it is not ticked. Ticking it would make the phase
      read tidy at the cost of the one thing this list is for.
      What shipped, and what is still owed: `tests/Browser/InvoicePrintLayoutTest.php` sells the
      seeded handset at the collision's own figure (`۹۶,۶۳۶,۷۹۸` toman) under the long
      Persian name, then measures every cell on A4 and A5: no box overlaps a neighbour,
      and no cell's content is wider than the cell that owns it — the second being the
      real mechanism, since `table-fixed` holds the *boxes* still while the *text*
      spills.
      **The replacement was attempted and refused on evidence.** Removing `table-fixed`
      — the exact regression the source test guards — leaves the rendered test green: at
      A4 the browser balances an `auto` layout into 703px of a 794px sheet with room to
      spare, and thermal80 uses no table at all. The historical squeeze does not
      reproduce in a screen-width preview. So both are kept, each guarding the half the
      other cannot see, and the source test's docblock now says so instead of promising
      its own deletion. Two fixture facts worth keeping: the price must land on a whole
      toman or `Money::inUnit()` refuses it (golden rule 2 — the guard has eyes), and a
      VAT total the cash payment does not cover needs a party, or the POS correctly
      rejects the credit sale.
      **Still owed, post-launch:** the rendered test measures the *screen preview* of A4,
      and the collision lives in **print media**. Catching the squeeze needs
      `page.emulateMedia({ media: 'print' })` — which Pest's browser plugin does not
      expose today — or a fixture narrow enough to force the squeeze on screen. Until one
      of those exists the source test is load-bearing and stays
- [ ] Audit for other mechanism-level guards standing in for rendered ones and convert
      them in the same pass (`grep -rn "asserts the \*mechanism\*" app/Modules`); each
      conversion deletes the "when browser testing lands" caveat from its docblock rather
      than leaving both tests in place.
- [x] **The manual walk is now a suite.** `tests/Browser/SmokeTest.php` renders four
      screens at mobile and desktop and asserts no console errors and no horizontal
      overflow — the mechanical half of the pass that has ended every phase. It found a
      real defect on its first honest run: `AppShell`'s action row never wrapped, so the
      products list put 553px of buttons in a 375px viewport and pushed the whole page
      sideways. One `flex-wrap`, in the one place every screen inherits. Judgement stays
      human; the tripwires do not
- [x] **Extend the smoke suite to light and dark, and to the print layouts.** Done in
      11.3, and the stated precondition — "once the invoice conversion above lands" — was
      dropped on purpose: that conversion is parked behind print-media emulation, and it
      gated the wrong thing. Nothing about rendering four screens in dark mode depends on
      whether a *source-level* layout guard was replaced.
      `SmokeTest` now runs 4 screens × 2 devices × **2 themes** = 16 cases, and
      `InvoicePrintLayoutTest` gained 3 papers × 2 themes — which is how `thermal80`
      finally got covered at all, having no table and therefore no geometry test. That
      is the paper most of these shops actually print on.
      **The theme is driven through `prefers-color-scheme`, not the toggle**, because
      that is the path a first visit takes: `app.blade.php` reads localStorage and falls
      back to the OS preference before first paint. Clicking the toggle would test the
      *second* visit and skip the flash-of-wrong-theme logic entirely.
      **And each case asserts the theme actually applied.** Without that witness the dark
      half is the light half run eight more times — sixteen green cases reporting twice
      the coverage they have, which is the same defect this suite was written to stop and
      which it had already made once with mounting. Verified by forcing light and watching
      all eight dark cases fail
- [→] **Not covered, and it is the same gap as the bullet above: a receipt printing as
      ink on white.** The rule exists (`@media print { html, body { background: #fff } }`
      in `resources/css/app.css`) and is guarded by nothing but itself, because reaching
      it needs `page.emulateMedia({ media: 'print' })`, which Pest's browser plugin does
      not expose. The dark-mode print cases prove the *page* survives a shop working in
      dark mode; they cannot prove the *paper* does. Recorded rather than implied — a
      screen-media test that looked like it covered this would be worse than the gap

### 11.2 Performance
- [x] **Seed 50 tenants × realistic volumes** — `php artisan platform:seed-volume`.
      `BulkVolumeSeeder` already built one convincing shop; this builds the *platform*,
      which is a different fixture for a specific reason: a query's cost stops being about
      one shop. 11c's dead-index find had no shape until there were forty-nine other
      shops for the scan to wade through, and a tenant predicate selecting 2% of a table
      makes Postgres choose differently from one selecting 100%.
      Measured on the dev machine: **50 shops, ~19M rows, 5.5GB, 19 minutes** (5 shops in
      56s, so it scales linearly). Refuses production without `--force`, only ever adds,
      and every shop carries a `load-test-` slug so `--fresh` can remove the set without
      touching the demo tenant.
      **First verification of the schema at launch size, and it held**: every hot report
      shape — invoices for a month, items joined to invoices, stock valuation, ledger
      aging — used an index and read only its own tenant's slice (3,094 invoices out of
      2,000,000; 102,400 movements out of 5,120,000), at 1.9–83ms
- [→] **Load test top 10 endpoints — script written, run parked until the staging box
      exists.** `tests/Load/endpoints.js` (k6) and its runbook are committed and point at
      the 11.2 platform fixture; what is missing is a machine to point them at, and that
      is deliberate rather than outstanding.
      **Running it here would measure the wrong thing.** A concurrency test on a
      developer laptop — the app, Postgres, Redis and the load generator all competing
      for the same cores, through Docker Desktop's network stack — produces a number
      that is dominated by the harness. Publishing it as "p95 under load" would be
      worse than having no number: an optimistic one hides a problem, a pessimistic one
      sends somebody optimising a virtualised filesystem.
      **Destination: the staging VPS**, which is being provisioned. The moment it has an
      address this is `k6 run` plus a report in `docs/` — the runbook is
      [`docs/load-testing.md`](load-testing.md): how to seed the box, where to run the
      generator from (not on the box), what counts as a pass, what to record, and which
      of the three items below each kind of failure feeds. See also the needs-the-box
      list at the end of 11.3
- [ ] Fix N+1s
- [ ] Add missing composite indexes
- [ ] Queue latency dashboards

### 11.3 Ops

> **Found while cleaning up the 11.2 fixture, and it belongs here.** Deleting a shop that
> has a trading year behind it is a **multi-minute, lock-heavy cascade** — around 380k
> rows across a dozen tables per shop, and a chunk of five tenants was still running after
> twenty minutes. Two consequences worth designing for before a shop ever cancels:
>
> - **Tenant deletion cannot happen inside a request.** It is a queued job with a
>   progress record, or it is a gateway timeout and a half-deleted shop.
> - **Concurrent deletes serialise.** A second `DELETE` over the same set waits on the
>   first's transaction rather than doing useful work, so a "remove these ten shops"
>   action has to be sequential by design rather than by accident.
>
> Nothing is broken today — no shop has cancelled and there is no delete-a-tenant feature
> to be slow. It is written down because the cheapest moment to learn this was on a
> fixture, and the most expensive would be on a customer asking to be forgotten under a
> data-protection deadline.
> **Everything below that does not need a server is built. The list of what does is at
> the end of this section**, so the moment there is an address, deployment is a
> checklist rather than a project.
>
> Two packages the stack has claimed since ADR 0001 turned out never to have been
> installed: **Horizon** (`make horizon` would have failed) and **Sentry** (whose env
> keys were already reserved in `.env.example`). Both are in now.

- [x] **Production compose + nginx + SSL** — `compose.prod.yaml`,
      `docker/nginx/templates/default.conf.template`, `docker/app/Dockerfile.prod`,
      `docker/app/php.prod.ini`, `docker/postgres/postgresql.prod.conf`, and the runbook
      rewritten in [`docs/deploy.md`](deploy.md).
      **Nothing names a domain, a host or a secret.** nginx gets the apex from
      `${APP_DOMAIN}` through the image's own envsubst pass — with
      `NGINX_ENVSUBST_FILTER='^APP_'`, without which envsubst also eats nginx's `$uri`,
      `$host` and `$fastcgi_script_name` and produces a config that starts and serves
      nothing correctly.
      **The production image caches no config, and that is golden rule 1b.**
      `config:cache` at build time bakes the *build machine's* environment into the
      artefact, starting with `APP_DOMAIN` — which would turn the apex into an image
      rebuild instead of a config change, silently, because the cached value simply wins.
      `bin/deploy` caches on the box, after the environment exists
- [x] **Enforced, not asserted: `bin/check-apex-domain`**, in CI. Rule 1b's five surfaces
      — printed receipts, repair-tracking QR codes, reseller price-list links, SMS
      templates, emails — are the five nobody greps, and a literal there works perfectly
      until the domain is registered and the receipts are already in customers' pockets.
      It found one on the day it was written: `MAIL_FROM_ADDRESS` in `.env.example` was
      `no-reply@hamyar.ir`, a working name nobody owns; it is now interpolated from
      `${APP_DOMAIN}`.
      Its first draft reported **ninety** findings, every one false — `invoices.store` is
      a route name, `report.net` is JSX, `index.php` is a filename, and all of them
      collide with a real TLD. A gate with ninety false positives is one somebody deletes
      in its first week, so it matches hostnames only in *hostname position* (after a
      scheme, after an `@`, any `.ir`, or anywhere at all inside infrastructure files)
- [x] **Zero-downtime deploy script** — `bin/deploy`. Two application containers, one
      nginx, and exactly one file deciding which is live: the new container is started,
      migrated, warmed and health-checked **before** anything points at it, and the
      cutover is a rewrite of `docker/nginx/upstream/app.conf` plus `nginx -s reload`,
      which lets in-flight requests finish instead of dropping them. Every irreversible
      step happens after every reversible one, so a failure before cutover leaves the old
      release serving and exits non-zero.
      The old container is **stopped, not removed**, which is what makes
      `bin/deploy <previous-image> --rollback` a cutover rather than a rebuild. Rollback
      skips migrations deliberately: reverting code to a release that predates a column
      is safe, and `migrate:rollback` during an incident is how data is lost
- [x] **Nightly `pg_dump` + WAL archiving** — `bin/backup-nightly`, plus `archive_mode`
      and a retrying `archive_command` in `docker/postgres/postgresql.prod.conf`. Two
      levels for two disasters: the WAL archive recovers a bad migration to the second on
      a box that still exists, the offsite dump survives losing the machine.
      **The dump is taken as the superuser, and the script asserts why.** The application
      connects as a NOSUPERUSER role so that RLS is a real boundary — and a dump taken as
      *that* role is silently filtered by those same policies. With no tenant pinned RLS
      fails closed, so the backup would contain **zero rows from every tenant table**
      while exiting 0 and reporting a plausible size. That is the most dangerous failure
      available here, so every run reads its own archive back and refuses one with no
      `stock_movements` data in it
- [x] **Sentry + health endpoint** — `config/sentry.php`, `ScrubSensitiveData`, `/health`,
      `artisan health:check`.
      **A crash reporter is a tenancy problem**, because its job is to take production
      data somewhere else. Three settings carry that decision and all three are hardcoded
      rather than env-driven — an environment variable is an invitation to flip one on
      mid-incident: `send_default_pii` off, and `sql_bindings` off in **both**
      breadcrumbs and traces (separate switches for the same values; a binding array is
      the richest leak in the product — `where national_id = ?` carries the national id).
      Request bodies are scrubbed through `App\Support\SensitiveInput`, which is now the
      **one** list behind two doors: the session flash (`dontFlash`, which had it) and the
      crash report (which did not). Events carry the tenant's id and slug as tags — enough
      to answer "which shop?", which is the question an incident actually asks, without
      carrying anyone's data to answer it.
      **The health endpoint grades its checks, and that is the point of it.** Database,
      cache and pending migrations are critical and return 503; a queue backlog is
      reported at 200. Grading a backlog critical would remove a healthy web tier from
      rotation because a third-party SMS gateway is slow — turning a delayed text message
      into a shop that cannot take payment. The detailed body needs `X-Health-Secret` and
      is withheld from everyone when none is configured: it names internal hostnames and
      driver-level failures, and reads best exactly when something is broken
- [x] **Horizon production config** — installed, and gated. One supervisor per queue
      rather than one pool over three, because a shared pool lets Moadian (a government
      endpoint, 180s timeouts) starve fifty shops' repair-ready texts.
      **The dashboard is a tenancy boundary, not an admin convenience**, and it is the
      widest leak available in the product because it does not look like one. Horizon
      renders **job payloads** — `SendSmsJob` carries a customer's phone number and
      message text — from every shop, on one screen, and none of it is a database row, so
      RLS cannot reach it. The gate asks the `platform` guard directly and re-checks
      `is_active` per request; `HorizonAccessTest` proves a shop **Owner**, the most
      privileged tenant role, gets 403
- [x] **Restore drill documented and performed once (log committed)** — performed on the
      staging box 2026-08-20, log in [`docs/restore-drills/20260820T104721Z.md`](restore-drills/20260820T104721Z.md).
      **RTO observed: 102s to restore**, 111s for the whole drill, from a 373MB dump of a
      5.5GB / 19.1M-row platform. 52 tenants and 5,240,003 stock movements back, 80
      policies present.
      **Running it found four faults in it, and the fourth is the lesson.** It could not
      complete at all (`--jobs` cannot read stdin; it counted a table named `invoices`
      that does not exist; its RLS rule contradicted `TenancyCheckCommand`'s and failed on
      three legitimately-exempt tables). But the isolation check — the reason this drill
      exists — *could never have tested isolation*: the scratch database is restored as
      the superuser, so the app role had no privileges in it, and "denied by RLS" and
      "cannot see the table" are the same zero. It now grants the role first and asserts
      **both** directions: 0 rows with no tenant pinned, 40,000 with one pinned. A zero
      only means RLS if the same role on the same table can read when a tenant is set
- [ ] Uptime hook — `/health` is live on the staging apex and correctly graded (503 for
      database/cache/migrations, 200 for a queue backlog; detail behind `X-Health-Secret`).
      What is missing is an **external** probe pointed at it, which needs a monitoring
      account somebody has to create — a credential decision, not work
- [ ] Queue latency dashboards *(moved from 11.2 — Horizon is now running on staging with
      all three supervisors and the `waits` thresholds configured, but the dashboards mean
      nothing until there is sustained real traffic to put in them)*
- [ ] **WAL archiving alert** — new, from the 11.4 deploy. `pg_stat_archiver.failed_count`
      must be watched: archiving had failed 3,595 times on the staging box without a single
      symptom anywhere else, while `pg_wal` grew to 14.5GB. Ownership is fixed
      (`compose.prod.yaml`), but the class of failure — postgres retries forever and stays
      healthy while recovery quietly does not exist — is exactly what an alert is for

#### Needs the box

*Written down as a checklist rather than a phase, because none of it is design work — it
is the same five things every time, and the point of everything above is that this list
is short.*

*All five were done on 2026-08-20 against `mobiyar.com` on a Hetzner box in Helsinki.
Every one of them found a fault that could not have been found without a machine — which
is the argument for the list, and the argument against ever trusting this layer until it
has run somewhere.*

| # | what | outcome |
|---|---|---|
| 1 | **Restore drill** (`bin/restore-drill`) | ✅ RTO **102s** observed. Found four faults in the drill itself; its key assertion could never have tested isolation ([log](restore-drills/20260820T104721Z.md)) |
| 2 | **Load test** (`tests/Load/endpoints.js`) | ✅ Run from a second Helsinki box, 4.4ms away. **Aggregate p95 1.62s — FAILS** the 1000ms threshold; 0.00% errors ([report](load-tests/2026-08-20.md)) |
| 3 | **Wildcard TLS** (`*.<apex>`) | ✅ Issued for `mobiyar.com` + `*.mobiyar.com` via DNS-01/Cloudflare, expires 2026-11-18, **renewal rehearsed and passing**. Found that the renewal container had no DNS plugin and that nothing ever reloaded nginx after a renewal |
| 4 | **First `bin/deploy` run end to end** | ✅ Four runs. Found that it could not deploy a locally built image, that neither script was executable, and that it could **force-recreate the live container** after any repo sync |
| 5 | **Sentry DSN, `HEALTH_SECRET`, uptime probe** | ◐ Sentry live and privacy-verified; `HEALTH_SECRET` set and `/health` grading correctly. **Uptime probe still open** — it needs an external monitoring account |

> **The largest single find was not in this list.** WAL archiving had **never once
> succeeded** — `archived_count = 0`, `failed_count = 3595` — because the named volume's
> mount point is root-owned and postgres is not. There was no point-in-time recovery at
> all, and `pg_wal` had grown to 14.5GB because unarchived segments cannot be recycled,
> heading for a full disk and a database that stops accepting writes. Nothing else looked
> wrong at any layer.

Everything else — image, compose, nginx, TLS config, deploy, backup, WAL, Sentry wiring,
health, Horizon — is built and parameterised. Pointing at a real server is writing
`.env.production` and running one command.

### 11b — Products import

*Promoted from 10.5. Built on the schema 11a settles.*

> ### ✅ CHECKPOINT 2 — CLEARED 2026-08-18
>
> **Variant representation:** one row = one product + one `options: []` variant; grouping
> opt-in, never inferred; barcode → SKU match ladder. Recorded as
> [ADR 0013](adr/0013-flat-product-import.md). The ruling rationale is the **reversibility
> asymmetry** — flat-and-wrong costs an afternoon of tidying, grouped-wrong is permanent
> once stock movements and invoice lines reference those variants.
>
> **Stock: catalog only.** The «موجودی» column appears in the mapping list greyed and
> labelled «وارد نمی‌شود», with a pointer to the correct path (opening purchase receipt or
> stock count), so it reads as deliberate rather than broken.
>
> **Format claims, probed against the live reader layer before designing anything:**
> `.xlsx`, legacy `.xls` (BIFF8) and UTF-8 CSV already work. windows-1256 CSV does **not**
> — it reads as mojibake and the header row comes back empty. No ی/ک normaliser exists.
> Money parsing was silently 10×/100× wrong (fixed — see below).

- [x] Variant representation in a flat file — one decision, documented, driving both the
      template and the mapping screen. [ADR 0013](adr/0013-flat-product-import.md)
- [x] **Money parsing routed through `Money::parse()`** — pulled forward out of 11b because
      it was a **live 10×/100× error on customer balances**, not a products-import concern.
      An Iranian sheet writes a decimal with a slash; the old parser stripped every
      non-digit and concatenated the fraction onto the amount, so `12500000/0` toman was
      imported as 1,250,000,000 rial. `Money::parse()` now reads all three decimal marks
      (`/`, `٫`, `.`) with exact integer arithmetic and **refuses** an amount that does not
      land on a whole rial. An unreadable cell is a row error, never a zero balance. Nothing
      to remediate: no staging or production exists, and the dev database has zero parties
- [x] Three layers, all required: downloadable template · **column mapping screen** ·
      dry-run preview that writes nothing until confirmed. Reachable at `/catalog/import`
      from a «ورود گروهی» action on the products list, behind a new `catalog.import`
      permission (Owner and Manager have it; Warehousekeeper does not — one click writes
      the whole catalogue and, on re-import, a new price for every matched row)
- [x] Currency unit a **required** choice, no default and no inference — unpicked on the
      screen, blocking the step, and `required` in the FormRequest so a client that omits
      it is rejected rather than served a guess
- [x] Persian/Arabic digit, separator and ی/ک normalisation before parsing — **ی/ک is
      code-page repair, not tidying**: windows-1256 cannot encode Persian yeh, so every
      «گوشی» in a legacy file physically arrives as «گوشي»
      ([catalog spec](specs/catalog.md#products-import-phase-11b))
- [x] `.xlsx`, legacy `.xls`, and CSV in windows-1256 as well as UTF-8. `Encoding` decides
      from UTF-8 validity rather than asking a shopkeeper which code page their export
      used, and the verdict is **announced** in the file chip
      («این فایل با کدپیج قدیمی ذخیره شده و اصلاح شد») so the repair is visible.
      `ProductImportReadPathsTest` pins one catalogue written four ways and asserts all
      four read back identically — the fixture is constrained by what cp1256 can hold,
      which is itself the proof that the ی/ک repair is structural
- [x] Quantity column shown greyed and labelled «وارد نمی‌شود» with a pointer to the
      correct path (فاکتور خرید / انبارگردانی) — silence here reads as a bug
- [x] **Found on the browser walk, not by a test:** a row with more fields than the header
      has an unescaped delimiter inside a value, and every column after it shifts. An
      unquoted `18,900,000` in a comma-delimited file split into three cells, the price
      column read `18`, and the phone imported at eighteen toman — no error, no empty
      cell. Now a row error naming both counts. Two further UI defects the walk caught:
      `<Money value=…>` instead of `rial=…` (the component's own guard threw at render),
      and verdict messages overflowing their cell so the half saying *what to do about it*
      was clipped

### 11c — Audit-log viewer

*Promoted from 10.5. Read-only UI over the audit trail — **and, first, over an audit
trail worth reading**.*

> **The promotion note was wrong, and checking it was the first thing 11c did.** 10.5
> described this as a UI over data «collected since Phase 2». Across eighteen modules
> exactly one model carried `LogsActivity` — `Identity\User`, four attributes — beside
> two hand-written call sites (an impersonation starting, a passcode being revealed).
> The dev database's entire audit trail was two rows, both «user created».
>
> So «کی این قیمت را عوض کرد؟» — the question the viewer exists for — had no row to
> find. A filter bar over that table would have worked perfectly and answered nothing.
> Coverage came first; the screen second. [ADR 0014](adr/0014-audit-surface-and-log-isolation.md)

- [x] **An audit surface, decided rather than sprinkled.** An entry is earned when an
      owner would ask who made a change *and* no other table already answers. Product,
      ProductVariant, PriceLevel and Party carry `Auditable`; price changes are logged
      by hand against the **variant** with the amount before and after, because
      `product_prices` is append-only so only *who* was missing. Ledger-shaped tables
      (`stock_movements`, `ledger_entries`, `ticket_status_histories`, …) are
      deliberately **not** audited — they are already the record, and mirroring them
      would duplicate the highest-volume writes in the product to say nothing new
- [x] Filterable by user, by date (Jalali), by subject type, by **one specific record**,
      and by free text over the Persian description
- [x] **The record-page entry point, which matters more than the standalone screen.**
      «تاریخچه» on a product and on a party opens that record's own history, titled with
      its name. An owner who must find the audit log and then rediscover which of four
      hundred products they were looking at will not use it twice
- [x] **Read-only as a tested property, not a habit.** `ActivityLogRoutesTest` fails if
      any route reaches the controller with a mutating verb, if the controller grows a
      public action beyond `index`, or if the policy grows an ability beyond `viewAny`.
      Entries leave by ageing out, never by request
- [x] **The log masks what the model masks.** Secrets are redacted on the way in, from a
      list *derived* from each model's own `$hidden` and `encrypted` casts rather than
      maintained beside the audit code — so a new secret field is protected the day it
      is added. Two findings: spatie v5 writes the model diff to `attribute_changes`,
      not `properties`, so guarding the latter alone masked nothing for audited models;
      and `RepairTicket`'s `tracking_token` and `approval_token`, both bearer
      credentials, were not declared sensitive anywhere
- [x] **Indexes measured against the filter set at 1.8M rows** (fifty shops, a year) —
      and the measurement found that the table had **no usable index at all**. Its
      null-tolerant RLS policy used `IS NOT DISTINCT FROM`, which no btree can serve, so
      every query scanned the whole platform to answer a question about one shop. Fixed
      in `EnablesRowLevelSecurity` (an indexable OR) plus a planner-visible tenant scope
      on `Activity`; the record-history hot path went from a full scan to a 0.074ms
      index scan, and no query's cost grows with the number of shops any more
- [x] **Six defects found on the browser walk with every feature test green** — the
      worst being that a product's «تاریخچه» contained no price changes at all, because
      prices are logged against the variant. Not an edge case: [ADR 0013](adr/0013-flat-product-import.md)
      makes one-product-one-variant the shape of every imported row, so the link built
      to answer «کی این قیمت را عوض کرد؟» opened a page with every kind of change
      except that one. Subjects can now declare related records
- [x] **Null-tolerance kept, deliberately, and asserted.** The last ordered-scan plan
      needs a policy with no OR, which means a platform action on a shop would have to
      live somewhere the shop cannot see. Declined: an impersonation is the event a shop
      most needs to read. `ActivityLogViewerTest` now requires an impersonation entry to
      be visible to the tenant's own Owner, so the property cannot silently invert
      ([ADR 0002 amendment 3](adr/0002-single-db-tenancy-rls.md))
- [ ] **Retention — noted, not built** (post-launch). `clean_after_days` is 365 and
      `activitylog:clean` is **not scheduled**. What is left of the per-shop query cost
      is bounded by retention rather than by an index, but how long a shop's audit trail
      must survive is a legal and commercial question, not an engineering one

### 11d — Remaining launch hardening

### 11.4 Launch kit
- [ ] **Choose, register and configure the production apex domain.** `hamyar.ir` is a
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
> **Removed, deliberately: "collect real هلو/سپیدار/محک exports and pin them as
> fixtures".** This was never a dependency — it was an *upgrade* to the header-hint
> guesser, and the owner has decided not to collect the files. Recording it as a scope
> decision rather than leaving it open, because an unticked box reads as an unmet
> dependency and this one is not: nothing is waiting on it and nothing is degraded by
> its absence.
>
> **What the import actually relies on, and why the guesser was never load-bearing**
> ([ADR 0013](adr/0013-flat-product-import.md)): the mapping screen was built not to
> know these layouts. Three things carry the weight, and all three shipped in 11b —
>
> - a **dry run** before anything is written,
> - an **explicit user mapping**, with the guesser's suggestion as a default the operator
>   overrides rather than an answer they have to accept,
> - **per-row verdicts**, so a wrong guess surfaces as "this row would do X" on a screen
>   before it is a wrong product in the catalogue.
>
> The guesser stays tuned to its current guesses. That is the accepted mitigation, not a
> temporary one: a shopkeeper whose column names are unrecognised maps four columns by
> hand once, which is a minute of work, not a blocked onboarding.
>
> Inventing plausible column names instead would have been the genuinely bad outcome —
> it would tune the guesser to a fiction **and look tested**. Not doing that remains
> right; what has changed is that the alternative is no longer waiting to happen.
- [ ] **Branded error pages (404 / 403 / 419 / 500)** — new, found in production on the
      staging box. `resources/views/errors/` **does not exist**, so every error in this
      Persian, RTL product renders as the framework's bare `<html lang="en">Not Found`.
      That is not only ugly: `ResolveTenant` aborts with written Persian messages —
      «فروشگاهی با این نشانی پیدا نشد.» and «دسترسی به این فروشگاه موقتاً غیرفعال است.» —
      and **nobody has ever seen either of them**, because there is no view to render
      them into and `APP_DEBUG=false` withholds the default. 419 matters most of the
      four: a session that expired while a shop was mid-invoice currently produces an
      English page with no explanation and no way back. Needs the `hamyar-ui` skill and
      a pass through `/design`, so it is UI work rather than a one-line fix
- [ ] Demo tenant with rich Persian data
- [ ] 5-minute owner onboarding tour
- [ ] Terms + privacy pages
- [ ] Public landing page per `docs/design-system.md#landing`: signature live thermal receipt hero, specced section order, Blade + Tailwind, JS ≤ 180KB gz, LCP < 2.5s on 4G, `prefers-reduced-motion` honoured

> ### ⛔ DECISION GATE 5
> Show the landing at wireframe and first-styled-pass before polishing.

### Phase 11 — Definition of Done
- [ ] Staging deploy from CI — staging is deployed and `bin/deploy` has now run end to end
      four times, but **from the box, not from CI**. No workflow builds or pushes an image;
      the repository is private, so wiring this needs a registry decision and a token
      (GHCR + a PAT, or a self-hosted registry). Deliberately not guessed at
- [x] Restore drill log committed — [`docs/restore-drills/20260820T104721Z.md`](restore-drills/20260820T104721Z.md), RTO 102s observed
- [x] Load test report in `docs/` — [`docs/load-tests/2026-08-20.md`](load-tests/2026-08-20.md). **The aggregate threshold fails** (p95 1.62s against 1000ms) with **zero errors** in 1339 requests; `/dashboard` is the cause and is 1.3s with a single user
- [ ] Go-live checklist all green

---

## Phase 12 — Metered plans: every module open, a monthly credit per feature, a three-rung ladder

**Goal:** replace "a plan is a bundle of modules" with "a plan is how much work a shop may
record in a Jalali month". Every module opens for every shop; every kind of work has a
monthly credit that refills at 00:00 Tehran on the 1st; the first rung is free; three plans
form a ladder and running the credit out is the moment a shop upgrades. Design, alternatives
and the full metric matrix: [ADR 0018](adr/0018-metered-plans.md) — **Accepted at Decision
Gate 6, 2026-08-29**.

Owner direction, 2026-08-29: «همه امکانات برای همه باز باشه اولش ولی محدودیت داشته باشن …
همه موارد و امکانات سایت محدودیت تعداد داشته باشن … اگه به محدودیت خوردن … اپگرید کنن به پلن
بالاتر … در کل ۳ تا پلن.» And at the gate, the window: «کلاً می‌خوام سقف‌ها ماهانه باشه نه
روزانه. یعنی هر کاربر برای هر ماه یه کریدیتی برای هر امکانات سایت داشته باشه … دقیقاً مثل
پلن‌های فعلی جی‌پی‌تی و کلاد که تا یه حد مصرف رو رایگان دارن، یه تایمی ریست میشه.»

**Supersedes** the module-bundle premise of 2.1/2.3 (`plan_module`, add-ons,
`EnsureModuleEnabled` as a *plan* gate) and the 11.4 "validate final pricing" task's
framing (prices are now per-quota-ladder, not per-module-bundle; the competitor check still
happens, at the gate). Phase 2's ticked boxes stay ticked — they were true when ticked.

**Operational note (2026-08-29):** there is currently **no production server** (the owner
will provide a new one). PRs merge on green as usual; `bin/release --deploy` is suspended
until the new box exists, and nothing in this phase may be reported as shipped until
`bin/smoke` passes against it.

**Ships in order.** Each PR is small, independently green and mergeable; the numbering is
the dependency order.

### 12.0 Design and gate (docs only) — `0.14.1` · `0.14.2`
- [x] [ADR 0018](adr/0018-metered-plans.md) written, from a map of all 100 mutating actions
      across the 18 modules, three independent designs judged and merged, and 20
      load-bearing claims adversarially verified against the code (10 corrected)
- [x] This phase, and 2.3/2.6 marked `[→]`
- [x] **Gate 6 cleared 2026-08-29**; ADR rewritten to monthly windows and a free first rung,
      `TrialPolicy` scheduled for deletion, CLAUDE.md golden rule 7 replaced
- [ ] `docs/specs/platform.md` (Data, Feature gating → kill-switch, Limits, Events,
      Acceptance), `docs/specs/README.md` "Gating" rule, one Acceptance line per metered
      module spec, `docs/architecture.md`, `docs/load-testing.md` (load-test shops on
      `enterprise`), `docs/testing.md:74-100` (cites `isolation()`/`actingAsUserOf()` helpers
      that do not exist — the real primitives are the `isolation` group and
      `actingForTenant()`)

### 12.1 Billing bug fix — first, because the ladder's upgrade click is broken today
- [x] `BillingService::applyPayment()` writes `subscriptions.plan_id` + `plan_changed_at`
      from a `plan_id` now stored on the invoice (a paid upgrade extended the period and
      **left the shop on the old plan** — no test ever asserted the plan changed). A plain
      renewal deliberately does not stamp `plan_changed_at`
- [x] `Plan::getRouteKeyName() = 'code'`; `billing/index.tsx` posted `plan.code` to an
      id-bound route, so the upgrade button 404'd. `PlanResource` pinned to `id` so the
      panel's URLs do not move with it
- [x] `ForgetResolvedSubscription` listener on `SubscriptionActivated` (the event's docblock
      promised it since Phase 2; nothing in production code called `forget()`)
- [x] A paid invoice on a tenant with no subscription row now creates one — found on the
      way; it used to fire the event and return, granting nothing
- [x] `BillingPaymentTest`: six new cases, including the first in the suite that posts to
      `billing.subscribe` over HTTP

### 12.2 Shared kernel — no behaviour change
- [x] `app/Support/Quota/{Metric,Window,MetricRegistry,QuotaGuard,QuotaVerdict,QuotaExceeded,
      OutsideTransaction,UnknownMetric,NoQuota,PeriodClock}` + `Events/{QuotaWarning,LimitReached}`.
      `Window` is `Month | Total`; `PeriodClock::periodKey()` returns the Gregorian date of
      the first day of the Jalali month and `resetsAt()` **midnight Tehran** on the next one
- [x] No `MetricKind`: the window carries counted-versus-computed, and `Metric`'s
      constructor enforces the pairing (see the ADR note added with this PR)
- [x] `AppServiceProvider`: registry and clock singletons, `bindIf(QuotaGuard, NoQuota)`;
      `config/hamyar.php` (`quota.fallback_plan`, `quota.warning_ratio`,
      `quota.system_sms_daily_cap`)
- [x] `tests/Arch/QuotaBoundariesTest.php`: no module imports `Platform\Services\Quota`;
      `App\Support\Quota` imports no module and no HTTP
- [x] Unit tests for the registry's invariants and the month boundary (UTC-midnight and
      Gregorian-month-end controls that must NOT roll over)
- [→] Reporting's `ShopClock` promoted to the shared kernel — **not needed yet and not done**.
      It answers a different question (a SQL expression for bucketing a column by the Tehran
      day) and has ten call sites in Reporting. Moved to 12.12, where the Filament usage
      sparkline is the first thing outside Reporting that wants it

### 12.3 Counter, resolver, tables
- [x] Migrations `usage_counters` (+ `blocked_at`, covering index), `tenant_limit_overrides`,
      `usage_events` — RLS `allowPlatform: true` in the same migration; models without
      `BelongsToTenant`; `TenancyCheckCommand::PLATFORM_OWNED_TABLES`; `bin/check-quota-scoping`
      requires `where('tenant_id'` on every production query of the three
- [x] `tenants.entitlement_version`, with a write-through cache bump in `LimitResolver::bump()`
- [x] `DatabaseQuotaGuard` (two statements, every placeholder cast), `LimitResolver`
      (override → plan → fallback; **throws** when the fallback plan is missing),
      `UsageEvents` (savepoint-safe idempotent insert, `try` outside the transaction)
- [x] `subscriptions:expire` — the first writer `past_due`, `grace_ends_at` and `canceled`
      ever had; MRR stops counting lapsed shops; free plans exempt
- [x] `quota:prune` (weekly) + a `quota.pruned_at` line for `/health`
- [x] Tests: `ConsumeTest`, `AtomicityTest`, `LimitResolverTest`, `LifecycleTest`,
      `QuotaIsolationTest` (`isolation` group)
- [→] `subscriptions:apply-scheduled` (scheduled downgrade) → 12.4, where the catalogue and
      the Filament plan editor first make a downgrade reachable. Nothing can schedule one today
- [→] `quota:audit` → 12.12 with the other operator tooling: it reports on metrics that do
      not exist until the modules register them (12.7–12.11)
- [!] **No fork-based concurrency test in CI.** Written, then removed — forking inside
      PHPUnit is fragile for reasons unrelated to the code under test, and a build that
      hangs occasionally teaches people to re-run rather than read. `AtomicityTest` asserts
      the two deterministic properties instead: one statement per spend (a refactor into
      read-decide-write fails it, and that refactor *is* the double-spend bug) and the cap
      evaluated against committed state (a decision made from a stale read is refused).
      Recorded in `DECISIONS-FOR-REVIEW.md` rather than left for a green suite to imply

### 12.4 Catalogue and Filament limits — **numbers from Gate 6**
- [x] `PlanCatalogue::plans()` carries the monthly matrix; `['modules']` and add-on prices
      removed; `syncModules()` writes `is_enabled = true` on create and leaves it after
- [x] Data migration: `modules.is_enabled`, `plans.sms_credit_grant_count`, the three legacy
      limit keys renamed in place, `invoices_per_month` and `sms_credit_bonus` deleted, and
      every `trialing` subscription moved to `active` on the free plan so no row is left in
      a state nothing writes
- [x] `PlanForm` is driven by `MetricRegistry` — one field per metric, grouped by owning
      module; `EditsPlanLimits` translates between the flat field names and `plan_limits`
      (a Filament field name may not contain a dot and every metric key has one) and bumps
      every tenant on the plan; `PlansTable` counts limits, not modules
- [x] **`TrialPolicy` deleted** with the trial branches in `SubscriptionResolver::limit()`;
      `TenantProvisioner::startTrial()` → `startOnFreePlan()`; a new shop is `active` on a
      zero-price plan with no period, and `tenants.status` is `active` rather than `trialing`
- [x] `RevenueOverview`'s dead "trials in progress" stat replaced by free-shop count — the
      upgrade pool, and a number that is not permanently zero
- [→] `subscriptions:apply-scheduled` (scheduled downgrade) → 12.12, with the Filament
      cancel action that is the only thing able to schedule one

### 12.5 Every module open; lapse falls to the free plan; landing — `0.15.0`, `BREAKING`
- [x] `SubscriptionResolver::grantedModuleCodes()` → `Module::enabledCodes()`;
      `EnsureModuleEnabled` asks "have we switched it on" and says «این بخش موقتاً در دسترس
      نیست»; `features()` reads the DB rows so the panel toggle is honoured;
      `Module::is_enabled` + `isEnabledPlatformWide()`; `ModuleForm`/`ModulesTable` show the
      switch instead of an add-on price; `Subscription::grantedModuleCodes()/addons()`
      `@deprecated`, dropped in 0.16.0
- [x] `ModuleSwitchTest` (was `PlanGatingTest`, asserting the opposite);
      `DashboardTest` free plan sees every card; `PriceListSecurityTest` and
      `MoadianSubmissionTest` add-on fixtures deleted; `OnboardingTest` expects active +
      free; `BillingPaymentTest`'s trial case became the free-plan case
- [x] **Same release:** `LandingController` loads `plans.limits` + the registry, the pricing
      rows list monthly credits with «رایگان» on the first rung, the add-on shelf is gone,
      the trial CTAs and `terms.blade.php` §3 are rewritten. `landing.js` untouched
- [x] Billing page: `PlanCard.limits` replaces `modules`, free plan shows «رایگان»
- [→] `SeedPlatformVolumeCommand` → `enterprise` and the k6 note → 12.12, with the rest of
      the operator tooling; nothing meters yet, so nothing can refuse the load test today

### 12.6 Being blocked, meters, banner (gallery first)
- [ ] `bootstrap/app.php` renders `QuotaExceeded` → `back()->withErrors(['quota'])` +
      `quota_block` (422 JSON off-Inertia); `quota_block` and `usage` shared props;
      `types/index.d.ts`
- [ ] `EnsureQuotaAvailable` middleware (`quota:<metric>[,<n>]`), not on the POS route;
      test that every route key is registered
- [ ] `UsageMeter`, `QuotaBlock`, `UsageBanner` on `/design` (ok / warning / reached /
      blocked / unlimited / total; can_upgrade / cannot / top rung / bulk / lapsed);
      `QuotaBlock` rendered once in `app-shell.tsx`
- [ ] `return_to` persisted on `payment_attempts`, validated as a same-host relative path,
      honoured after `applyPayment`; `BillingController` `?upgrade=<code>`
- [ ] `UsagePropTest`, `MiddlewarePrecheckTest`
- [ ] Separate task, not on the quota path: a shared `<FormErrors>` domain component and the
      ~25 forms that render only field-keyed errors (CLAUDE.md "a home for errors that
      belong to no field")

### 12.7 Sales and Inventory call sites
- [x] `FinaliseInvoice::finalise(…, bool $metered = true)`; `DeliverTicket` passes `false`
- [x] The POS quote path takes its number and its credit in ONE transaction — it called
      `CounterService::nextFormatted()` with none, which that class throws on, so the quote
      path was one concurrent pair of requests away from two quotes sharing a number
- [x] `UnitStateMachine::recordAcquisition(…, bool $metered = true)`;
      `ReceivePurchaseInvoice` consumes `n` once for the delivery; `TradeInIntake` counts
- [x] `TransferService::dispatch`, `StockCountService::apply`
- [x] Every metric registered by its owning module with `afterResolving(MetricRegistry)`
- [x] `withUnlimitedQuota()` opt-in; `ConcurrentFinalisationTest` uses it (fifty invoices
      in one test is about the counter, not about credits)
- [→] `bin/check-quota-lock-order` and `bin/check-quota-in-transaction` → 12.12. The
      scoping gate shipped and already caught two real findings; the other two are lint for
      a pattern that now exists in one place per module and is easier to check by reading

### 12.8 Catalog, CRM, Purchasing
- [x] `ProductController@store` (+tx), `ProductImporter` consumes `n = counts[create]`
      after the walk — the count does not exist until the file has been read, and the
      transaction still makes it all-or-nothing
- [x] `PartyController@store` (+tx — the party and its contacts were two statements with
      nothing wrapping them, so a failure between them left a customer with no phone
      number), `PartyImporter`, `FollowUpController@store` (+tx)
- [x] `ReceivePurchaseInvoice::receive`
- [→] `quota` payload on the dry-run/analyse endpoints → 12.13 with the preview UI that
      would show it

### 12.9 Repairs, Installments, Treasury
- [x] `TicketIntake::take`, `CreateInstallmentPlan::fromInvoice`,
      `TransferBetweenAccounts::transfer`, `RecordCashTransaction::record` (manual only —
      the recurring generator can backfill months in one run and must not be refused)
- [ ] `cheques.cheques` and the two Treasury capacities are registered and priced but
      **cannot be reached**: no route creates a cheque, a recurring template or a rental
      contract. The box stays open with the reason (CLAUDE.md: no route, no screen, no tick)

### 12.10 Messaging, Files, Reporting, Storefront
- [x] `SendSms::send` — `record()` not `consume()`, so a queued automation never throws;
      a refusal is a fifth suppression reason beside the four the shop can already read.
      `systemMessage: true` bypasses quota and wallet
- [x] `FileStore::attach` — both credits and the row in one transaction, and **the object
      written after the row**: the old order left an orphaned blob nothing referenced if
      anything failed after the upload
- [x] Every `*ReportController@export`, counted after a successful build via `MetersExports`
- [x] `PriceListAccess::mint`
- [→] `SendCampaign` pre-flight → 12.13 with the campaign screen

### 12.11 Seats and branches — closes the never-enforced `users`/`branches`
- [x] `UserController@invite` (+tx, seat reserved at invite) and `@toggleActive` on
      re-activation — without which deactivate → invite → re-activate is a three-click
      back door; accept deliberately does not re-check
- [x] `BranchController@store` (+tx); the default branch counts

### 12.12 Events, analytics, Filament ops
- [ ] `UsageEvents` writer (`warning` afterCommit; `blocked`/`bulk_blocked` from the exception
      handler after the rolled-back tx; `upgraded_after` attribution within 7 days)
- [ ] Messaging `quota.warning`/`quota.reached` automations (default off, owner's mobile,
      `systemMessage`); `SubscriptionRenewalDue` finally gets a listener; reminder copy
      «renew or fall to پایه quotas»
- [ ] Filament: `LimitOverridesRelationManager`, `TenantUsage` page (30-day sparkline via
      `ShopClock::dayOf`), `QuotaPressure` and `QuotaConversion` widgets, «لغو در پایان دوره»
      action; dead surfaces removed (`ListSubscriptions` `CreateAction`, `TenantForm`
      `is_active`)
- [ ] `EventsTest`, `AdminPanelTest` additions

### 12.13 Shell, dashboard, billing UI
- [ ] `UsageBanner` in the shell; dashboard «سهمیهٔ امروز» as a deferred prop; billing
      current-plan meters + `PlanCard.limits`; browser test: POS at the cap shows
      `QuotaBlock` RTL at 390 px with the prorated CTA; Manager sees «از مدیر بخواهید»

### 12.14 Inertia error pages (separate, any time)
- [ ] `resources/js/pages/errors/*` via `withExceptions(respond)` so 403/404/419/500 render
      RTL and branded (the 11.4 "branded error pages" item, done the Inertia way)

### 12.15 Drop the bundle tables — `0.16.0`
- [ ] Drop `plan_module`, `subscription_addons`, `modules.is_addonable/addon_price`,
      `SubscriptionAddon`, `Subscription::addons()`; `ModuleResource` (blue/green rule: one
      release after they stop being read)
- [ ] Remove `laravel/pennant` if Gate 6 item 15 says so

### Phase 12 — Definition of Done
- [ ] A shop on the free plan runs its monthly `sales.invoices` credit out at the till, sees
      `QuotaBlock` with the prorated price, pays in sandbox, lands back on the same form and
      finalises the next invoice — with no counter reset
- [ ] A shop that never pays keeps working for ever on the free plan, and its credit refills
      at 00:00 Tehran on the 1st of the Jalali month
- [ ] `ConcurrencyTest` green under the CI `NOBYPASSRLS` role
- [ ] Every metered key has an enforcement-site test and an isolation test
- [ ] The landing rows show monthly quotas, not modules, advertise no add-ons, and the
      first rung reads «رایگان»
- [ ] `bin/smoke` passes against the new production box (whenever it exists)

> ### ✅ DECISION GATE 6 — CLEARED 2026-08-29
> Every item answered by the project owner the same day the design was presented. Two answers
> changed the design as written; both are applied in [ADR 0018](adr/0018-metered-plans.md),
> which is now **Accepted**.
>
> **The window is a month, not a day.** «کلاً می‌خوام سقف‌ها ماهانه باشه نه روزانه. یعنی هر
> کاربر برای هر ماه یه کریدیتی برای هر امکانات سایت داشته باشه … دقیقاً مثل پلن‌های فعلی
> جی‌پی‌تی و کلاد که تا یه حد مصرف رو رایگان دارن، یه تایمی ریست میشه.» One credit per feature
> per Jalali month, refilled at 00:00 Tehran on the 1st. `Window` therefore has two cases,
> `Month` and `Total`, and no day bucket exists anywhere. This also retires item 10 (the burst
> allowance) as a problem rather than deferring it as a feature.
>
> **The first rung is free**, and it replaces the trial. `TrialPolicy` and the 14-day trial
> are deleted rather than inverted; a new shop starts `active` on a zero-price plan with no
> period; a lapsed paid shop falls back to the same place. `messaging.sms` is **0** on it —
> the one quota that costs cash per unit is funded by the wallet, not given away.
>
> The rest, as recommended: limit matrix as tabled but rewritten to monthly numbers, revisited
> after 30 days of `usage_events` (1) · lapse never locks a shop out (4) · repair-delivery
> invoice exempt (5) · automated SMS in the same bucket as manual (6) · voids and returns
> never refund (7) · `module:` middleware kept as a platform kill-switch (8) · enterprise keeps
> finite operational ceilings, 25 seats and 50 GB, lifted per shop by an override (9) ·
> «سازمانی» renamed «نامحدود» (11) · golden rule 7 reworded, now in CLAUDE.md (12) · prices:
> `basic` = 0, `pro` and `enterprise` unchanged until the 11.4 competitor check (13) · Moadian
> never metered (14) · `laravel/pennant` removed (15) · the platform pays for system SMS, hard-
> capped per tenant per day (16).
>
> **One scope note from the owner, recorded because it changes priority rather than design:**
> Moadian is low priority for a later phase — «کلا بیشتر مغازه‌ها معاف از مالیاتن اونو
> نمی‌خوان». Nothing in Phase 12 depends on it; the kill-switch (item 8) is what keeps it off.


---

## Anti-goals — do NOT build unless explicitly asked

Native mobile apps · full online-ordering/e-commerce checkout · double-entry GL beyond
the specified ledgers · multi-currency · Kubernetes · GraphQL · microservices.
