# MobiShop — Progress log

One line per completed roadmap task. Newest at the bottom. Format:

```
YYYY-MM-DD (jYYYY-MM-DD) · <phase.section> · what shipped · notable decision (if any)
```

Dates are Gregorian with the Jalali equivalent in parentheses, because the roadmap
is written in English but the business calendar is Jalali.

---

- 2026-08-06 (j1405-05-15) · 0.1 · Laravel 12 skeleton scaffolded around the pre-seeded files; `.gitignore` merged, not replaced.
- 2026-08-06 (j1405-05-15) · 0.3 · Dev stack up: php-fpm 8.4 + nginx + postgres:16 + redis:7 + minio + mailpit, driven by `Makefile`. Decision: Postgres provisions a **non-owner app role** so RLS can never be bypassed by the connecting user (owners and superusers bypass RLS by default).
- 2026-08-06 (j1405-05-15) · 0.2 · Documentation baseline written: ROADMAP (all 12 phases as checkboxes with the 5 decision gates preserved), architecture, testing, deploy, design-system, ADRs 0001–0003, and one spec file per module.
- 2026-08-06 (j1405-05-15) · 0.4/0.5 · Strict Laravel config (fa locale, UTC storage + Tehran display, CarbonImmutable, strict models) and the module system: `make:module` generator, auto-discovery, all 18 module shells.
- 2026-08-06 (j1405-05-15) · 0.6 · Quality gate wired: `composer test` = Pint → RTL grep → Larastan L8 → Pest. Decision: Pest runs on **real PostgreSQL, never SQLite** — SQLite cannot express RLS, so a green SQLite suite would prove nothing about the isolation guarantee we sell.
- 2026-08-06 (j1405-05-15) · 0.6 · `bin/check-direction-classes` matches real Tailwind value syntax rather than "any word characters", after false positives on English prose (`left-to-right`) and on compound utilities (`slide-in-from-left-2`). It has its own 39-test suite so that precision does not regress.
- 2026-08-06 (j1405-05-15) · 0.7/0.8 · Frontend: Inertia v2 + React 19 + TS, Tailwind v4 `@theme` tokens, shadcn with `rtl:true`, domain components (Money, Num, JDatePicker, StatusBadge, EmptyState) and the `/design` gallery.
- 2026-08-06 (j1405-05-15) · 0.9/0.10 · App shell and login placeholder; Laravel Boost installed with its guidelines below our golden rules plus an explicit precedence note; Boost and Playwright MCP registered and both verified connected.
- 2026-08-07 (j1405-05-16) · 0.2 · ADR 0004 (Postgres-only tests) and ADR 0005 (RTL gate matches Tailwind value syntax) written and approved; ADR 0002 already carried the NOBYPASSRLS app-role decision and now cross-links to 0004. ADR index added; pending proration ADR renumbered to 0006.
- 2026-08-07 (j1405-05-16) · 0.DoD · Repo pushed to GitHub (private) and CI proven end-to-end on PR #1 — all four jobs green. The first three runs failed for genuinely different reasons, each a real defect: the Vite manifest is a gitignored build artefact the Pest job never produces (`withoutVite()` in TestCase); Inertia's page-existence check defaulted to `js/Pages` and macOS's case-insensitive filesystem hid it until Linux CI; and `pest --ci` treats an empty `--group=isolation` run as a failure. Known issue: only `workflow_dispatch` triggers runs on this repo — `push` and `pull_request` produce none.
- 2026-08-06 (j1405-05-15) · 0.DoD · Visual verification found three bugs the server-side tests could not: a missing `TooltipProvider` blanking every page, bidi reordering that displayed `<Money/>` as `</Money>` and pushed minus signs to the wrong side of negative amounts (fixed with `<bdi>` isolation), and a 1px mobile overflow from shadcn's `shrink-0` on the search button. Lesson: Inertia feature tests assert the server payload, not that React mounts — browser checks are not optional.
- 2026-08-07 (j1405-05-16) · 1.1–1.3 · Tenancy core: central `tenants`/`domains`/`platform_users`, `TenantContext`, `ResolveTenant` middleware, `BelongsToTenant` + `TenantScope`, the `enableRls()` migration helper and `php artisan tenancy:check`. Decision (ADR 0007): the tenant id is pinned with **session-scoped `set_config(..., false)`, not `SET LOCAL`** — `SET LOCAL` is transaction-scoped and Laravel does not wrap a request in a transaction, so the literal reading of golden rule 1 would set nothing and every query would return zero rows.
- 2026-08-07 (j1405-05-16) · 1.4–1.5 · Onboarding wizard (reserved-subdomain list, live availability, one-transaction provisioning), per-tenant login with tenant-scoped throttling, Argon2id, the `module.action` permission catalogue and seven per-tenant roles.
- 2026-08-07 (j1405-05-16) · 1.7 · **Isolation suite v1** — 15 tests. Found and fixed a real cross-tenant session bug on the way: `SESSION_DOMAIN=.app.localhost` shares one cookie across every subdomain, so a session from shop A replayed at shop B resolves the stored user id through B's scoped provider and — ids being sequential — very likely authenticates the visitor **as a different person**. Cookies are now host-only, plus `EnsureUserBelongsToTenant` as a second line.
- 2026-08-07 (j1405-05-16) · 1.x · RLS also caught activity-log rows being written with no tenant attribution (rejected by `WITH CHECK` rather than silently mis-filed). A tenant-aware `Activity` model now stamps the context.
- 2026-08-07 (j1405-05-16) · Gate 1 · **Cleared.** Subdomain scheme and the seven roles approved as designed; `mobishop.ir` explicitly NOT confirmed, so golden rule 1b now forbids hardcoding the apex domain anywhere (tests included — they derive it from config via `centralUrl()`/`tenantUrl()`), and choosing it is a Phase 11 launch task. ADR 0007 approved and golden rule 1 amended to describe the real session-scoped `set_config` implementation.
- 2026-08-07 (j1405-05-16) · 1.4–1.6 · Deferred Phase 1 items finished: password reset (hash-only tokens, tenant-scoped, kills other sessions), TOTP 2FA with single-use recovery codes and a two-step enrolment, session management, invitations, activity-log viewer, `UserPolicy`/`ActivityPolicy`, and `--tenant=` via `InteractsWithTenants`.
- 2026-08-07 (j1405-05-16) · 1.x · Three bugs found while testing: **`SubstituteBindings` ran before our `tenant` middleware**, so route-model binding queried with no tenant and every bound tenant route 404'd — fixed with middleware priority; Carbon 3's `diffInMinutes()` is signed, so reset tokens never expired; and the Owner `Gate::before` was overriding the "nobody edits their own roles" invariant, which is structural rather than a permission.
- 2026-08-07 (j1405-05-16) · design · Visual language moved to a calm, near-monochrome system (ADR 0008): alternating white / `#f5f5f7` grounds, `#1d1d1f` text, ONE blue accent, pill actions, hairline borders, 1.5× section rhythm, frosted sticky chrome. Derived from apple.com opened in a real browser at 390/1280 with computed styles extracted, not from memory. Two values deliberately diverge from the reference because they fail WCAG AA: the accent is `#0066cc` (the brighter `#0071e3` measures 4.31:1 on the grey ground) and secondary text is `#6e6e73` (the reference's `#86868b` is 3.62:1). No SF Pro, no Apple assets — Estedad/Vazirmatn stay.
- 2026-08-07 (j1405-05-16) · design · Three bugs the gallery surfaced immediately: the pill rule lost the cascade because Tailwind's `utilities` layer outranks `components`; a secondary button was invisible when `--secondary` and `canvas-alt` were the same value; and the `ink` swatch vanished on the dark card. All fixed at token level.
- 2026-08-07 (j1405-05-16) · design · Propagated the visual language to every Phase 0/1 screen. `login`/`register` each had their own copy of the auth frame — both now use `AuthLayout`; the four settings screens each chose their own card padding — now `SettingsSection`. Added a Prettier config so the formatter stops rewriting the codebase to its own defaults. design-system.md, the mobishop-ui skill and ADR 0008 updated in step.
- 2026-08-07 (j1405-05-16) · 1.x · **Redirect-loop bug found by opening the app in a browser, not by the suite.** `Authenticate` ran before `ResolveTenant`, so the tenant-scoped user provider never found the session's user: `/dashboard` bounced to `/login`, which (having no Authenticate) resolved the user fine and bounced back. Every existing auth test used `actingAs()`, which injects the user into the guard and skips the provider entirely — so 192 tests passed against a completely unusable app. Fixed with an explicit middleware priority list, plus `AuthenticatedNavigationTest` which logs in through the real form and would have caught it. Also switched `SESSION_DRIVER` to `database`, which the sessions screen has always depended on.
- 2026-08-08 (j1405-05-17) · 2.1–2.3 · Plan catalogue (16 modules, 3 plans, limits), subscriptions with add-ons/coupons/grace, `SubscriptionResolver`, `EnsureModuleEnabled` route middleware and resolved `features` shared props. Gating **fails closed**: no subscription or a lapsed one grants zero modules, for the same reason RLS denies by default. Proration written as `ProrationCalculator` with exact-value unit tests; ADR 0006 is Proposed pending Gate 2. Note: the `plan_module` pivot is named explicitly on both relations — Laravel's convention would order it `module_plan`, which is not the documented schema.
- **2026-08-08** — Phase 2.1–2.3: module registry, three plans with limits, subscriptions
  and add-ons, `SubscriptionResolver` (fails closed), `EnsureModuleEnabled`, `features`
  Inertia prop, `ProrationCalculator` (ADR 0006, Proposed).
  Notable: `tenancy:check` flagged `subscriptions`/`subscription_invoices` as unprotected.
  Rather than exempt them, they keep RLS with an extra disjunct on an `app.platform` flag
  that only `TenantContext::runAsPlatform()` sets — narrow enough that a platform context
  still sees zero users (asserted). ADR 0002 amended. `tenancy:check` now runs in
  `composer test`, not just CI, which is why this reached CI in the first place.
  **Stops at DECISION GATE 2** — pricing/limits and the proration rule need sign-off
  before 2.4 (payments) and 2.5 (Filament) can build on them.
- **2026-08-08** — DECISION GATE 2 answered; Phase 2 unblocked.
  Item 0 (first, as instructed): `subscription_addons` and `payment_attempts` are now
  real tenant tables — `tenant_id` backfilled from the parent, FK, composite index,
  FORCE RLS with the `app.platform` disjunct, and both covered by `tenancy:check`.
  ADR 0002 gained a second amendment recording the lesson: *reachability is not
  protection*.
  Item 1: prices approved as provisional. Found that `PlanCatalogueSeeder` overwrote
  `price` on every run, which would have silently reverted any Filament edit — it now
  seeds business data (prices, limits, trial days, plan/module membership) on create
  only, and keeps syncing just the structure the code owns. "Validate final pricing
  against Iranian competitors" added to the Phase 11 launch checklist.
  Item 2: ADR 0006 accepted unchanged — truncate to 1 rial. The round-half-up-to-1,000
  alternative is recorded as considered and rejected.
  Item 3: `TrialPolicy` — Pro features, 14 days, no card, bonus SMS forced to 0, invoice
  volume borrowed from Basic and never exceeding the trialled plan.
  Item 4: golden rule 6 amended to list all 18 modules including Storefront (authorized
  by the owner; the omission was in the rule, not the code).
- **2026-08-08** — Phase 2.4 payments. `PaymentGateway` interface + Zarinpal (sandbox) +
  `FakeGateway`; `counters` with a row-locked `CounterService`; invoices, attempts,
  init/callback/verify; billing and receipt pages; renewal reminders at 7/3/1 days.
  Two real bugs surfaced by tests rather than by users:
  (1) `subscription_invoices.number` was globally UNIQUE while counters are per-tenant, so
  the second shop to ever buy a plan would have collided with the first — now
  `unique(tenant_id, number)`;
  (2) proration on an already-expired subscription yields zero remaining days and hence a
  zero invoice, so a lapsed shop would have renewed **for free** — `invoiceForPlan` now
  bills the full price whenever there is no live period left, and the same applies coming
  out of a trial. Both have regression tests.
  Note: `PlanCatalogueSeeder`'s split (structure synced, business data seeded once) means
  changing a price in code no longer changes any existing environment.
- **2026-08-08** — Phase 2.4 payments. `PaymentGateway` interface + Zarinpal (sandbox) +
  `FakeGateway`; `counters` with a row-locked `CounterService`; invoices, attempts,
  init/callback/verify; billing and receipt pages; renewal reminders at 7/3/1 days.
  Two real bugs surfaced by tests rather than by users:
  (1) `subscription_invoices.number` was globally UNIQUE while counters are per-tenant, so
  the second shop ever to buy a plan would have collided with the first — now
  `unique(tenant_id, number)`;
  (2) proration on an already-expired subscription yields zero remaining days and hence a
  zero invoice, so a lapsed shop would have renewed **for free** — `invoiceForPlan` now
  bills the full price whenever no live period remains, trials included.
  Both have regression tests.
- **2026-08-08** — Phase 2.5 Filament v4 super-admin panel. Pinned to the central domain
  and the `platform` guard; panel requests carry the `app.platform` flag via
  `PlatformPanelContext`, which opens billing across tenants and nothing else — asserted
  by a test that a shop's user list stays invisible from the panel.
  Resources are deliberately narrow (no plan/module deletes, no hand-made tenants, no
  editable subscriptions) because each of those would break a record that something else
  depends on. Impersonation writes its audit row before the session exists, into the
  shop's own activity log, and hands off via a two-minute signed link minted on the
  shop's hostname.
  One bug found while testing it: `URL::forceRootUrl($previous)` does not restore — it
  *pins* the generator root, so every later `route()` came out on the central domain.
  Cleared with `null` instead.
  SMS credit packages deferred to Phase 8: selling credit needs Messaging's ledger to
  sell into, and guessing that schema now would create a second balance to reconcile.
- **2026-08-09** — Phase 3.1: branches, warehouses, per-branch counters, branch access.
  Two things worth recording. (1) `counters` shipped in 2.4 without `branch_id`, which
  docs/specs/settings.md always required; fixed now, while the column is empty, rather
  than as a data migration on live invoice numbering in Phase 5. The unique index uses
  `NULLS NOT DISTINCT` so tenant-level counters (subscription invoices, no branch) stay
  constrained. (2) `branch_user` restriction is opt-in — no rows means every branch —
  because making the empty case restrictive would lock every user out of a feature
  single-branch shops never configure.
  Platform does not know Inventory exists: provisioning emits `TenantProvisioned` and
  Inventory listens (golden rule 6). The listener is synchronous and inside the signup
  transaction, with a test that forces it to throw and asserts the tenant is rolled back.
- **2026-08-09** — Phase 3.2 catalog: categories, brands, products, variant matrix,
  barcodes, price levels, price resolution and bulk updates. 318 tests green.
  Named the variant matrix column `options`, not `attributes` — Eloquent already uses
  `$attributes` for a model's raw column values, so that name would have made
  `$variant->attributes` silently return the wrong thing with no error.
  Barcode/SKU uniqueness uses partial indexes (`where ... is not null and deleted_at is
  null`): a plain unique would collide on the many NULLs, and a soft-deleted variant
  would keep its barcode reserved forever.
  Bulk pricing's guarantee is that `apply()` consumes the rows `preview()` produced
  rather than re-deriving them, so an operator cannot approve one set of changes and have
  a different set applied because a price moved in between.
  UI screens for catalog are deliberately not built yet — schema and services are tested,
  and the Inertia pages land as one pass over Phase 3 rather than piecemeal.
- **2026-08-09** — Phase 3.3 serialized units and 3.4 stock ledger. 355 tests green.
  IMEI uniqueness needed more than indexes. Two partial unique indexes stop the same
  number appearing twice in the same column, but a dual-SIM phone's `imei2` could still
  be registered as a different device's `imei1` — the same handset entered twice, which
  is exactly what the passport must never allow. Postgres cannot express uniqueness
  across two columns of one table as an index, so a `before insert or update` trigger
  does it. Tested directly.
  The unit state machine refuses `sold → in_stock`: undoing a sale is a return, which
  produces a `returned` unit and a credit document, and a silent status flip loses the
  money side entirely. Every transition writes history in the same transaction, and a
  refused transition leaves none.
  Stock quantity is a SUM with a covering index and never a column (golden rule 3). Both
  the service and a CHECK constraint reject zero-quantity movements; transfers write two
  rows so each side's ledger explains its own change; counts record the difference rather
  than overwriting a total.
- **2026-08-09** — **Roadmap reordered (owner-approved).** Phase 4.1 (parties) and 4.2
  (ledger engine) now run *before* 3.5 (Purchasing); the rest of Phase 4 keeps its
  original slot. Reason: purchasing needs suppliers, suppliers are parties, and the
  alternative was a minimal `parties` table that four later phases would extend — which
  accumulates migrations and assumptions nobody revisits. `product_units
  .acquired_from_party_id` has been an unconstrained bigint since 3.3 and gets its FK in
  4.1. Recorded in ROADMAP.md at both the new and the vacated positions.
  Also settled: Phase 3's UI screens are built as one coherent pass after 4.1–4.2 + 3.5,
  and Phase 3 is not Definition-of-Done until they exist, are built from `/design`
  gallery components, and have been verified in a real browser at 390/1280 in light and
  dark — the IMEI passport page especially, as the product's signature screen.
  The cross-column IMEI trigger is now documented in docs/specs/inventory.md with a
  pointer to the test that covers it.
- **2026-08-09** — Phase 4.1 parties + 4.2 ledger engine (run ahead of 3.5 per the
  approved reorder). 374 tests green.
  One table for customers, suppliers and همکاران, because in this trade one person is
  routinely all three and three tables would mean three balances for one human.
  The ledger enforces its invariants in two places on purpose: the database checks the
  shape of a *row* (exactly one of debit/credit, at least one of party/account), and
  `LedgerService` checks the balance of a *set*, which no per-insert constraint can
  express. Reversal writes the mirror image rather than deleting, so a statement shows
  both the error and the correction — the difference between a ledger and a spreadsheet.
  The statement's closing figure is asserted to equal `partyBalance()`; a statement whose
  total disagrees with the balance shown elsewhere is worse than no statement.
  `product_units.acquired_from_party_id` finally got its FK, closing the gap opened in 3.3.
- **2026-08-09** — Phase 3.5 purchasing. 392 tests green.
  Caught myself writing a supplier ledger posting that debited and credited the *same
  party* for the same amount — it balances, passes every constraint, and records nothing.
  The worst kind of wrong, because the books look fine. Added an `inventory` account type
  so received stock debits something real, and `ReceivePurchaseInvoice` now refuses to
  post at all if that account is missing rather than falling back to a no-op.
  Receiving is one transaction: allocate landed costs, write stock movements, create
  units with the first line of their passport, credit the supplier. A test forces a
  failure partway through and asserts nothing survives — stock without a payable is the
  discrepancy the transaction exists to prevent.
  Landed-cost remainders go to the largest line so the allocation sums to the charge
  exactly; dropping them would leave the books a few rial short per invoice, which
  accumulates into a discrepancy nobody can explain.

