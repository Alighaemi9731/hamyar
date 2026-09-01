# Hamyar — Progress log

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
- 2026-08-07 (j1405-05-16) · Gate 1 · **Cleared.** Subdomain scheme and the seven roles approved as designed; `hamyar.ir` explicitly NOT confirmed, so golden rule 1b now forbids hardcoding the apex domain anywhere (tests included — they derive it from config via `centralUrl()`/`tenantUrl()`), and choosing it is a Phase 11 launch task. ADR 0007 approved and golden rule 1 amended to describe the real session-scoped `set_config` implementation.
- 2026-08-07 (j1405-05-16) · 1.4–1.6 · Deferred Phase 1 items finished: password reset (hash-only tokens, tenant-scoped, kills other sessions), TOTP 2FA with single-use recovery codes and a two-step enrolment, session management, invitations, activity-log viewer, `UserPolicy`/`ActivityPolicy`, and `--tenant=` via `InteractsWithTenants`.
- 2026-08-07 (j1405-05-16) · 1.x · Three bugs found while testing: **`SubstituteBindings` ran before our `tenant` middleware**, so route-model binding queried with no tenant and every bound tenant route 404'd — fixed with middleware priority; Carbon 3's `diffInMinutes()` is signed, so reset tokens never expired; and the Owner `Gate::before` was overriding the "nobody edits their own roles" invariant, which is structural rather than a permission.
- 2026-08-07 (j1405-05-16) · design · Visual language moved to a calm, near-monochrome system (ADR 0008): alternating white / `#f5f5f7` grounds, `#1d1d1f` text, ONE blue accent, pill actions, hairline borders, 1.5× section rhythm, frosted sticky chrome. Derived from apple.com opened in a real browser at 390/1280 with computed styles extracted, not from memory. Two values deliberately diverge from the reference because they fail WCAG AA: the accent is `#0066cc` (the brighter `#0071e3` measures 4.31:1 on the grey ground) and secondary text is `#6e6e73` (the reference's `#86868b` is 3.62:1). No SF Pro, no Apple assets — Estedad/Vazirmatn stay.
- 2026-08-07 (j1405-05-16) · design · Three bugs the gallery surfaced immediately: the pill rule lost the cascade because Tailwind's `utilities` layer outranks `components`; a secondary button was invisible when `--secondary` and `canvas-alt` were the same value; and the `ink` swatch vanished on the dark card. All fixed at token level.
- 2026-08-07 (j1405-05-16) · design · Propagated the visual language to every Phase 0/1 screen. `login`/`register` each had their own copy of the auth frame — both now use `AuthLayout`; the four settings screens each chose their own card padding — now `SettingsSection`. Added a Prettier config so the formatter stops rewriting the codebase to its own defaults. design-system.md, the hamyar-ui skill and ADR 0008 updated in step.
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
- **2026-08-09** — Phase 3.6 transfers and stock counts. 407 tests green.
  A test caught me contradicting my own design: `stock_movements`' migration says
  serialized goods do not live in the quantity ledger — a phone's location is
  `product_units.warehouse_id` — but `TransferService` was writing a movement for every
  line, serialized included. That double-counts each handset, once in the unit register
  and once in the ledger, and every stock report would have disagreed with the shelf by
  exactly the number of phones transferred. Fixed, with a regression test that asserts
  zero movements for a serialized transfer.
  Transfers are two-step because the van journey is real; a shortfall on receipt is
  recorded rather than smoothed over. Counts are blind by default — a number on the
  screen is a number people count towards — and uncounted lines are skipped, because an
  unvisited shelf is not an empty shelf.
- **2026-08-09** — Phase 3.9a: the domain components the Phase 3 screens need —
  `StatCard`, `ImeiInput`, `DataTable` — plus their state matrices on `/design`, per the
  skill's rule that a component appears in the gallery before a feature page uses it.
  Verified in a real browser at 1280 and 390, light and dark, RTL. That verification
  earned its place immediately — it found three bugs no test would have:
  (1) a nine-digit toman figure needs ~270px and a quarter-width dashboard card gives
  158, so the number overflowed and was overlapped by the neighbouring card. `Money`
  gained `unitPlacement="block"`; wrapping alone did not help because a figure and its
  unit have no break opportunity between them.
  (2) `ImeiInput`'s validity icon used `end-3` while the input carried `dir="ltr"`. The
  logical utility was correct and the *direction context* was not, so the icon resolved
  to the left — the side where LTR digits start — and sat on top of the number. Fixed by
  putting `dir="ltr"` on the wrapper, not just the field.
  (3) A server error rendered a green tick beside red error text, because local Luhn
  validity ignored it. A syntactically perfect IMEI can still be rejected for already
  being registered; the error now outranks it.

- **2026-08-09** — Phase 3.9b: `PartyPicker` and `UnitPicker`, with the two lookup
  endpoints behind them, on the gallery with their full state matrices. Both take an
  injectable search function so the gallery can show what a live database will not
  reliably produce — still loading, no results, request failed. The party balance rides
  along with the name (choosing a party is the moment someone needs to know what they
  owe) and is withheld entirely, not nulled, without `crm.view_balance`. `UnitPicker` is
  a scan box rather than a dropdown: a complete 15-digit IMEI with one match selects
  itself and focus never leaves the field. `LedgerService::partyBalances()` was added for
  the same reason `StockLedger::onHandForMany()` exists — twelve rows were twelve
  aggregates per keystroke. Found on the way: the shared `MoneyValue` type declared
  `{amount, formatted}` while `Money::toArray()` has always sent `{value, formatted}`;
  pages had been working around it with local interfaces.
- **2026-08-09** — Phase 3.9c: catalogue screens — category tree, product editor with
  the variant matrix, price grid with a preview-then-apply bulk tool. Two tree guards an
  adjacency list will not give you: a move that would make a node its own descendant is
  refused (a cycle does not error, it silently removes the subtree and every product
  filed under it), and deleting a node with children is refused rather than cascaded.
  Slugs are generated with a null language so `Str::slug` does not transliterate Persian
  to an empty string and collide every category with every other. The matrix axes are
  re-derived from the existing variants rather than stored — the variants are the truth.
  Money crosses the wire as `{amount, unit}` so the toman→rial conversion happens once,
  on the server. These are the first **module-owned Inertia pages**, which needed three
  pieces of wiring that had never been exercised: a tsconfig include, a Tailwind
  `@source` line (without it the classes are simply absent and the page renders unstyled
  with no error), and an Inertia page namespace for the test helper — its finder is a
  `FileViewFinder`, so `Catalog::Products/Index` is a namespace hint and unregistered it
  reported every module page as missing.
- **2026-08-09** — Phase 3.9d: the **IMEI passport**. Nothing in the timeline truncates
  (a long supplier name wraps to three lines rather than hiding the fact the line exists
  to record), and events group by Jalali day in Tehran time so seventeen entries read as
  a story instead of a log. Each line is named in the words a shop uses — «از تعمیر
  برگشت و موجود شد», not «در تعمیر ← موجود». Decision: `App\Support\Documents\DocumentRegistry`
  in the shared kernel, because every passport line points at a document owned by another
  module and Inventory may not import Purchasing or CRM (ADR 0003). Resolvers take a
  list, never one id — a device transferred five times must describe five transfers in
  one query, so the longest passports are not the slowest. Sales and Repairs will each be
  a five-line registration.
- **2026-08-09** — Phase 3.9e: stock views, low-stock alerts and label printing.
  Quantity comes from **two** ledgers and they must never be added: a standard product is
  a SUM over `stock_movements`, a serialized one is a COUNT of `product_units`, because
  receiving a phone deliberately writes no movement. Low stock is opt-in (only products
  whose owner set a threshold) and separates "out" from "low" — one costs a sale today,
  the other is a purchase order this week. `PrintLayout` owns the three papers and the
  sheet on screen IS the sheet that prints; `@page` cannot be scoped to an element, so
  each layout is a whole page and emits its own size rule. Barcodes render server-side as
  Code 128 (Iranian shops mix EANs, their own numbers and supplier codes; a label that
  cannot be printed is the worse failure). Adds `picqer/php-barcode-generator`, which
  CLAUDE.md already named as part of the locked stack.
- **2026-08-09** — Phase 3.9f: browser pass over all ten screens at 390 and 1280, light
  and dark, RTL, zero horizontal overflow anywhere and no console errors. It earned its
  place again — three defects no test would have caught: the price grid rendered
  `82000000` unformatted in a cell whose entire job is being read; the passport spent its
  most prominent line repeating the model name that was already the page heading, when
  the IMEI is what every party to a device identifies it by; and the category tree showed
  four permanently-red bin icons, making deletion the loudest thing on a filing screen.
  Also added `DemoShopSeeder`, which builds the demo shop by driving the real services —
  the purchase is *received* through `ReceivePurchaseInvoice`, so landed costs, stock
  movements, the supplier credit and the passports are all genuine. Writing it exposed a
  latent bug: `DatabaseSeeder` used `WithoutModelEvents`, which mutes the `creating` hook
  that fills `tenant_id`, so every tenant-scoped insert would have been rejected by its
  own RLS policy. Nothing caught it while the seeder only created central rows.
- **2026-08-09** — Phase 3 closed. The purchasing intake, transfer and stock-count
  screens, plus purchase returns and the GRN — the screens the Phase 3 DoD is written
  around, and the last thing standing between the services and a shop being able to use
  them.
  The intake screen is the one this module exists for: paste twenty IMEIs, get a verdict
  per line (valid / mistyped / twice in this paste / already ours, with a link to the
  device), fix what is wrong, receive. Nothing commits until the batch is clean or the
  operator explicitly skips the bad rows, and the server **re-parses on commit** rather
  than trusting the browser's verdicts — between the preview and the commit another till
  can register a handset, and a client that decides which IMEIs are acceptable is a
  client that can register the same device twice.
  Walked end-to-end in a real browser rather than asserted from services: 10 IMEIs →
  received at 79,000,000 toman each (78,000,000 plus a 1,000,000 share of freight) →
  labels → two handsets scanned onto a transfer, dispatched and received → a blind count
  applied. Every figure reconciles with the movement ledger, and serialized transfers
  wrote zero quantity movements, so no handset is counted in both registers.
  Three things the browser found that no test had:
  (1) **The Owner `Gate::before` override makes document-state checks in a policy dead
  code.** `PurchaseInvoicePolicy::receive()` required `isDraft()`, and an Owner was still
  offered "دریافت کالا" on an already-received invoice — the override returns true before
  any policy method runs. Worse, the edit endpoints would then have reached the service
  and thrown a 500. A policy answers *who*, never *when*; document state now lives in a
  controller guard the override cannot skip, the UI flags are `permission && state`, and
  two regression tests act as an Owner specifically. The whole suite had used a
  Warehousekeeper, which is exactly what hid it.
  (2) A disabled brand-filled "ثبت ۱۰ دستگاه" sat beside the enabled skip button on a
  dirty batch, putting the eye on the one action that would not work. The unavailable
  button is no longer rendered at all.
  (3) The count summary read «-۱ عدد کسری» — a minus sign next to the word "short",
  which reads as a surplus. The word carries the direction; the figure is now absolute.
  The demo seeder gained a second branch (شعبه ونک). Provisioning gives a new shop one
  branch and one warehouse, which is right for a real signup and leaves a transfer with
  nowhere to go.
- **2026-08-10** — Phase 4.3–4.5: the customer page, the 360° timeline, follow-ups,
  loyalty points and the bulk import.
  The timeline is the piece with a design decision in it. Most of what a shop wants on a
  customer page belongs to other modules — what they bought (Sales), what was repaired
  (Repairs), what the shop bought *from* them (Purchasing) — and CRM may not import any
  of it (ADR 0003). So `TimelineRegistry` sits in the shared kernel, the same shape as
  `DocumentRegistry`: each module registers a contributor for its own records keyed by
  module name, and the page asks the registry. Contributors are handed a party **id**,
  never a `Party` object, because the object is the dependency the registry exists to
  avoid. Adding Sales in Phase 5 is one `contribute()` call and no change to CRM.
  A contributor that throws is caught, reported and **named on the page**: a customer
  page that cannot render because the SMS module had a bad day is worse than the same
  page missing its SMS lines, and a page silently missing its repair history is how
  somebody concludes a device was never brought in.
  Loyalty follows golden rule 3 — points are a ledger, the balance is `SUM(points)`, and
  expiry writes a negative entry rather than deleting a positive one, so a customer
  asking why their points vanished can be shown the line that took them. Redemption
  refuses to overdraw: points are not credit, and there is nothing to collect from
  someone who spends points they do not have.
  The import's dry run is the import itself stopped before the write, the same guarantee
  `BulkPriceUpdater` makes — an import that reports one outcome and performs another is
  discovered weeks later in the balances. It handles what a real shop actually sends: a
  UTF-8 BOM (which otherwise becomes part of the first header and makes that column
  unselectable), a semicolon delimiter from a Persian Windows Excel (which otherwise
  reads the whole file as one column), Persian digits, and the same person twice. An
  existing customer is matched and their **gaps filled, never overwritten** — the sheet
  is an import, not a source of truth, and a name corrected in the app last week must
  not be undone by a stale export.
  Two things the work turned up:
  (1) **`Party::tags()` was broken for eager loading.** It read `$this->tenant_id` inside
  `withPivotValue()`, and eager loading builds a relation on a fresh attribute-less
  instance — so `with('tags')` threw while a lazy `$party->tags` worked. Nothing had
  eager-loaded it until the customer page did. The pivot value now comes from the tenant
  context.
  (2) **Loyalty adjustment could not ride on `crm.update`.** Salesperson holds that
  permission, and granting points is granting something worth money. Added
  `crm.manage_loyalty`, which lands on Owner and Manager only — the same capability
  separation `inventory.view_cost` and `repairs.reveal_passcode` already make.
  `maatwebsite/excel` is named in CLAUDE.md's stack but is not installed, so the import
  is CSV-only today. The parser sits behind a `SpreadsheetReader` contract with a
  registry, so `.xlsx` is one more reader and no change to the import service.
  Not done: the Phase 4 screens have had no browser pass, so the DoD is left unticked.
- **2026-08-10** — Phase 4.6: `maatwebsite/excel` installed and the Phase 4 browser
  pass. Phase 4 closed.
  The package was declared in CLAUDE.md's stack from day one, so installing it realises
  an existing decision rather than adding a dependency. `XlsxReader` joins `CsvReader`
  behind the `SpreadsheetReader` contract; the import service knows about neither
  format and asks the registry for whatever opens the file it was handed. A `.csv` and
  an `.xlsx` of the same data are asserted to produce identical headers and mapping —
  one shape, one set of bugs, or the file format becomes a hidden variable in every
  support call.
  The xlsx reader normalises cells to strings at the boundary, which is where the
  interesting bug lives: a mobile number with no leading zero is stored by Excel as a
  *number* and comes back as `9.1211122e+9`. Rendered naively that is what lands in the
  customer record. It is now rendered digit-by-digit, with a test.
  The browser pass found five defects, none of which any test would have caught:
  (1) **A big «۰» where the truth was "nobody decided".** The unset credit limit
  rendered as zero on the customer page — the exact null-vs-zero distinction the column,
  the request and the service all take care to preserve, undone at the last step by the
  UI. `StatCard` now takes `number | null` and renders an em-dash for null.
  (2) **The page contradicted itself.** It showed a balance of 12,850,000 above a
  timeline reading "nothing has ever happened", because the opening balance lives in a
  column rather than as a ledger row and so nothing put it on the timeline. It is now a
  timeline entry of its own, dated to when the record was created.
  (3) **The same figure in two digit systems on one page.** The stat cards followed the
  tenant's Persian-digit setting while the timeline forced Latin. A timeline is prose,
  not a table; the forced Latin is gone.
  (4) **Counts inside Persian sentences carried Latin digits.** «همین شخص در سطر 2 همین
  فایل هم هست» — server-composed strings interpolate a raw int and bypass `<Num/>`
  entirely. Six such messages across four modules now convert at the point of
  composition. Document numbers (`CNT-000001`) deliberately stay Latin: they are
  identifiers, not quantities.
  (5) **A toggle labelled with its current state**, which reads as an action and sends
  people the wrong way. The follow-up desk button now names where it goes and the
  heading carries which list you are on.
  Also fixed on the way: Pint's `strict_comparison` rewrote a float/int comparison in
  the new reader into a branch that could never run. The dead branch is gone and the
  reason is in a comment, because the next person to write `$float === (int) $float`
  will get the same "fix".
  The timeline contract is now written into docs/specs/crm.md — contributors
  self-register, are handed a party id rather than a `Party`, and a failing module is
  named on the page. Phase 5, 6 and 8 implement against it.

## 2026-08-12 — Phase 5: sales, the POS, trade-in, installments, Z-report

**5.1–5.2 The till.** The screen a shopkeeper sees a hundred times a day, so the design
constraint is latency and the keyboard, not features. One scan box, not two pickers:
`PosScanner` resolves an IMEI, a serial, a barcode, an SKU or a typed product name,
because the person holding the reader does not know which of those our schema calls it.
Exact matches win outright — a fifteen-digit number that also appears inside some
product's name must not turn a finished scan into a list — and a sold or reserved handset
resolves *with its reason attached* rather than to nothing, so nobody goes to the shelf
hunting a phone that left yesterday.

The basket lives in the browser and posts once. A request per scanned line means waiting
on the network once per item, which on a shop's connection is the pause that loses to a
paper notebook. The cost is a deliberate mirror of `InvoiceTotals` in TypeScript;
`resources/js/lib/invoice-totals.ts` documents why that is defensible and names the
browser pass as the thing that keeps the two honest. The server still recomputes VAT and
cost and re-locks stock: the browser may name a **price** (it is negotiated at the
counter) but never a tax rate or a cost.

Change is stored, not just displayed. The drawer keeps the settled amount; the tendered
figure rides along in a new column so a reprint next week still says what change was
given. A CHECK constraint stops tendered falling below settled.

**5.3 Returns, and the void boundary.** A return is a new numbered credit document, never
an edit of the sale — the sale happened, and a closed month must keep saying so. A
returned handset goes to `returned`, not straight back on the shelf: nine days in
somebody's pocket changes what a phone is worth, so it becomes sellable only when
somebody ticks that they have checked it and records the grade. Void is refused outright
on an invoice that has returns against it, because voiding one would tell the ledger a
customer was never charged while they are standing outside holding a refund.

**5.4 معاوضه as a tender, not a discount.** The distinction is not cosmetic: a discount
reduces the price of the new handset, which computes VAT on a smaller base and understates
both the sale and the tax. What actually happened is two transactions on one piece of
paper — the shop sold a phone at full price and bought one at an agreed price, and the
second settles part of the first. So the trade-in debits inventory and the invoice total
never moves.

**5.5 Installments.** Flat profit on the financed principal, Jalali months (not
thirty-day steps), and the last row absorbs the division remainder so the schedule sums
to the contract total exactly. One `ScheduleTable` component serves the screen and the
printed contract, because the whole point of a contract is that the paper and the screen
say the same thing.

**5.6 The QR, and what a stranger may read.** The public invoice link is signed and
deliberately never expires — a customer photographs their receipt and opens it eight
months later to check a warranty date. `QrRenderer` walks the encoded matrix itself
rather than using Bacon's SVG writer, which needs `ext-dom`; a deploy should not fail on
a missing PHP extension for a square of dots.

Writing its tests turned up three genuine security defects. **An enumeration oracle**:
`SubstituteBindings` runs in the `web` group ahead of a route's own `signed` middleware,
so a bound `{invoice}` 404'd before the signature was examined — making 403-vs-404 answer
"has this shop issued invoice 4,000 yet?" with no signature at all. **An IMEI leak**:
`DraftInvoiceWriter`'s fallback description embedded the IMEI, which then travelled to the
public page — the one place a serial number must never appear, since that is what a
stolen-handset check keys on. **Staff props to strangers**: the public page renders
through the same shared-prop middleware as the app, so platform announcements and the
shop's plan flags were being served to anyone with the link.

**5.7 Profit and گزارش Z.** The whole profit engine is one stored column,
`cost_snapshot`, written once at finalisation and never recomputed — under Iranian
inflation a report quoting today's cost for last month's sale is not a report with a
small error in it, it is a fabrication. Revenue is net of VAT, because tax collected is
the state's money briefly held and counting it would inflate every margin by the rate.

The Z-report answers exactly one question — how much cash should be in this drawer — and
every figure is chosen to make that comparison possible or to explain a difference in it.
Takings break down by method *and* by account; cheques and trade-ins are reported so the
day adds up but kept out of the expected-cash figure; cash refunds are subtracted **and
shown**, because a till that is 3,000,000 short needs the refund that explains it; and
voided invoices are counted rather than hidden, since that is how a till gets quietly
abused.

**5.9 The DoD walk, and the three defects it found.** Walked end-to-end in a real browser
on 2026-08-12 — an iPhone scanned by IMEI, a trade-in taken, three cheques, the remainder
on a six-month plan, every paper printed, the receipt's QR followed to the public page on
a 390px viewport. Every figure reconciled to the rial. Three things no test had asked
about:

(1) **A cheque booked against the cash box.** The POS pre-fills every payment row with the
default cash account and then hides the field once the operator picks چک, so the id rode
along on a payment that puts nothing in the drawer — and the Z-report showed صندوق
۱۱۵٬۰۰۰٬۰۰۰ directly beneath an expected-cash figure of ۳۰٬۰۰۰٬۰۰۰. Two numbers
contradicting each other on one screen is how the person closing the till stops trusting
both. Fixed at the source, in the browser, and in the report query.

(2) **A money field that ate its own input.** `MoneyField` rewrote its value on focus to
strip separators, which re-renders the input while the browser holds a selection — and a
re-render collapses that selection, so typing over a selected «۶۵٬۰۰۰٬۰۰۰» appended
instead of replacing and the box showed ۶٬۵۰۰٬۰۰۰٬۰۱۵٬۰۰۰٬۰۰۰. The stripping also defeated
its own purpose, since changing the value on focus moves the caret to the end. The Catalog
price grid never had an `onFocus` for exactly this reason; this now matches it.

(3) **Every instalment badged «نزدیک سررسید»**, including one due six months out, because
the table mapped `pending` to `due_soon` unconditionally. A contract where every line is
urgent is a contract where no line is. The reading is now derived from the due date.

The IMEI Luhn guard also refused a made-up trade-in number mid-walk, which is the guard
working as designed.

**Deliberately not built.** Commission accrual (5.1): the rule — percent of sale, of
margin, tiered, per-salesperson — is a business decision nobody has made, and inventing
one would prejudge it and produce a second set of numbers to reconcile. The trade-in ID
scan (5.4) stays blocked on the Files module wiring, exactly like the seller-ID
attachment in 3.3; the identity check and the HAMTA acknowledgement are recorded, the
image is not yet stored. Both are for Gate 3.

## 2026-08-12 — DECISION GATE 3 cleared, and commission on margin

**The ADR that approved itself.** ADR 0009 was found marked "Accepted at DECISION GATE
3", describing an alternative as "rejected at the gate" — for a gate that had not been
held. The roadmap still carried it as ⛔ open, and Gates 1 and 2 both have explicit
CLEARED blocks this one lacked. Corrected to Proposed, taken to the gate for real, and
approved unchanged. The history is recorded in the file rather than tidied away: an
ADR's only value is that a later reader can trust what it says was agreed.

**Approved at the gate.** Rounding as written — step 1,000 rial, `nearest`, no threshold
on the printed «گرد کردن» line. All four print layouts. VAT staying off by default,
raised explicitly rather than assumed, since it was a behaviour I had changed
unilaterally.

**Commission — a percentage of margin, not of turnover.** The alternative was simpler
and is what most shops say out loud, and it quietly breaks the incentive: discount a
100,000,000 phone to 90,000,000 and the sale falls 10% while the margin falls 25%. Pay
on turnover and the seller barely notices; pay on margin and the discount costs the
person who gave it. That proportionality is the whole point, and it is what the second
test asserts.

Computed net of VAT — tax collected is the state's money briefly held, and paying a
share of it would hand a salesperson somebody else's money. Floored to a whole toman
like every other derived figure (ADR 0009). Zero on a loss and never clawed back:
selling below cost is a decision made above the salesperson's head. Snapshotted with its
rate, for the same reason `cost_snapshot` is — payroll has already been run against the
old figure, and a rate changed in Mehr must not restate what was earned in Shahrivar.
The rate defaults to **0%**, not to something plausible: a shop that has never opened
the settings screen has not agreed to owe anybody anything.

**The uncomfortable part.** The figure is gated behind `sales.view_profit`, which means
the salesperson cannot see their own commission. That looks wrong until the arithmetic:
commission is a known percentage of margin, so telling somebody their commission tells
them the margin — and Gate 1 was explicit that a Salesperson is blind to cost and profit.
Shipping it visible would have quietly repealed a decision made two gates ago. A shop
that disagrees grants the permission, which is exactly the per-tenant override Gate 1
allowed for.

Phase 5 is now complete except the trade-in ID scan, which stays blocked on the Files
module wiring alongside the seller-ID attachment from 3.3.

## 2026-08-12 — Commission opt-in, and an audit of all nine ADRs

**The commission switch.** Gate 3 confirmed that a salesperson does not see their own
commission, because commission is a known percentage of margin and Gate 1 made the
Salesperson blind to margin. Pushback on that now has a switch instead of needing a code
change: `sales.view_own_commission`, off by default, granted per-tenant on the shop's own
Salesperson role exactly the way `inventory.view_cost` already is.

It is scoped to invoices the grantee actually sold, checked against `salesperson_id`
rather than trusted on its own — a grant that revealed every invoice's commission would
be `sales.view_profit` with extra steps, and would hand a seller the margin on their
colleagues' sales. Two tests pin both halves: their own invoice becomes visible, a
colleague's stays hidden, and the profit panel stays hidden in both cases.

**The ADR audit.** Prompted by ADR 0009, which was written marked *"Accepted at DECISION
GATE 3"* — describing an alternative as *"rejected at the gate"* — before that gate had
been held. All nine were checked against the roadmap's gate blocks and this log.

Two more inconsistencies, in opposite directions:

- **The ADR index called 0006 "Pending — Decision Gate 2"** while the file itself said
  Accepted at that gate. The gate cleared it on 2026-08-08; the index was never updated.
- **ROADMAP 2.2 still said "ADR 0006 is Proposed — needs sign-off at Gate 2"**, four days
  after Gate 2 signed it off, and carried a `[~]` box because of it.

The rest hold up. 0004, 0005 and 0007 name a dated approval; 0006, 0007 and 0009 name a
cleared gate. 0001–0003 record decisions the project owner had already written into
CLAUDE.md as law — the ADR documents them rather than proposing them, which is a
legitimate basis for Accepted and is now stated as such.

**The weakest one is 0008** (visual language). Its approval is an owner *request*
followed by delivery, with no recorded sign-off. Left Accepted, because nothing in it is
expensive to reverse — it is tokens in one stylesheet — but the index and the file both
say plainly that this is the thinnest provenance of the nine rather than dressing it up.

Every ADR now carries an **Approved by** line naming the gate, the PROGRESS entry or the
CLAUDE.md rule behind it, and `docs/adr/README.md` makes that a requirement for new ones:
a new ADR starts **Proposed** with its target gate named, and does not become Accepted
because somebody wrote it. `docs/PROGRESS.md` history was left alone — the 2026-08-08
line calling 0006 "Proposed pending Gate 2" was true on the day it was written, and a log
that gets edited to look correct in hindsight is not a log.

An ADR's whole value is that a later reader can trust what it says was agreed. A status
nobody can trace manufactures consent that was never given, and the next person to
disagree with the decision ends up arguing with a ghost.

---

## 2026-08-13 — Phase 6 (Repairs) complete: parts reach the bench, and a review finds three ways money leaks

Closed the phase. Kanban, delivery, the abandoned sweep and the DoD walk all landed as
planned; what the plan did not contain is most of what this entry is about.

**The service with no door.** `TicketParts` had been green since the parts commit —
reserve, consume, release, and the cross-module test proving a reserved screen is
invisible to the till. It had no route. The only way to plan a part into a job was
Tinker, and the phase would have shipped ticking a box for a feature no shopkeeper could
reach. Three routes now, one per decision, because a bench often plans two possible
fixes and fits one: consuming automatically on «آماده» would take a screen off the ledger
while it sits in the drawer.

The picker is not the POS scanner. That one resolves handsets by IMEI, applies reseller
price levels and gates cost on the till's permission — none of which a bench wants. A
technician fitting a screen is asking a stock question, so `PartLookup` depends on
Inventory instead, and quotes **available** rather than on-hand.

It also returns no cost. The first version formatted the weighted average into the
response, passed every test, and 500'd on the first real search: the test fixture bought
at exactly 200,000 while seeded stock averages 1,914,285 rial, which is not a whole
number of toman and which `Money::toArray` refuses. The crash is how it was found; it is
gone for a better reason. Cost is gated behind `inventory.view_cost` at the till, and a
parts picker must not hand it to everybody who can edit a ticket.

**The review.** An adversarial fan-out over the Phase 6 surface, every claim handed to
independent refuters. Three survived, and each was somewhere the module looked correct.

*The passcode leaked through the session, not the model.* Four layers guard an unlock
code and all four protect it once it has reached the model. A failed intake never gets
there: Laravel redirects with the old input, the framework's `dontFlash` covers only the
password fields, and `SESSION_DRIVER=database` with `SESSION_ENCRYPT` off puts the
customer's code in the clear one table away from the encrypted column that exists to hide
it. A photo one megabyte too large is enough. Every passcode test posted an intake that
*succeeded*, which is exactly why none of them saw it.

*A fitted part cost the shop nothing, as far as the books knew.* Repair lines are service
lines — that is what stops a part being deducted twice — but finalisation snapshots cost
by looking up a variant, and a service line has none. A screen bought for 200,000 and
billed at 900,000 read as 900,000 of margin. The overstated Z report is the smaller half:
commission derives from that margin, so the technician was quietly paid a percentage of
the customer's whole bill. On the walked example that is 508,570 rather than 700,000 —
191,430 rial on one repair, and nobody would notice until a year of payroll was
reconciled.

*Two tills could deliver the same device.* The service-line design removed the very lock
that protects the till from a double sell: `FinaliseInvoice` locks units, and a repair
invoice has none. A probe reproduced it — two invoices, the cash posted twice. The status
flip also sat *after* the invoice commit, which opened the same hole with no concurrency
at all: a process dying in between left a finalised, paid invoice on a ticket still
marked `ready`, and the natural retry wrote a second one.

**Left undone, on purpose and in writing.** The per-tenant checklist template builder,
the labour/services catalogue and outsourcing to an external technician are unticked on
the roadmap with a reason and a phase each. All three want infrastructure that arrives
later (Settings UI, Catalog, the party ledger), and building them here would mean
building them twice.

**What to carry forward.** Two of the three findings were invisible to tests that all
passed, and both for the same reason: the tests exercised the path that works. A form
that submits successfully never flashes old input; a fixture that buys at a round price
never meets the rounding guard. Worth testing the *failing* path of anything that handles
a secret or a computed figure.

---

## 2026-08-13 (later) — the Phase 6 DoD walk, and what walking it found

Two conventions captured first, both generalised from Phase 6 mistakes: secret-bearing
forms get a *failed*-submission test asserting the secret is absent from session storage,
flashed input and the error payload (`docs/testing.md`); and a roadmap box for
user-facing behaviour only ticks when a route and a screen reach it (`docs/ROADMAP.md`,
`CLAUDE.md`).

Then the walk: intake with checklist and photo at 390px → thermal receipt → Kanban →
approval link → parts → delivery signed with a finger → tracking as a stranger.
Screenshots in `docs/walks/phase-6/`.

It found five defects in a suite that was entirely green, and four of them were invisible
for the same structural reason — **the tests exercise the paths that work**.

**The camera never attached a photo.** A React functional updater read `e.target.files`
lazily, after the next line had cleared the input. Every intake posted with zero photos,
silently. That is the intake screen's whole purpose: three weeks later, when the customer
says the back glass was fine, the shop has a checklist and no picture. Server-side tests
passed because they build `UploadedFile` arrays in PHP and never touch the handler.

**Nothing could ask a customer for approval.** `QuoteApproval::request()` and
`approveByPhone()` had no route. The public `/a/{token}` pages could answer a question the
application had no way to ask — no quote, no link to send, no way to record a yes given
over the phone, which is how most Iranian shops settle this. The box was ticked. This was
the second instance of the rule captured an hour earlier, which is why it is now a
convention rather than a lesson.

**Tracking told customers they owed money they had already paid.** `amount_due` was
approved-minus-prepaid always, because a ticket could not find the invoice that settled
it. Delivery now records `sales_invoice_id`.

**An approval link outlived the job.** Quote, no answer, ticket rejected, phone handed
back — and the SMS link stayed live. Whoever held it could authorise work on a device that
had left the shop. Fixed at both layers.

**The public throttles were one bucket with two labels.** Laravel keys guest throttles on
`sha1($domain.'|'.$ip)` with no URI, so ten tracking refreshes spent the approval
allowance and the customer's link answered 429.

The adversarial sweep of the public surfaces raised 11 claims; 2 survived three
independent refuters each (the stale token and the shared bucket). The nine that died
were killed on reachability or consequence, which is the refuters doing their job.

**Carrying forward.** The failing path is where a rejected request puts things it was
never asked to keep, and the un-walked screen is where a green service turns out to have
no door. Both are now written down. Neither would have been found by more unit tests.

---

## 2026-08-14 — Phase 7 (Treasury, cheques, instalment collection) complete

**Spec-first, and it earned its keep.** The cheque posting matrix and the instalment maths
were written into `docs/specs/` before any code, because both are tables where a plausible
mistake produces a number a customer disputes at the counter — and where writing the
document afterwards turns it into a description of whatever got built.

The cheque matrix came from three independent derivations reconciled into one document.
All three reached the same central answer separately: **a received cheque settles the
customer's debt when it is taken, not when it clears.** The row two of them caught and most
specs miss: **re-presentation after a bounce does not post a deposit's lines.** The bounce
credited *collection* and debited the *party*, so the drawer account holds nothing for that
cheque — copying the deposit lines would drive it negative by the face value, permanently.

Every row of that table has its own test now, R1–R13 and I1–I7, in table order. Including
the boring ones: a matrix with three interesting rows tested and nine assumed is
untrustworthy the day the tenth is wrong.

**Two binding constraints fell out of the spec and shipped with the slice**, both verified
failing on the old code first. `creditCheck()` read the party balance alone, so a customer
who paid entirely in post-dated paper had unlimited further credit — exactly the customer a
limit exists to stop. And `VoidInvoice` reversed only batches referencing a `SalesInvoice`,
so voiding a cheque-paid invoice credited the customer in full and left the cheque asset
standing. Neither fix crosses a module boundary: CRM declares `PartyExposure`, Sales
declares `InvoiceSettlementGuard`, Cheques binds both.

**The "one crazy month" seeder was grown slice by slice from the first commit of the
phase**, with a reconciliation harness running after each. That was the right call: the
harness caught every slice that moved the figures, on the day it landed, instead of at the
end across six subsystems.

Its headline invariant had to be rewritten once, and the rewrite is the lesson. The first
version asserted exact figures for the whole month, and every slice broke it — which is a
number I have to update when I add a sale, and therefore a number I could update wrongly
while the books quietly stopped balancing. The claim that never needs editing:
`sum(all balances) == sum(all opening balances)`, across accounts and parties together,
whatever happened in between.

**Things found by building the screens rather than the tests.** `CrazyMonthSeeder` matched
accounts by name, so it built a parallel chart beside the demo shop's — two sales accounts,
two inventory accounts, and a collision on the one-default-per-tenant index for the till.
The ledger balanced throughout. Every test passed. It was visible the moment a treasury page
listed both.

**Deferred with reasons on the roadmap**, not silently: the rental reminder and the
instalment SMS nudge both belong to Phase 8, which owns every notification channel, and the
printable cheque receipt to Phase 9 with the shared print templates. The events those
features will listen on are already emitted.

**Two conventions captured.** Null-object defaults bind with `bindIf`, never `bind` —
provider discovery order must never decide which implementation wins, and the symptom is not
a crash but a guard that silently passes. And the period-keyed idempotency pattern is
written up in `docs/specs/treasury.md` as reusable, because Phase 8's birthday and reminder
automations need exactly that shape down to the Jalali key.

967 tests green.

---

## 2026-08-14 — Phase 8 (Messaging) complete

**The wallet is real money, so it was built before anything that could spend it.** Charge
before the gateway call, refund on refusal, and a test that scripts a gateway failure —
because a refund path with no test is a wallet that drains on every carrier outage. An empty
wallet suppresses rather than fails: a repair marked ready must not error because the SMS
credit ran out.

**Everything defaults OFF, and only an explicit boolean `true` turns an automation on.** A
shop that never configured messaging must not wake up to sent texts and a drained wallet.
`'yes'` and `1` resolve to off, because each is a guess about what somebody meant.

**Nine automations, all on events Phases 5–7 already emit.** No synthetic event names: an
invented `messaging.repair_ready` drifts from `TicketStatusChanged` the first time somebody
renames the real thing, and the automation goes quiet with no test failing. The three
date-driven ones have no emitter — nothing happens when a due date arrives — so a sweep
looks, keyed by Jalali period.

**Opt-out is asserted per automation, all nine.** The check lives at the door in `SendSms`;
a check in the automation gate would be one of nine callers getting it right, and the one
that got it wrong would be the birthday message.

**The busy day**, walked in the browser: one customer with a repair ready, an instalment due
and a birthday; a second with all three who opted out; three sweeps because a scheduler runs
hourly. Six rows — three sent at 300 toman, three suppressed at zero, each naming «مشتری از
دریافت پیامک انصراف داده است». Three *different* events to one number is correct; three of
the same is not, and a test that merely counted per number would have called the right
behaviour a bug.

**The best find of the phase was a tenancy defect, and it came from a test seeder failing.**
`RestoreTenantContext` cleared the tenant context unconditionally. Correct on a worker —
the next job may be another shop's. Wrong on `sync`, where a job runs inline inside the
caller's context: a repair transition that fired an SMS listener wiped the tenant of the
code that dispatched it. Recorded as [ADR 0010](adr/0010-job-context-teardown.md), including
the uncomfortable half — the same line had been probed one commit earlier and written up as
harmless-but-redundant, because the probe asked whether it was *necessary* and never whether
it was *harmful*.

**Four harness bugs, all of which made tests pass or fail for reasons unrelated to the
code:** a worker draining the wrong queue (and an isolation assertion that was true because
nothing ran), a queue connection cached across the suite, `queue:work`'s 128 MB ceiling
quitting after one job, and a throttle test querying a `jobs` table that `sync` never fills.
The last two are why `docs/testing.md` now says to instrument before hypothesising.

**Deferred with reasons on the roadmap:** the sms.ir stub (a second driver with no account
to test against is a guess at an API), price tiers and delivery polling (Phase 11, with
billing and a real gateway), and the notification bell (Phase 9, which builds the dashboard
it belongs on — the message log ships here and answers the same question).

1016 tests green.

---

## 2026-08-14 — Phase 9 started (foundation only)

Ended the session at a slice boundary with context running long, per the autonomous-run
hygiene rule. **Phase 8 is merged (PR #15).** Phase 9 has its foundation committed and
pushed on `feat/phase-9-reporting`; no roadmap box is ticked yet, because no roadmap item is
finished.

**Built and green (1026 tests):**

- `ReportPeriod` — Jalali range parsing done once for every report, inclusive end,
  backwards range swapped rather than rejected.
- `Jalali::startOfMonth()` / `endOfMonth()` — Carbon's find the Gregorian month, which falls
  mid-Jalali-month and makes "this month" cover parts of two.
- `SalesReports` — daily, by product, by salesperson, summary. Composes `ProfitEngine`
  rather than re-deriving, and mirrors its key names so a mismatch between two screens is
  visible.
- `GoldenNumbersTest` — pinned against the Phase 7 crazy month, each literal beside an
  invariant, cross-checked against a second code path where one exists.

**Where the next session picks up (ROADMAP Phase 9):**

1. 9.1 dashboard widgets — role-aware, on `ReportPeriod`.
2. 9.2 the remaining reports from `docs/specs/reporting.md`: stock valuation, dead stock,
   party aging, cheque calendar (the service exists — `ChequeCalendar`), instalments book,
   VAT summary, SMS usage, technician performance.
3. Print CSS + Excel export + saved filter presets.
4. 9.3 the query performance budget: <300ms on a 100k-row seed. Needs a bulk seeder, which
   does not exist yet — that is the first real piece of work, not the reports.

Decisions taken so far are batched in `docs/DECISIONS-FOR-REVIEW.md` for Gate 4.

---

## 2026-08-15 — Phase 9.1 done, and the sales report the dashboard links to

**1050 tests green** (was 1026). Larastan clean, Pint clean, RTL gate clean, `npm run
types` and `vite build` clean.

**Shipped**

- **The dashboard** (`/dashboard`, replacing the placeholder). Eight widgets, each
  composing the service that owns its number — `ProfitEngine`, `ChequeCalendar`,
  `CollectInstallment`, `StockOverview` — rather than re-deriving. A dashboard that
  re-derives disagrees with the screen it links to, and the shopkeeper finds out by
  clicking through.
- **Two gates per widget, and they are different questions.** The plan must include the
  module *and* the user must hold the permission. The Basic-plan test grants an Owner
  every permission there is and still expects no cheques card, which is the only way to
  prove the plan gate does anything.
- **`ReportAccess`** — one predicate for "may this person see what the shop paid",
  reading both `sales.view_profit` (the counter's version) and
  `reporting.view_financial` (the back office's). Asking one on the dashboard and the
  other in the viewer is how the same person sees margin on one screen and not the
  other, which reads as a bug in whichever they saw second.
- **`BarChart`** on the `/design` gallery first, per the UI workflow rule. One series,
  one colour, because the visual language has exactly one accent. A fixed readout above
  the plot instead of a floating tooltip: thirty 6px bars at 390px leave no room for one,
  and a tap works where a hover cannot.
- **Report index + sales report** (`/reporting`, `/reporting/sales`). Three cuts over one
  Jalali range — daily, by product, by salesperson — on an A4 print sheet that IS what
  prints, plus xlsx export with money in two columns (integer rial and the formatted
  string, through the same `Money::toArray()` the screen calls).

**The thing worth writing down**

`CrazyMonthSeeder` — the Phase 7 reconciliation scenario every golden number is pinned
against — **contains no sales invoices**. It seeds a chart of accounts, banking, overheads
and cheques, because what it was built to prove is that the ledger closes. So the two
sales assertions the previous session added to `GoldenNumbersTest` were comparing zero to
zero and passing. They now assert that emptiness explicitly (`invoice_count === 0`,
`daily === []`), which turns a silent gap into a failing test the day somebody adds a sale
— and points them at `SalesReportScreenTest`, which pins the sales arithmetic against a
fixture built for it: 290,000,000 revenue, 180,000,000 cost, 110,000,000 profit, with
every cut asserted to sum to the same revenue.

Found by strengthening a passing test rather than by a failure, which is the only way this
class of bug surfaces.

**Where the next session picks up**

1. The 100k-row bulk seeder — 9.3's latency budget cannot be written without it, and it is
   the gate on "measured" in 9.1 too.
2. The remaining reports, in the order the shop asks for them: stock valuation, dead
   stock, party aging, cheque calendar (`ChequeCalendar` exists), instalments book, VAT
   summary, SMS usage, technician performance.
3. Saved-filter presets (`saved_filters` in the spec — no table yet).

Decisions remain batched in `docs/DECISIONS-FOR-REVIEW.md` for Gate 4.

### Addendum, same day — the dark theme failed AA everywhere, and the browser walk found it

Walking the new screens at 390/1280 in both themes turned up a defect that predates this
phase and reaches the whole app. The palette defines `--color-success-on-dark`,
`--color-warning-on-dark`, `--color-danger-on-dark` and `--color-brand-on-dark` with
measured ratios beside them — but only `--primary` and the chart slots were ever wired to
them. So `text-warning`, `text-danger`, `text-success` and `text-brand` kept their **light**
values under `.dark`, and every status badge, stat card, timeline icon and inline link in
the product rendered below the AA floor:

| token | on `#1D1D1F` before | after |
|---|---|---|
| warning `#8A5A00` | 2.8:1 | `#E0A13A` — 7.4:1 |
| danger `#B3261E` | 2.6:1 | `#FF6961` — 5.9:1 |
| success `#0F7B3F` | 3.1:1 | `#4CC47F` — 7.5:1 |
| brand `#0066CC` | 3.0:1 | `#409CFF` — 6.5:1 |

Fixed by remapping the tokens in `.dark`, not by adding `dark:` variants at call sites —
which is the whole reason the system has tokens.

That immediately created its opposite, which is the part worth remembering: **the print
sheet is a light island.** `PrintLayout` renders every sheet as ink on white in *both*
themes, deliberately, so the lifted steps are the wrong ones inside it — `#4CC47F` is 7.5:1
on `#1D1D1F` and 2.2:1 on white, and the sales report's profit column went from readable to
nearly invisible the moment the shop switched to dark mode. Every semantic token is
therefore restored to its light step under `[data-paper]`, the attribute `PrintLayout`
already sets, so every existing and future sheet gets it without remembering to.

To keep one copy of each hex, the light steps are now named (`--color-*-on-light`) and both
`:root` and `[data-paper]` alias them. The `/design` swatch row prints both values, because
it is the one place in the app that shows a hex and a swatch labelled `#8A5A00` while
rendering `#E0A13A` is documentation that lies.

---

## 2026-08-15 (later) — the 100k-row fixture, and the index it found

Two captures first, both from the previous session, both written where the next session
will trip over them rather than in a chat log.

**"Green without witness"** is now a named pattern in `docs/testing.md` §3. A
golden-number test whose fixture does not contain the subject it measures compares zero
to zero and passes forever. `GoldenNumbersTest` pinned sales revenue and profit against
`CrazyMonthSeeder`, which holds no sales invoices at all — two assertions, green since
Phase 7, proving nothing. The rule now: assert the fixture contains the thing, or assert
the emptiness as the claim with a pointer to the test that does pin the arithmetic. Never
an exact figure against a fixture nobody checked, because the figure looks like evidence
and is decoration.

**The paper light island** is now written down in `docs/design-system.md` (§1 and hard
rule 12) and in the `hamyar-ui` skill: a print surface is ink on white in both themes,
so every semantic token restores to its light step inside `[data-paper]`. Dark-mode
`success` `#4CC47F` is 2.2:1 on white, which is how a paid stamp went invisible. Faking a
sheet with `bg-white text-black` is called out by name — it fixes the ground and leaves
the badges on their dark steps, which is the bug rather than the fix. The `/design`
gallery now carries the regression case: the same five badges outside a paper surface and
inside one, so a dark-mode look shows the two side by side.

### The bulk seeder

`BulkVolumeSeeder` fills a shop with a year of trading — 40,000 invoices, 100,000 invoice
lines, ~100,000 stock movements, ~75,000 ledger rows — in about eight seconds, set-based,
straight into the tables.

Deliberately **not** through the services, which is the opposite of `CrazyMonthSeeder`'s
discipline and for the opposite reason: that one exists to prove the ledger balances, this
one to prove a query plan holds, and 40,000 invoices through `FinaliseInvoice` would take
an hour to re-prove what Phase 5 already proved once. The consequence is written at the
top of the file in the bluntest terms available: **assert timings and row counts against
it, never money.** Every amount in it is arithmetic the seeder invented, so a report
pinned to it would be pinned to that invention — the same trap as above, one step worse,
because the table is full and the number still means nothing.

Three things the fixture had to learn:

**Two shops, the same size.** On a single-tenant table a sequential scan and an index scan
do identical work, so a budget measured there would pass with every index dropped. The
neighbour is filled to match, and the tenant predicate has to earn its place in the plan.

**ANALYZE after every step, not once at the end.** A bulk-loaded table has no statistics,
and autovacuum cannot help — the rows are uncommitted, and under `RefreshDatabase` they
never commit at all. Written with a single ANALYZE at the end, the sale-movement insert
joining 100,000 unanalysed lines to 40,000 unanalysed invoices was **still running after
seven minutes**, against 1.4 seconds once the two tables had statistics. It had looked
instant in development, on a dev database autovacuum had already been through.

**Foreign keys by array subscript, never by `offset … limit 1`.** The offset form reads
as the obvious way to say "some customer" and is quadratic — Postgres walks and discards
`offset` rows once per invoice.

### What it found

A thirty-day sales report was reading **75,200 index entries and 12,533 heap rows to keep
3,093**. Every report in `SalesReports` filters the same three things — this shop,
`status = 'final'`, a date range — and the schema had `(tenant_id, status)`, which stops
before the date, and `(tenant_id, branch_id, issued_at)`, which cannot be entered on
`issued_at` without a branch the consolidated view deliberately does not supply. So the
cost grew with everything the shop had ever sold rather than with the range somebody
asked for: invisible on eleven demo invoices, and a complaint from the biggest customer
eighteen months in.

Replaced with `(tenant_id, status, issued_at)`. The migration records what it is actually
worth — twelve milliseconds against fourteen on the same plan, both an order of magnitude
inside the budget — so nobody reads the two milliseconds as the claim. The claim is the
term that stops growing.

### The budget

`ReportLatencyTest`: sixteen measurements across a month range and a year range, **1–46ms
against 300ms**, with the fixture's row counts asserted before a single clock starts —
because a latency test against an empty table is the purest form of green without
witness. The docblock says plainly what the test is not: a ceiling, not a regression
detector. At 6× headroom a change making a report three times slower still passes, and
tightening the number would buy a test that fails on a busy CI box and teaches everyone
to re-run it. The detector for a plan change is the fixture plus `EXPLAIN`, which is how
the index above was found.

The roadmap line stays `[~]`. A budget over the four reports that exist is not the budget
the spec names over ten; each new 9.2 report is one more line in the timing map.

### Same day — sales monthly and by brand, and a day that belonged to the wrong day

Both land as **cuts of the existing sales report**, not as new screens. The controller's
own docblock already argued for it — three cuts are one query grouped three ways, and
three routes would mean three filter bars that drift — so monthly and brand make five,
sharing the range, the print sheet and the export.

**Monthly is folded in PHP, and that is not a shortcut.** Postgres has no Jalali
calendar, so `date_trunc('month', …)` groups by the Gregorian month — which straddles two
Jalali ones, making «فروش مرداد» part Tir and part Mordad. That is not a wrong total so
much as an answer to a question nobody asked. The daily rows are folded by
`Jalali::monthKey()`, the same key the recurring-expense generator books against; a year
is at most 366 rows, so the fold costs nothing and the calendar is right by construction.
Rows come back chronological rather than biggest-first, because the point of a
month-per-row table is the shape of the year.

**Writing it exposed a real defect in the daily report.** It bucketed on
`date(issued_at)`, and `issued_at` is stored UTC. A sale at 00:30 Tehran is 21:00 UTC the
day before, so it landed on yesterday's row — and eleven times a year on the previous
*month's* row, where the new monthly report would have filed it under the wrong month
entirely. Now the timestamp is shifted into the shop's wall clock before it is truncated,
with a test that moves an invoice to 00:30 Tehran and asserts it stays on today.

Two things fought back while fixing it, both worth writing down:

- **A bound placeholder cannot carry the timezone.** Postgres compares `GROUP BY` against
  `SELECT` *by expression*, and `$1` in the select list is not the same expression as `$5`
  in the group-by even when both hold `Asia/Tehran`. The failure is
  «column sales_invoices.issued_at must appear in the GROUP BY clause», which reads like a
  query-shape bug and is a binding one. Grouping by **ordinal** (`GROUP BY 1`) fixes that
  half and is better than the repeated expression anyway — `1` *is* the first select
  column, so the two cannot drift apart.
- The timezone itself is inlined from config, stripped to the characters an IANA zone name
  can contain and defaulting to UTC if that leaves nothing. Safe because it is config, and
  *proved* safe rather than argued safe.

Both errors surfaced three frames away as «current transaction is aborted … SQL: select
set_config(app.tenant_id …)» — the tenancy teardown failing on an already-poisoned
transaction. Same shape as the harness-versus-domain lesson in `docs/testing.md`: the
message named tenancy and the fault was in a `GROUP BY`.

**By brand** keeps the lines that have no brand — a service, or a handset sold off its own
unit record — under one unnamed row. Dropping them would make the brand cut disagree with
every other cut of the same range, which is the one thing a set of cuts must not do; the
«sums to the same revenue» invariant now runs across all five.

The report index lists five sales reports, the latency map takes sixteen measurements,
and `composer test` is green.

### Same day — the profit report

`/reporting/profit`, three cuts: by product, by brand, and per IMEI. Its own screen rather
than a sort order on the sales report, and the reason is the `LIMIT`.

**Ordering by margin has to happen in SQL, before the limit.** The sales report's top
fifty is chosen *by revenue*; re-sorting that set in PHP answers "the fifty best sellers,
arranged by profit" to somebody who asked for the fifty most profitable — plausibly, and
wrongly, and most visibly on exactly the low-volume high-margin lines the report exists to
surface. So `SalesReports::grouped()` now takes the order, and `ProfitReports` asks for
margin.

The test pins it as an **ordering**, not as three independent figures. The fixture is
built so the two questions disagree: a handset sold for 400,000,000 having cost
380,000,000 is the largest revenue line in the shop and the smallest margin. A profit
report that sorted by revenue would put it first and look entirely reasonable; this one
puts it last.

**Per-IMEI is the cut only this product can offer.** Every handset carries its own cost on
`product_units` and its own `cost_snapshot` on the line that sold it, so the margin on a
single device is exact — not an average, not an allocation. It reads the **line's**
snapshot and never `product_units.cost`: that column is what the device is worth today and
is updated by a re-grade, so reading it would restate a past month's profit every time
somebody touched a unit record, which is the precise failure `cost_snapshot` exists to
prevent.

**The screen is refused, not stripped.** The sales report drops the cost columns for a
viewer without margin and still shows them the takings, which answers a real question.
There is no equivalent here — a profit report with the profit removed is an empty table
under a heading that promises otherwise — so a Cashier gets a 403 on both doors. The
report index hides the three rows using the *same* predicate, `ReportAccess`, because a
listed row that 403s when clicked tells the reader the product is broken rather than that
the figure is not theirs to see. Both halves are asserted.

The latency map takes sixteen measurements. `profit.perUnit` is deliberately **not** among
them: `BulkVolumeSeeder` writes no `product_units`, so the query would return nothing and
time the speed of an empty table — green without witness, in the file that argues hardest
against it. It goes in when the fixture grows serialized handsets.

### Same day — technician performance

`/reporting/technicians`: delivered count, jobs on the bench today, average turnaround,
parts cost. Four decisions in it are worth keeping, because each has a plausible
alternative that produces a number nobody can act on.

**Delivered, not worked-on.** A ticket counts in the period it was *finished* in. Counting
intake would credit work to the month a device arrived, so one brought in on the 29th of
Mordad and repaired in Shahrivar makes Mordad look busy and Shahrivar idle — and neither
month describes what anybody did.

**Turnaround is intake→delivery wall-clock**, not time-in-repairing. The second flatters
every technician by excluding the days a device sat waiting for a part or for the customer
to answer the phone. The customer experienced the whole wait, and the whole wait is what a
promised date has to be set against. Where the shop wants to know *why* a job was slow the
status history has it ticket by ticket; an average that quietly excluded waiting would
hide that there is a question.

**«روی میز» deliberately ignores the range.** Open work has no date to be inside: a ticket
from two months ago that is still open is open today, and excluding it because it fell
outside the range would report an empty bench for the technician who most needs help. The
column header and the footer both say so, rather than leaving the reader to wonder why two
columns do not add up. It uses `TicketStatus::isOpenWork()` — the same predicate the Kanban
board uses — so «۴ روی میز» here and four cards there are the same four.

**Parts cost is the shop's cost, not the customer's price.** `ticket_parts` carries both;
the figure beside a technician's name should not move when somebody edits a price list.
Consumed only — a reservation is not a cost, and a technician who planned a repair that
was cancelled has spent nothing.

The margin gate takes the sales report's shape here rather than the profit report's: the
money column is dropped and the screen still works, because «چند دستگاه تحویل شد و چقدر
طول کشید» stands on its own.

### Same day — stock valuation, dead stock, and a rounding guard that was waiting

`/reporting/inventory`, two cuts. One date rather than a range, because «موجودی» is a
figure at an instant — and the as-of date works at all only because on-hand is a SUM over
movements rather than a stored total.

**The valuation had a wrong answer waiting for it.** This product keeps stock in two
registers on purpose: standard goods are a SUM over `stock_movements`, and handsets are
rows in `product_units` with **no** movement written for them (Phase 3.6 — a phone counted
in both is counted twice). So a valuation that reads movements alone values a mobile-phone
shop's phones at zero. The fixture is built to make that loud: devices are 760,000,000 of
a 1,526,666,700 total, and a movements-only report would say 766,666,700 and look entirely
plausible. Both the split and the total are asserted.

**Dead stock is dated from the last outward movement**, not from arrival. A line restocked
last week that has not sold since Farvardin is dead stock with fresh purchase dates all
over it. Handsets get one row each, because a shop discounts *this* phone.

### The find: a derived cost that could not be displayed

Writing the valuation's fixture with realistic numbers — 10 batteries at 50,000,000 and 2
at 90,000,000 — hit an exception from `Money`:

> Amount 56666666 rial is not a whole number of toman; refusing to round money.

`Money::toToman()` refuses a sub-toman remainder by design, on the stated grounds that any
such amount is "a bug upstream". That is true of every amount a person types and of every
price, tax and instalment split — and **not** true of a derived unit cost, which is a
division. `StockLedger::weightedAverageCost()` has been returning such figures since Phase
3; `FinaliseInvoice` writes them into `cost_snapshot`; and the sales report renders
`cost_snapshot × quantity` through `Money::toArray()`. **The sales report would throw on
any shop whose average cost did not land on a whole toman**, which is most of them. The
suite never saw it because every fixture bought stock at round numbers — the exact gap
`docs/testing.md` had already predicted in writing: *"a fixture that buys stock at exactly
200,000 never meets the rounding guard that real weighted-average cost trips on the first
search."*

Fixed at the source rather than at the display: `Money::ceilToToman()` raises a derived
cost to the next whole toman, and `StockLedger` applies it. **Upward**, so an understated
cost never flatters the margin — the mirror of `Money::percent()`, which truncates so the
shop never over-charges. The adjustment is at most nine rial on a unit cost.

A second, quieter trap sat inside the set-based version of the same average. Postgres
returns **numeric** from `sum()` over a bigint column, so `sum(a) / sum(b)` is exact
decimal division rather than the truncating integer division the expression was written
as — and the ceiling-to-ten that followed then operated on a fraction and produced one.
It surfaced as a valuation of 56,666,675 rial: not a whole toman, refused by `Money`, from
a screen whose PHP path had computed the same figure correctly moments earlier. Explicit
`::bigint` casts, and a comment saying why they are load-bearing.

Two pinned figures moved with the fix — `FinaliseInvoiceTest`'s 53,636 to 53,640 — and
`Money::ceilToToman` gained its own unit tests, including the property the whole helper
exists for: over all ten residues, its output is something `toToman()` will accept.

### 2026-08-15 — the six remaining reports, saved presets, and the budget over all of them

Phase 9.2's open boxes and 9.3's: party balances aging, the cheque calendar, the instalment
book, the VAT summary, SMS usage, saved-filter presets — and the latency budget widened from
four reports to every report the catalogue lists.

**Aging is FIFO, and the rule is the report.** `ledger_entries` records debits and credits
against a party; it does not record *which debt a payment settled*, because nothing at the
counter asks. So an aging report has to choose, and the two simpler choices are both wrong in
ways that look plausible on screen. Bucketing the debits and ignoring the credits reports
every invoice ever issued as outstanding — a customer with a spotless twelve-year history
becomes the shop's worst debt. Putting the whole balance in the bucket of the oldest unpaid
entry lets one ancient rounding remnant drag a current balance into «۹۰+», and a report that
points everywhere points nowhere.

Oldest-first is the standard rule and it runs **in SQL**, not in PHP: with credits applied to
the oldest debts, the credit reaching a given lot is whatever is left after the lots before
it, so `remaining = clamp(cumulative − settled, 0, lot)` is exact FIFO as one window
function. The loop it replaces would drag 75,000 ledger rows into PHP to produce forty.

`opening_balance` joins the entry stream as a **signed pseudo-entry** dated `parties.created_at`,
and that detail is what makes the whole thing checkable: **outstanding − unapplied credit =
the sum of `partyBalance()`, exactly**. Asserted rather than trusted, in the screen test and
again in `GoldenNumbersTest` against the crazy month.

**Two decisions carried over from earlier screens, on purpose.** Cleared cheques are reported
but stay out of the calendar's net — that money arrived, and counting it again promises cash
the shop already spent. Overdue-and-still-open cheques are shown **outside the range**, the
same call the technician report makes about «روی میز»: a cheque that failed in Mordad has no
future date to sit inside Shahrivar's calendar, and surfacing it only when somebody scrolls
back is how it is forgotten.

**The VAT summary reproduces the invoices; it does not recompute them.** That rule is now
written into ADR 0009 as an amendment rather than into a docblock, because the invoice side
has to honour the same one. Re-deriving VAT from a period's revenue at today's rate rounds
once over a month instead of once per line, applies a rate the invoice may not have been
issued under, and on the two-line fixture lands **eighteen rial** away from the invoices — in
the shop's favour, which is the direction a tax authority notices.

### The captures, and the one that paid immediately

Two from the last session, both written down before this session's code:

`docs/testing.md` gained **"money fixtures use non-round amounts by default"** under
green-without-witness, naming the `weightedAverageCost` case. The fixtures here were then
built to it — the aging payment (7,430,000) lands in the *middle* of the second invoice so
FIFO has to split a lot, and every taxable line is priced at 8,881,990 so the per-line VAT
floor has something to floor.

ADR 0009 gained an **Amendment** stating the direction rule once for the whole family:
*every rounding of a derived figure goes in the direction that does not flatter the party
doing the rounding.* That single sentence produces every direction already in the codebase —
VAT floors, `percent()` truncates, `ceilToToman()` ceils — and the asymmetry stops looking
like an inconsistency. It also binds the VAT report, which is why that report reads stored
figures.

### Closing 9.3 honestly meant growing the fixture, not widening the claim

The budget covered four reports because `BulkVolumeSeeder` held only invoices, items,
movements and ledger rows. Timing a cheque calendar against an empty `cheques` table measures
`select … where false` and passes by a factor of a thousand — the exact trap this suite argues
hardest against. So the seeder grew handsets, cheques, instalment plans/rows/collections and
messages, and with them the cuts the latency test had **explicitly deferred in writing**
(`profit.per_imei`, both inventory cuts) became measurable and are measured.

Doing that surfaced a second fixture defect. The seeded ledger wrote **debits only**, so
`settled` was always zero, the FIFO clamp collapsed to `lot` on every row, and the expensive
branch never ran — the payable direction was reading an empty set at full speed. The first
measurement said so out loud: 84.8ms receivable against 20.5ms payable, a gap that was the
fixture rather than the query. Part-payments against every third invoice fixed it.

**26 measurements, 1–93ms against the 300ms budget.** One report is still not measured and is
named rather than quietly omitted: `repairs.technicians`, which needs repair tickets in the
seeder and goes in with them.

### Smaller things worth having written down

`ReportAccess` grew from one predicate to seven, and `ReportCatalogue` rows now carry a
**named gate** resolved in one `match`. The point is unchanged and now enforced structurally:
the index and the screen ask the same question, so a listed row can always be opened. The
financial screen gates **per cut** for the same reason — a viewer allowed one cut sees only
that cut's tab, because offering a tab that 403s is the same defect one screen further in.

A preset **grants nothing**. Applying one is a `router.get`, so the URL stays shareable and
the screen gates itself exactly as it does for a typed URL — pinned by a test where a Cashier
saves a preset for the tax screen and still gets a 403 opening it. That is what lets `filters`
be opaque JSON. They are keyed by *screen*, not by catalogue row: `sales.daily` and
`sales.by_brand` are one screen with a `cut` filter, and keying by row would give one saved
range three entries that each restore a different tab.

`ShopClock` now owns the stored-UTC-to-Tehran-day expression that four new reports needed.
The Mordad-month bug this repo already fixed once is one `date()` call away in every report
that groups or ages by date, so it lives in one place and each report asks for it.

## 2026-08-18 — Phase 10 less Data tools (#17)

**10.1's finding was not a missing filter.** `branch_user` had enforcement, readers and
tests since Phase 2, and **no writer** — no branch screen, no assignment control, no
switcher. So every user was unrestricted, and the five modules with no branch filter at all
were indistinguishable from the three that had one. The gap could not be observed until the
feature became reachable, which is the same shape as a guard that silently passes; it is now
named in `docs/testing.md` as *a feature with enforcement but no write path is invisible*,
with the grep that finds it.

`BranchContext` keeps the two questions apart — **access is a floor that always applies,
the switcher is a lens on top**. Conflating them makes «همه شعب» a privilege-escalation
button, and that is the test the class exists for. The reports had no branch filter either,
and `?int $branchId` could not express "the two branches this regional manager is allowed",
so it widened to `list<int>|null` through `ProfitEngine`. `DailyCloseReport` and
`ProfitAndLoss` kept their `?int` and wrap at the call: a close is one till.

**The same pattern appeared again in the very next module.** `product_units.hamta_status`
shipped in Phase 3 and nothing ever set it — every device in every shop read
`not_required`, used ones included, for seven phases. HAMTA's two listeners are the first
writers; a new `UnitAcquired` event covers every door a device comes in through so a fourth
acquisition path cannot be forgotten.

**Gate 4 closed both ways.** Storefront to a fixed scope (no cart, no checkout, no
accounts). Moadian ships as an adapter with **no real provider** — ADR 0011 — because these
customers are mostly on presumptive taxation and choosing an intermediary before one has
been asked for buys an integration the first real request is likely to contradict. What
ships is the part that is expensive to retrofit: the contract, the pure mapping, the queue,
the inbox, the idempotent resend. Flag off for every plan, «به‌زودی» in plan copy.

The reseller price-list link is treated as the bearer credential it is: hashed at rest,
shown once, expiry NOT NULL with a ceiling. `price_list_links` is one of very few tables to
opt into `allowPlatform`, and the reason is narrow — a visitor holding a token has no tenant
to be scoped by, so resolution is one indexed lookup under `runAsPlatform()` and then it
enters that link's tenant.

**Three rules earned this session**, all of the same family — nothing crashes, the wrong
thing silently wins:

- The 23505 rule has a sharper edge: the `try` goes **outside** `DB::transaction()`. A
  closure that catches its own unique violation never triggers the savepoint rollback, so
  the recovery query dies with the very 25P02 the wrapper was added to prevent.
- A `function_exists`-guarded global helper must not take a name a dependency also defines.
  `jdate()` had been dead for eight phases because morilog/jalali defines one too.
- `BranchAccess` was never a singleton, so its memo was per-instance and every `forget()`
  was a no-op — including the one the new assignment screen calls.

**Process:** the 10.1 commit went to `main` directly, breaking the repo's own rule. Left in
place (no history rewrite) and hardened as far as the platform allows: rulesets and branch
protection are **Pro-gated for private repos**, so `.githooks/pre-push` prevents it locally
and a `guard-main` workflow raises a red build if it happens anyway. CLAUDE.md states
exactly what is and is not enforced, because a rule everybody believes is mechanical is one
nobody checks.

**Not built: 10.5 Data tools** — tenant export, products import, backup button, audit-log
viewer. Skipped per the session's instruction, not by judgement.

---

## 2026-08-18 (j1405-05-27) · Phase 11b · Checkpoint 2 — and a ten-fold error found on the way

**Checkpoint 2 cleared.** Variant representation: one import row becomes one product and
one `options: []` variant; grouping is opt-in, never inferred from a product name;
matching is barcode → SKU. [ADR 0013](adr/0013-flat-product-import.md).

The ruling rationale is the **reversibility asymmetry**, and it is worth keeping as a
general test for any guess an importer makes. Flat-and-wrong — two colours of one phone
becoming two products — costs an afternoon of tidying, and everything sells and stocks
correctly meanwhile. Grouped-wrong is **permanent**: once stock movements and invoice
lines reference those variants, splitting them is not an operation this system has, and
`VariantMatrix` deliberately never deletes a variant with history. When one side of a
guess is recoverable and the other is not, do not guess.

**Stock is out of scope for 11b, and says so on screen.** The «موجودی» column appears in
the mapping list greyed and labelled «وارد نمی‌شود», with a pointer to the correct path.
Golden rule 3 is the reason — an opening quantity is a `stock_movements` row needing a
warehouse and a unit cost the file does not carry — but the label exists because silence
reads as a bug and a label reads as a decision.

**The format claims were probed before anything was designed, and half of them were
wrong.** `.xlsx`, legacy `.xls` (BIFF8) and UTF-8 CSV already read fine — the last of
those needed no work at all. windows-1256 CSV does not: it reads as mojibake and the
header row comes back *empty*, so the mapping screen would offer no columns to select.

**windows-1256 cannot represent Persian, and that changes what ی/ک normalisation is
for.** The code page has no Persian yeh, no Persian digits and no Persian thousands
mark — it does have Persian kaf. So a legacy file physically cannot contain «گوشی»; it
contains «گوشي», every time. Normalising ی and ک is **code-page repair, not tidying**,
and without it every name imported from old software fails to match what the shop later
types into search. Two corollaries: the Persian-kaf-with-Arabic-yeh mix is a fingerprint
for detecting cp1256 rather than asking the operator, and digit normalisation targets
`.xlsx` rather than the legacy format, because a cp1256 file has Latin digits by
construction. Recorded in [the catalog spec](specs/catalog.md#products-import-phase-11b).

**A live 10×/100× money error, found by probing rather than by a test.** `PartyImporter`
normalised money by stripping every non-digit and casting to `int`. An Iranian sheet
writes a decimal with a **slash**, so `12500000/0` toman was imported as 1,250,000,000
rial — ten times the balance — and `12500000.00` a hundred times it. Nothing threw,
nothing logged; the customer simply owed ten times what they owed and the ledger built on
it. Fixed in this branch rather than filed, because it was live against opening balances
and credit limits.

`Money::parse()` now reads all three decimal marks in circulation (`/`, `٫`, `.`) with
exact integer arithmetic — a numerator over a power-of-ten scale, divided once, only
after the division is proven exact — and **refuses** any amount that does not land on a
whole rial rather than rounding it. `1250/5` toman is a legitimate 12,505 rial and
survives; `1250/55` is 12,505.5 and throws. Two decimal marks in one value are ambiguous
between grouping and fraction and are rejected rather than guessed. An unreadable cell is
now a **row error, never a zero** — importing zero for a balance nobody could parse is
the same failure one step later, in the last place anyone looks.

A cell stating its own currency word is tolerated when it agrees with the chosen unit and
**rejected when it contradicts it**, because that disagreement is the operator having
picked the wrong unit for the whole file — worth ten times every amount in it.

**Remediation: none needed, and the reason is structural.** No staging or production
environment exists yet (Phase 11 DoD still has the staging deploy unchecked), and the dev
database holds zero parties. Nothing was ever imported through the broken path. Reported
rather than assumed, and no balances were rewritten.

Verified by planting the old parser back and watching the suite go red with
`Failed asserting that 1250000000 is identical to 125000000` — a regression test written
against a bug you cannot re-introduce on demand has not been shown to test anything.

**Not built yet:** the products import itself. Checkpoint 2 was the gate; the template,
mapping screen, dry run and windows-1256 reader are the next session's work.

---

## 2026-08-18 (j1405-05-27) · Phase 11b · Products import — built, and what the walk found

Template · mapping screen · dry run · commit-on-confirm, at `/catalog/import`, reachable
from «ورود گروهی» on the products list behind a new `catalog.import` permission. Owner and
Manager have it; Warehousekeeper does not, because one click writes the whole catalogue
and, on re-import, a new price for every matched row — the reach of `create` and
`managePrices` together.

**One row is one product and one `options: []` variant** ([ADR 0013](adr/0013-flat-product-import.md)).
Matching is barcode → SKU; a row with neither says so **on its own row**, because "importing
this file twice will duplicate these" is not something a shopkeeper can infer.

**The currency unit has no default and blocks the step** — unpicked on screen, `required`
in the request. The customer wizard reads the tenant's display preference; this one refuses
to, because a price column is toman most of the time and rial the rest and guessing wrong
is ten-fold across the catalogue.

**«موجودی» is shown, greyed, and refused**, with a pointer to فاکتور خرید / انبارگردانی.
Golden rule 3 is the reason; the label exists because silence reads as a bug.

**Encoding is detected, not asked about, and the repair is announced.** `Encoding` decides
from UTF-8 validity — a shopkeeper cannot answer "which code page is your export?" and a
wrong answer corrupts the catalogue silently. The file chip then says «این فایل با کدپیج
قدیمی ذخیره شده و اصلاح شد» and «حرف‌های ی و ک استاندارد شد», with the sample rows as the
evidence. `ProductImportReadPathsTest` writes one catalogue four ways (.xlsx, .xls,
CSV-UTF8, CSV-cp1256) and asserts all four read back identically. The fixture is
constrained by what cp1256 can physically hold, and that constraint **is** the proof the
ی/ک repair is structural rather than cosmetic.

One design bug fixed en route: `repaired_text` was asked of the reader's output, which has
already been repaired — so it reported "nothing changed" on precisely the files that
changed most. It is asked of the raw bytes now.

**The browser walk earned its place: three defects, with all sixteen feature tests green.**

- `<Money value={…}>` — the component's prop is `rial`. The payload was valid JSON;
  only rendering it threw.
- Verdict messages overflowed their cell and were clipped — losing exactly the half that
  says what to do about the error.
- **A ragged CSV row silently imported a price of `18`.** An unquoted `18,900,000` in a
  comma-delimited file splits into three cells, every column after it shifts, and the row
  imports as a phone costing eighteen toman. No error, no empty cell, a plausible number in
  the right column. Now refused with both field counts named.

The third is the lesson: **a fixture built in PHP cannot express the malformation you are
trying to survive**, because the test writes it field by field and every file it produces
is well-formed. Written up in `docs/testing.md`.

**Two rules ratified into the import spec** beside parse-never-strip: an unreadable cell is
a row error never a zero (a zero *price* is a real price — it goes out the door), and a
contradicting currency word is a file-level unit error rather than noise to strip.

---

## 1405/05/27 (2026-08-18) — Phase 11c, the audit-log viewer

The roadmap called this "a read-only UI over data `spatie/activitylog` has collected
since Phase 2." Checking that was the first thing 11c did, and it was false. Across
eighteen modules exactly one model carried `LogsActivity` — `Identity\User`, four
attributes — beside two hand-written call sites. The development database's entire audit
trail was **two rows, both «user created»**.

So «کی این قیمت را عوض کرد؟», the question the viewer exists to answer, had no row to
find. Filters over that table would have worked perfectly and answered nothing, and the
checkbox would have said otherwise. Coverage first, screen second.

**What earns an entry** is now a rule rather than a habit: a change is audited when an
owner would ask who made it *and* no other table already answers. Product, variant,
price level and party carry `Auditable`; ledger-shaped tables (`stock_movements`,
`ticket_status_histories`, …) deliberately do not, because they are already the record
and mirroring them would duplicate the highest-volume writes in the product to say
nothing new. Price changes are logged by hand **against the variant**, with the amount
before and after, because `product_prices` is append-only — what and when were already
there, only *who* was missing.

**The log masks what the model masks.** The secret list is derived from each model's own
`$hidden` and `encrypted` casts rather than maintained beside the audit code, so a new
secret is covered by the declaration that already protects it everywhere else. Two things
fell out of building it: spatie v5 writes the model diff to **`attribute_changes`**, not
`properties` — so guarding `properties` alone, which is what the column names suggest,
masks nothing at all for audited models — and `RepairTicket`'s `tracking_token` and
`approval_token`, both bearer credentials, were declared sensitive nowhere.

**The expensive find came from measuring, not reading.** `activity_log`'s null-tolerant
RLS policy used `IS NOT DISTINCT FROM`, and **no btree can serve that operator**. An RLS
predicate is ANDed into every query, so every index on the table was dead the moment it
was created — including the two added for this viewer. Nothing errored; the log just
scanned the whole platform to answer a question about one shop, a little slower with
every shop that signed up. At a seeded 1.8M rows the default view was a 55.8ms parallel
sequential scan. Fixed in `EnablesRowLevelSecurity` (an indexable OR) plus a
planner-visible tenant scope on `Activity`, and the record-history hot path — the one
the «تاریخچه» link uses — went to a 0.074ms index scan. The point is not the
milliseconds: it is that no query's cost grows with the number of shops any more.

**Read-only is now a tested property.** `ActivityLogRoutesTest` fails if any route
reaches the controller with a mutating verb, if the controller grows a public action
beyond `index`, or if the policy grows an ability beyond `viewAny`.

**The browser walk found six defects with every feature test green**, and one of them was
the feature: a product's «تاریخچه» contained no price changes at all, because prices are
logged against the variant. ADR 0013 makes one-product-one-variant the shape of every
imported row, so the link built to answer «کی این قیمت را عوض کرد؟» opened a page with
every kind of change except that one. Subjects can now declare related records, and the
owning module supplies the relationship so the controller still does not know what a
variant is. The other five: `created` events printing `— ← —` for every unset field
(seven of ten lines on one entry said nothing), a struck-through em dash standing in for
"not set", money rendered without its unit in a log where the amount is the point, Latin
digits in Persian prose, and empty query parameters making a shared audit link unreadable.

Two things noted and deliberately not built: **retention** — `clean_after_days` is 365
and `activitylog:clean` is not scheduled, and how long a shop's audit trail must survive
is a legal question — and dropping the policy's null-tolerance entirely, which would buy
the last ordered-scan plan but decides whether a shop should see the platform's actions
on it. Both belong in 11d or later. Also corrected: `docs/specs/identity.md` still said
the tenant context was pinned with `SET LOCAL`, which golden rule 1 exists to say it is
not.

Recorded as [ADR 0014](adr/0014-audit-surface-and-log-isolation.md); the index lesson is
in `docs/testing.md`, because "a predicate the planner cannot use is an index that does
not exist" is not specific to this table.

**Accepted, with three decisions recorded.** The dead-index find went into
[ADR 0002](adr/0002-single-db-tenancy-rls.md) as its third amendment, where the policy
lives rather than where it was discovered: *an RLS predicate is ANDed into every query,
so a policy operator no btree can serve kills every index on the table, silently.* Same
family as the `bindIf` and `function_exists` traps — nothing crashes, the wrong thing
just wins.

**Null-tolerance is kept.** The last ordered-scan plan needs a policy with no OR, which
means a platform action on a shop would have to live somewhere the shop cannot see.
Declined: an impersonation is the event a shop most needs to read, and
`ImpersonationService` has written it into the tenant's own log since Phase 2.4. Now
asserted, so the property cannot invert without a red test.

**And the flake it exposed was worth more than the feature.** CI failed on
`ReportLatencyTest` — 308.6ms against a 300ms budget — and the same test had failed on
`main` earlier the same day, before this branch existed. All 26 measurements were shifted
three-fold; locally the run was 23–24s against 63s on the runner, and `main` measured the
same as the branch. Not a regression: the assertion was measuring the wrong machine. 300ms
is a promise about the hardware a shop runs on. Two failures in twenty runs is a
ten-percent false-positive rate, and this repo already wrote down what happens next —
nobody deletes a noisy gate, they comment out the CI step "just for this PR". The budget
now scales to the machine against a fixed reference workload, one-directionally so a fast
box never earns a tighter ceiling than the spec promises, and the failure message names
the scale so a reader can tell a broken promise from a busy runner. Verified by planting
a regression, because a gate that has only printed green has not been shown to have eyes.

---

## 1405/05/27 (2026-08-18) — Phase 11d pass 1: security (11.1)

Audited before building. The pattern from 11c held: four of the eight items were already
satisfied and needed *verifying and writing down*, not implementing — the rate limits
were all in place, and login was throttled per credential **and** IP rather than per
route, which is the harder and better version. Ticking those without checking would have
been the same error 11c's premise made.

`docs/security.md` is an ASVS-L1 audit where every line is either verified with the file
or test that proves it, or named as a gap with its cost. Gaps are named, including one
this pass declined to close unilaterally: `shadcn`, a scaffolding CLI, sits in
`dependencies` rather than `devDependencies`, which is what puts `postcss` and its
`nanoid` advisory in the **production** tree. Moving it alters the dependency manifest,
so it is proposed rather than taken.

**Three real holes closed.**

*Security headers and a CSP* existed nowhere. The policy is written against what this
application actually does rather than copied: nonce-based `script-src` with no
`'unsafe-inline'`, and one honest relaxation — `style-src` — because seven components set
a computed style attribute and a policy without it flattens every chart and mis-sizes
every printed label sheet. The middleware is registered **globally rather than in the
`web` group**: group middleware runs only after a route matches, so a 404 came back
unprotected. A test asking for a page that does not exist found that; every real screen
looked correctly protected.

*Dependency audits* now run in CI, and found a live high-severity advisory on the day
they were added.

*The encrypted-columns inventory* is a test rather than a list, on the same principle as
11c's redactor: it asks the models. The invariant is that an `encrypted` attribute must
also be `$hidden` — the cast decrypts on access, so one that is not hidden reaches every
`toArray()`, JSON response and log line in plaintext, encryption doing its job while the
value ships anyway. Verified by planting a leak in `MoadianSetting`.

**And one near-miss worth more than the finding.** Checking the claim "React escapes
everything" turned up three `dangerouslySetInnerHTML` sites, one rendering a barcode from
an operator-supplied string the products import can set in bulk. A quick probe reported
the payload was echoed — and it was wrong. The check searched the SVG for the substring
`script` and matched `&lt;script&gt;` inside a correctly escaped `<desc>`. Parsing the
output instead showed zero script nodes and zero event handlers for every hostile input.
**A test that looks for dangerous-looking text finds escaped text**; the question is
whether a parser sees a script node, so `InlineSvgIsInertTest` asks a parser. The
vulnerability was never there, and the test that proves it now is.

Still open in 11d: 11.1b browser testing in CI, 11.2 performance, 11.3 ops — including
the restore drill, which the phase preamble calls the largest unhardened thing in the
project.

---

## 1405/05/27 (2026-08-18) — Phase 11d pass 2: browser testing (11.1b, partial)

Node and Chromium now live in the dev image and a `Browser smoke (Chromium)` job runs
beside the others. The dev-image half was a deliberate call rather than the cheap option:
11.1b exists to replace source-level guards with rendered ones, and a rendered assertion
that can only be run by pushing to CI is one nobody iterates on.

**Four things had to line up, and three of them fail silently** — which is the whole
character of this task:

- The shop is given `127.0.0.1` as its own `domains.hostname`. Pest's server binds there,
  every screen in this product resolves from the hostname, and a request arriving as a
  host belonging to no tenant is a 404 by design. The plugin's `withHost()` looks like the
  answer and is not: it moves where the server listens, not the Host header the page is
  fetched with.
- `Tests\BrowserTestCase` leaves `@vite` alone. The base `TestCase` stubs it out, which is
  right for feature tests and fatal here — the page arrives with its Inertia payload and
  no script tag to consume it. Blank body, no JavaScript error, and a failure message
  about text not being visible that explains nothing.
- **The measurement waits for React to mount.** This is the one worth remembering. The
  first working version polled nothing: `script()` runs at the load event, React mounts a
  beat later, so it measured an empty `<div id="app">`, found scrollWidth equal to
  clientWidth on every page at every width, and passed all eight cases. It also passed
  with a 2000px-wide element planted in the page, and with sixty repetitions of an
  unbreakable token. **Green without witness, on the suite written to stop exactly that** —
  and it was only caught because the planted-regression check is a habit here rather than
  a formality.
- `tests/Browser` sits outside every declared testsuite, so a default `pest` on a machine
  that has not run `npm run build` does not fail.

**It found a real defect on its first honest run.** `AppShell`'s action row was
`flex items-center gap-2` with no wrapping, so the products list put 553px of buttons into
a 375px viewport and pushed the whole page sideways. One `flex-wrap`, in the one place
every screen inherits it. That screen had been walked by hand several times.

Still open in 11.1b: converting `InvoicePrintLayoutTest` from its source assertion to the
rendered one its own docblock asks for — the task that owns the collided-money-columns
defect — and extending the smoke suite to dark mode and the print layouts.

---

## 1405/05/27 (2026-08-18) — 11.1b, the print-layout conversion that refused to be one

The roadmap asked for `InvoicePrintLayoutTest` to be **replaced** by a rendered
assertion. The rendered assertion exists now — it sells the seeded handset at the
collision's own figure under the long Persian name, and measures every cell on A4 and A5
for both overlap and content overflow. What it could not do is replace the old one.

**Removing `table-fixed` — the exact regression the source test guards — leaves the
rendered test green.** At A4 the browser balances an `auto` layout into 703px of a 794px
sheet with room to spare, every figure fits its column, nothing overlaps; and thermal80
does not use a table at all. The historical squeeze does not reproduce in a screen-width
preview of A4, so the rendered test has not been shown to catch what the source test
catches.

Deleting the source test on the strength of "we have a real one now" would have removed a
working guard and left a green suite over the defect it was written for. Both are kept,
each covering the half the other cannot see, and the old docblock — which promised its own
deletion — now records why that promise was not kept.

Two fixture facts worth keeping, both found by being refused:

- The price has to land on a whole toman. `896,636,798` rial was rejected by
  `Money::inUnit()`, correctly and loudly (golden rule 2). The collision's `96,636,798` is
  **toman**; in rial it ends in a zero like every other price in this product.
- A VAT total the cash payment does not cover is a credit sale, and a credit sale without
  a party is refused by the POS. The fixture gained a customer rather than losing its VAT.

The generalisable bit: **"replace the weaker test" is a hypothesis, not a plan.** It holds
only if the stronger test fails where the weaker one does, and that is a thing to check by
planting the regression — not to assume because the new test is more sophisticated.

---

## 1405/05/27 (2026-08-18) — 11.2 begins: a platform-sized fixture

`php artisan platform:seed-volume` builds a launch-sized database: N shops, each with a
real trading year behind it. `BulkVolumeSeeder` already made one convincing shop; this
makes the **platform**, and the distinction is the whole reason the item exists.

A query's cost stops being about one shop. 11c's dead-index find — an RLS operator no
btree could serve, so every query scanned the whole table — reads as *fast* on a
single-tenant database. It only has a shape when there are forty-nine other shops for the
scan to wade through. And a tenant predicate selecting 2% of a table is not the same
predicate as one selecting 100%: Postgres picks a different plan for identical SQL.

Measured on the dev machine: **50 shops, ~19M rows, 5.5GB, 19 minutes**, linear from a
5-shop run at 56s. The command refuses production without `--force`, only ever adds rows,
and stamps every shop with a `load-test-` slug so `--fresh` removes the set without
touching the demo tenant or anybody's scratch shops. One shop failing is warned about and
skipped rather than aborting the run — a nineteen-minute tool that restarts from zero on
the last error is a tool nobody uses twice.

**The first verification of this schema at launch size, and it held.** Every hot report
shape was spot-checked with `EXPLAIN (ANALYZE)` as the application role with
`app.tenant_id` set — invoices for a month, items joined to invoices, stock valuation,
ledger aging. All four used an index and read only their own tenant's slice: 3,094 invoices
out of 2,000,000, and 102,400 stock movements out of 5,120,000, at 1.9–83ms. Nothing scaled
with the platform.

The fixture was removed afterwards rather than left in the dev database; the command
rebuilds it. What it has *not* done is measure an endpoint under concurrency — that is the
next item, and the query shapes being clean is not the same claim.

**A postscript from cleaning the fixture up, which is now an 11.3 note.** Removing the 50
shops took longer than creating them. Deleting a shop with a trading year behind it is a
**multi-minute, lock-heavy cascade** — roughly 380k rows across a dozen tables per shop —
and a chunk of five was still running after twenty minutes. A second `DELETE` over the
same set does not do useful work; it waits on the first's transaction. In the end the dev
database was rebuilt with `migrate:fresh --seed` rather than unpicked, which is the right
answer for a dev database and not an option for a customer's.

Two consequences are worth designing for before any shop cancels: tenant deletion belongs
in a queued job with a progress record rather than a request, and "remove these ten shops"
has to be sequential by design rather than by accident. Nothing is broken today — there is
no delete-a-tenant feature to be slow — but the cheapest moment to learn this was on a
fixture, and the most expensive would be a customer asking to be forgotten under a
data-protection deadline.

---

## 1405/05/29 (2026-08-20) — 11.3 Ops: everything that does not need a server

The instruction was to build the whole operational layer parameterised, so that pointing
at a real box is setting a host and some secrets, and to finish with the list of what
genuinely cannot be done without one. Both are done: `docs/deploy.md §7` and the roadmap's
**Needs the box** table are the same five items, and nothing on them is design work.

### Two packages the stack has claimed since ADR 0001 were never installed

`CLAUDE.md` has said "Redis 7, **Horizon** queues" since Phase 0, `make horizon` has been
in the Makefile for months, and `.env.example` reserved `SENTRY_LARAVEL_DSN` under a
"(Phase 11)" heading. Neither package was in `composer.json`. `make horizon` would have
failed the first time anybody ran it.

Nothing had gone wrong, because nothing had asked. That is the shape worth noticing: a
documented stack component is not an installed one, and the gap survives exactly as long
as nobody exercises it.

### What "wiring Sentry" actually turned out to be

Not `composer require`. A crash reporter's *job* is to take production data somewhere
else, and this platform holds fifty shops' customers in one schema. Installed the standard
way, it is a wider hole than anything RLS protects — and so are the other two things 11.3
adds. [ADR 0015](adr/0015-observability-without-disclosure.md) records the decision the
three share.

The one worth repeating here is **Horizon**, because it does not look like a leak. The
dashboard renders job payloads: `SendSmsJob` carries a customer's phone number and the
text of the message, from every shop, on one screen, and none of it is a database row — so
RLS reaches none of it. The published gate compares an email against a list resolved from
the *default* guard, which on a tenant subdomain is a shop's user. Ours asks the `platform`
guard directly, and `HorizonAccessTest` asserts a shop **Owner** — the top of a tenant's
role ladder — gets 403.

The scrubbing work produced one thing that outlives Sentry: `App\Support\SensitiveInput`.
The `dontFlash` list in `bootstrap/app.php` existed because a failed repair intake once
flashed a customer's unlock code into `sessions.payload` in clear. The identical value goes
to a crash report through a different wall, and that door was open. **One list, two doors**,
and `CrashReportScrubbingTest` iterates the list rather than spot-checking it, so the
fourteenth key cannot be added to one door only.

### Health: the grading is the feature

`/up` boots the framework. `/health` costs a Postgres round trip, a Redis round trip and a
read of the migration repository, and — the part that matters — **grades what it finds**.
Database, cache and pending migrations are critical and return 503. A queue backlog is
reported at 200.

Grading a backlog critical is the tempting "completeness" edit, and it would mean the load
balancer pulls a healthy web tier out of rotation because a third-party SMS gateway is
slow: a delayed text message converted into a shop that cannot take payment. There is a
test asserting `queue` is *not* critical, specifically so that changing it is a
conversation rather than a tidy-up.

It also became an artisan command, because `bin/deploy` has to health-check the new
container **before** nginx points at it — and cannot: php-fpm speaks FastCGI, so there is
nothing inside the container to curl, and going through nginx would mean cutting over
first. `php artisan health:check` has no HTTP layer and an exit code a shell can act on.

### The domain, enforced rather than asserted

The instruction said `config('app.domain')` stays the single source. Rather than claim it,
`bin/check-apex-domain` now runs in CI — and found one on the day it was written:
`MAIL_FROM_ADDRESS` in `.env.example` was `no-reply@hamyar.ir`, a working name nobody
owns, which every developer copying that file has been sending mail as.

Its first draft reported **ninety** findings, every one false. `invoices.store` is a route
name, `report.net` is JSX, `index.php` and `fullchain.pem` are filenames — and every one
collides with a real TLD. A gate with ninety false positives is one somebody deletes in its
first week, which this project has written down before. It now matches only in *hostname
position*: after a scheme, after an `@`, any `.ir`, or anywhere at all inside
infrastructure files.

### Two build-time traps that would only have fired on the production box

- **`config:cache` is not run in the image.** Caching config at build time bakes the build
  machine's environment into the artefact, starting with `APP_DOMAIN` — turning the apex
  into an image rebuild instead of a config change, silently, because the cached value
  simply wins. `bin/deploy` caches on the box, after the environment exists.
- **`before_send` is a static callable, not a closure.** `config:cache` `var_export`s the
  config; a closure cannot be exported and an object exports to a `__set_state()` that does
  not exist. Either would be a **fatal during a deploy**, on the one machine nobody tries
  it on first — nothing in development ever runs `config:cache`. `ConfigIsCacheableTest`
  now asserts the property directly, and was verified by planting a closure and watching
  both it and `artisan config:cache` fail.

### The backup's most dangerous failure is a green one

The application connects as a NOSUPERUSER role so RLS is a real boundary. A `pg_dump`
taken as *that* role is filtered by those same policies: with no tenant pinned RLS fails
closed, so the archive contains **zero rows from every tenant table** — while exiting 0 and
reporting a plausible size. `bin/backup-nightly` therefore dumps as the superuser, reads
its own archive back every night, and refuses one with no `stock_movements` data in it.

`bin/restore-drill` asserts the mirror of that, plus the thing a generic restore procedure
would never think to check: that **RLS came back enabled** on every tenant table, and that
the app role genuinely reads zero rows with no tenant pinned. A restored platform without
RLS has no tenancy boundary and nothing about it looks wrong until two shops see each
other's customers. It stays unticked — it reports the RTO *observed*, and there is nothing
here to observe.

### 11.1b closed: light, dark, and the paper nobody was testing

`SmokeTest` is 4 screens × 2 devices × **2 themes**. The theme is driven through
`prefers-color-scheme`, not the toggle, because that is the path a first visit takes —
`app.blade.php` reads localStorage and falls back to the OS preference before first paint;
clicking the toggle would test the *second* visit.

**Each case asserts the theme actually applied**, and that is not decoration. Without the
witness the dark half is the light half run eight more times: sixteen green cases reporting
twice the coverage they have — the same defect this suite was written to stop, and one it
had already made once with mounting. Verified by forcing light mode and watching all eight
dark cases fail with the message that says so.

`InvoicePrintLayoutTest` gained 3 papers × 2 themes, which is how **thermal80** finally got
covered at all: it has no table, so the two geometry tests skipped it, and it is the paper
most of these shops actually print on.

The bullet's stated precondition — "once the invoice conversion above lands" — was dropped
on purpose and said so in the roadmap. That conversion is parked behind print-media
emulation, and it gated the wrong thing: nothing about rendering four screens in dark mode
depends on whether a source-level guard was replaced. What is still **not** covered is
recorded as its own `[→]`: the `@media print` ink-on-white rule is guarded by nothing but
itself, because reaching it needs `emulateMedia({ media: 'print' })`. The dark-mode print
cases prove the *page* survives dark mode; they cannot prove the *paper* does.

### Answered from the session's decisions

- **Load test parked, not outstanding.** `tests/Load/endpoints.js` and its runbook are
  committed; running them here would measure Docker rather than the product, since the load
  generator competes with the app for the same cores. `[→]`, destination the staging VPS.
- **The هلو/سپیدار/محک fixtures item is removed**, as a deliberate scope decision rather
  than an unmet dependency — the owner will not be collecting them. The roadmap now records
  what actually carries the weight (ADR 0013): a dry run, an explicit user mapping with the
  guesser's answer as an overridable default, and per-row verdicts. A shopkeeper whose
  columns are unrecognised maps four of them by hand, once.

### Found on the way in, and it had already gone off

CI went red on the previous PR with a diff touching a seeder, a roadmap and a
`package.json`. `PriceResolverSharingTest` failed twice, in Catalog, which the commit does
not go near. **The suite broke because the date changed.**

That file argues at length that the instant a price is asked for must be pinned — and it
was, with a literal. The fixture underneath it was left on the wall clock. Half the
comparison pinned, half of it moving, and on 2026-08-19 they crossed. The fix everybody
remembers is what planted it.

Swept for the shape rather than for date literals — which are harmless when *both* sides
are literal, as the Cheques suites show — and found two more, both fused for **2026-09-01,
twelve days out**. Six tests fail on 2026-09-02 before the fix; all 33 across the four
files pass on 2029-03-14 after it. Detail in the commit; the rule is that a fixture built
from `now()` must never be measured against a fixed date.
- **2026-08-20 — 11.4: staging on real hardware (`mobiyar.com`), and the eleven faults it found.**
  Provisioned a Hetzner box in Helsinki, issued wildcard TLS for `mobiyar.com` + `*.mobiyar.com`
  via DNS-01/Cloudflare, deployed from `main`, seeded the 50-shop fixture (19.1M rows, 87.6 min),
  ran the k6 load test from a second Helsinki box 4.4ms away, and performed the restore drill.
  **The deploy layer had never run, and essentially none of it worked first time.** In order of
  severity: WAL archiving had *never once succeeded* (root-owned named volume; `failed_count`
  3595, no point-in-time recovery at all, `pg_wal` at 14.5GB heading for a full disk);
  `bin/deploy` could force-recreate the **live** container after any repo sync, because the
  upstream file it trusts is both tracked and runtime state; the certbot container had no DNS
  plugin so wildcard renewal could never have worked; nothing ever reloaded nginx after a
  renewal, so a renewed certificate would have expired in place; the restore drill could not
  complete a run and its isolation assertion could never have tested isolation (no privileges on
  the scratch database — "denied by RLS" and "cannot see the table" are the same zero);
  `SENTRY_RELEASE` was clobbered by an empty env line; `release/public` was unreadable by nginx;
  `pg_stat_statements` was preloaded but never created; `pg_restore` was missing on the host that
  verifies the backups. Six PRs. **Load test: aggregate p95 1.62s — FAILS the 1000ms threshold —
  with 0.00% errors across 1339 requests.** `/dashboard` is the cause at 2.03s p95 and **1.3s with
  a single user**, traced to a non-sargable date expression (26% of all DB time), a `select *` at
  81.7ms mean, and a 20,600-call N+1. Raising FPM workers made it worse, not better: throughput is
  flat at ~14 req/s, so the box is saturated and the dashboard is what saturates it. Restore drill
  **RTO 102s observed**. Sentry live with `send_default_pii` and both `sql_bindings` verified off;
  `sentry:publish` deliberately not run — it would have overwritten those with vendor defaults.

## 1405/05/30 (2026-08-21) — the landing page, reviewed a third time: «بی‌روح»

Direction B (navy-and-white premium minimal, ADR 0016) was reviewed live by the owner and
called **بی‌روح و بی‌رنگ** — lifeless, and too white. The reference offered was
`novinpardakht.com`, whose strength is not its typography: it is that the page has a
full-bleed coloured band, filled tiles and imagery, so the eye has somewhere to land.

The direction was **kept**, not replaced. What was missing was weight, and the diagnosis
matters because the obvious reading — "add motion" — is exactly the answer Direction A
already gave and had rejected:

- **The grounds were not actually different.** `page-alt` was `#F7F9FB`, which measures
  **1.03:1 against white**. On a shop's monitor the section alternation did not exist, so
  six sections read as one endless sheet. Now `#EDF2F8`, with a third tint for panels.
- **Nothing on the page was filled.** The only solid shapes were two buttons. Added: two
  navy-900 anchors (the IMEI argument as a full-bleed band, the closing CTA as an inset
  card) and a filled navy featured plan card.
- **The featured plan was marked with a 1px border** — correct, and invisible against
  three white cards on a white section. It is the thing the pricing section exists to
  point at; it is now the dark one.
- **Screenshots floated.** They sit on a tinted panel with a glow now, so a 1440px product
  shot reads as a screen instead of a rectangle with a hairline.
- **Rhythm.** 8.5rem section padding *plus* a 4rem heading margin put a screen and a half
  of nothing between a heading and its content. Sections are 6.5rem and heads are centred —
  a title aligned to the inline-start edge of a 1120px column leaves two thirds of the line
  empty, five times down the page.
- Icons, chips, numbered badges, a scan window around the IMEI, a 46px masked mesh on the
  hero and both dark surfaces.

Palette additions are measured on **both** light grounds, because an alternating page has
no single background to check against: `navy-mute` moved `#64748B → #5D6B7E` (4.38:1 on the
new alt ground would have been under the floor), `accent-lit #6BB4FF` is the same accent
lifted for navy (8.3:1 on navy-900), and the dark-band body/caption pair measure 10.4:1 and
7.0:1. Hairline `#E7E8EA → #DCE3EB`.

Still **no animation library**, still one JavaScript file, still no glassmorphism. Landing
bundle: **18.8KB gz CSS, 1.05KB gz JS** against a 180KB budget. RTL gate and Pint green;
zero horizontal overflow at 390px.

**Not fixed here, and it is pre-existing:** `composer test` is **95 failed / 1242 passed**
on this branch. Every failure is a host mismatch left by ADR 0017 (`centralUrl()` still
points at the apex while `/register`, `/login` and the shop routes moved to `app.<apex>`) —
`OnboardingTest` ×18, `PriceListSecurityTest` ×14, `StorefrontRenderTest` ×8,
`AuthenticatedNavigationTest` ×8, `LoginTest` ×6, and so on. The landing change touched
three view/CSS files and moved that count by zero.

---

## 2026-08-22 — releases became a thing that happens, and the first two happened

**`v0.12.0` and `v0.12.1` are tagged, published and live on `mobiyar.com`, verified from
outside the box.** The session began with the opposite of that.

**What it found.** Twenty-one finished commits sat on `feat/landing-immersive`. Production
served `2e2951c6e`, five commits behind that branch's tip. `app.<apex>/login` offered a
«ثبت نام» link pointing at the landing host, where `/register` is a 404 — a live fault whose
fix was already written. PR #41 was red on exactly two checks; PR #40 had been green and
unmerged for two days. Zero tags, zero releases, no `VERSION`, no `CHANGELOG`. **Nothing was
red that anybody was looking at.** The gap was between "the code is correct", which four CI
gates measured, and "the correct code is running", which nothing measured.

**The two red checks.** Both the same fault as everything else on that branch: a page moved
and its test did not follow. `SecurityHeadersTest` asked `/login` for a `nonce=` in markup —
`/login` is Blade since ADR 0016 and carries no inline script, so the assertion could only
fail there. Split: the policy's shape stays on `/login`, the stamping moved to `/design`,
the one route rendering `app.blade.php` without a session — and it now compares the markup's
nonce to the header's, because a mismatched nonce fails exactly like a missing one while
each half reads as correct alone. Larastan's single error was `expect($this->registerHref)`:
a property set in `beforeEach` is `mixed` to static analysis.

**Shipped in 0.12.0.** #41 and #40 merged; the landing rebuild, the register-link fix, the
ADR 0017 route migration across 63 test files, and the dashboard query work — where the
traced cause was a missing tenant predicate letting Postgres scan both tables across every
shop, not the date expression everybody suspected.

**The release system**, modelled on the sibling invoice-system project (241 releases):
`VERSION`, `CHANGELOG.md`, `docs/VERSIONING.md`, `docs/RELEASE_PROCESS.md`, `bin/release`,
`bin/smoke`, and `/release` + `/where` as commands. `/health` now reports the version
publicly and the exact image tag behind `X-Health-Secret`, so "is my fix live?" is a `curl`
rather than a guess. Three decisions worth keeping: a release is **not** a commit (the
version bump rides in the feature PR, so no `ALLOW_MAIN_PUSH` is ever needed);
`bin/release` **refuses** while a green PR sits unmerged; and a deploy is not a release
until `bin/smoke` says so — it *follows* the links a shopkeeper clicks rather than matching
them, which is the only assertion that fails on the page this session's 404 was hiding on.

**The repository is public** (owner decision), taken after a secret audit came back clean:
no `.env`, no key, no host in any of 178 commits. Its most valuable consequence was
unplanned — **branch protection is Pro-gated for private repositories and free for public
ones**, so `main` now requires a PR and all five checks at the platform level. `CLAUDE.md`
said in plain words that nothing here was mechanically enforced; that paragraph is rewritten,
with the condition under which it must be written back.

**Fourteen defects in the release tooling, and where each came from.**

- **Twelve** from an adversarial review of `bin/release`/`bin/deploy` before either touched
  production — 23 candidates, each handed to an independent reviewer told to refute it.
  Three critical. The worst was not a failure mode but the happy path: `git tag` creates the
  ref locally, so the tag-exists guard made the script single-shot and the two-step usage it
  *prints* could never run. Also: a `bin/deploy` failure after the cutover reported "nothing
  was cut over" — the exact 2026-08-21 shape, reported as its own opposite; three gates that
  failed **open** on a GitHub hiccup; and `rsync ./` shipping the working copy while the
  tarball and its SHA-256 asserted a different tree.
- **One** from the first real release: `remote_detached` was not detached. In
  `A && B && C & D` the `&` backgrounds the whole list, so the remote shell forked a subshell
  that waited on the `nohup` with its stdout still on the ssh channel. Everything looked
  right — and the poll loop never ran one iteration, so the drop protection was decorative.
  Measured: 32.4s versus 2.4s for a 30-second remote command.
- **One** from asking what a loop does when it matches nothing: `bin/smoke`'s landing check
  matched only absolute hrefs and looped over what it found. A switch to relative paths, or a
  redesign dropping the CTAs, and it would report **no checks at all** while declaring the
  front door healthy.

That last pair is the same lesson twice, and it is the one worth keeping: **a gate that
passes because it cannot see is worse than no gate**, because it also stops anybody looking.

**Open, and deliberately not claimed as fixed.** The dashboard's p95 was measured under k6
load on 2026-08-20 at 2.03s against a 1000ms threshold. The query fix is live and the
pathology is gone from the top of `pg_stat_statements` — no statement on the box now exceeds
11.5ms mean over 2,000,001 invoices — but those stats span both sides of the deploy and
idle-state timings are not a load test. **Re-run the k6 suite before calling that finding
closed.**

## 1405/06/07 (2026-08-29) — Phase 12 designed: metered plans, a quota ladder instead of module bundles

**The owner changed the business model** in one message: every module open for every shop
from the first minute, every kind of work capped by quantity — mostly per day — and three
plans forming a ladder, so hitting a cap is the moment a shop upgrades. Nothing was built
today; the deliverable is the design, and it is [ADR 0018](adr/0018-metered-plans.md)
(Proposed) plus Phase 12 in the roadmap ending in **Decision Gate 6** with sixteen items.

**How the design was made, because the method is what makes it trustworthy.** Nine
readers mapped every mutating action in the 18 modules — 100 of them, each with its
transactional entry point, whether it is bulk or event-driven, and whether a shopkeeper
would accept a cap on it — plus the platform layer, the tenancy/caching infrastructure,
the test conventions and the frontend conventions. Three designers then wrote complete
designs from different angles (correctness, hot-path scale, product/funnel); three judges
scored them and the correctness-first design won (153 / 137 / 128) with grafts from the
others; twenty load-bearing claims of the merged design were then handed to independent
verifiers told to refute them against the code, and **ten did not survive as written**. Every
correction is in the ADR and named where it changed a decision — the SQL needed explicit
casts on every placeholder; the "must run inside a transaction" throw is inert under
`RefreshDatabase`, so a spy guard and a grep gate enforce it instead; `ConcurrentFinalisationTest`
is sequential and could not be the concurrency harness; the 60-second cached version made
"Filament edits are seen next request" false until the bump became write-through;
importers can only know their create count *after* their loop; roughly 25 forms render only
field-keyed errors, so the block is rendered once in the shell rather than trusting the
error bag; the SMS order had to be charge → consume or an empty wallet leaked a quota
unit; invitation accept must not re-check a seat it already holds. A final completeness
pass found two metrics the map had marked meter-worthy and the design had dropped, a
lifecycle with no writers for `past_due`/`canceled` (so MRR counts lapsed shops forever),
and a contradiction between "rung 1 is paid" and "lapse falls to rung 1 quotas" — a free
plan through the back door — which is now Gate 6 item 2, decided openly.

**Two latent billing bugs found on the way, both on the exact path a ladder depends on.**
`BillingService::applyPayment()` never writes `subscriptions.plan_id` — a paid upgrade
extends the period and leaves the shop on the old plan, and no test asserted otherwise —
and `billing/index.tsx` posts `plan.code` to a route bound by id, so the upgrade button
404s. Both are 12.1, a plain bug fix that may land before the gate.

**Decisions taken in the design, for the gate to confirm:** the meter is a Postgres row
incremented by one `INSERT … ON CONFLICT DO UPDATE … WHERE` inside the transaction that
writes the counted document, never a `COUNT(*)` over domain tables (soft deletes, recreated
children, drafts and voids make every such count wrong); the contract lives in
`App\Support\Quota` with a `bindIf` null object and Platform's singleton, the
`PartyExposure` shape; counters are never reset on upgrade — the limit is resolved at check
time; period keys are Gregorian dates even for Jalali months (the first day of the month)
so no Jalali string is stored and one retention cutoff works; `module:` middleware stays
as a platform kill-switch; reads are never blocked and lapse never locks a shop out;
being blocked is error-bag shaped with the prorated upgrade CTA, never a 4xx page.

**Operational:** the owner said there is currently no production server; a new one will be
provided. Releases are suspended until then, and CLAUDE.md's environment section says so.

## 1405/06/07 (2026-08-29) — Gate 6 cleared the same day, and it moved the window

The owner answered all sixteen items within the hour. Fourteen came back as recommended;
two changed the design, and the second one is the interesting one.

**«کلاً می‌خوام سقف‌ها ماهانه باشه نه روزانه.»** The design had been written to their opening
message, which said «روزانه». At the gate they replaced it with one credit per feature per
Jalali month, refilled at 00:00 Tehran on the 1st, and named the model they had in mind:
«دقیقاً مثل پلن‌های فعلی جی‌پی‌تی و کلاد که تا یه حد مصرف رو رایگان دارن، یه تایمی ریست میشه».

That is a product U-turn that cost almost nothing, and the reason is worth writing down: the
counter was never designed around a day. It is keyed `(tenant_id, metric, period_key)` and the
clock computes the key from the metric's window, so switching every metric from `day` to
`month` changed the matrix, the Persian copy and one enum — not the guard, not the SQL, not
the migration, not the tests' shape. The design's one genuinely load-bearing choice (resolve
the limit at check time, never bake it into the counter) is what made a window swap a
data change. `Window::Day` is now deleted rather than kept "just in case": a case nothing uses
is a promise nobody keeps. It also retires the burst-allowance item as a *problem* instead of
deferring it as a feature — a monthly credit is what a burst allowance was trying to be.

**The first rung is free, and it eats the trial.** The completeness review had already found
that "paid Basic + lapse falls to Basic quotas" is a free rung through the back door, so the
item was put to the owner openly and they chose free. The consequence is deletion, not
addition: `TrialPolicy`, `BASELINE_PLAN_CODE`, the borrowed-limits table and the trial
branches in `SubscriptionResolver::limit()` and `ProrationCalculator::preview()` all go, and a
new shop simply starts `active` on a zero-price plan. `messaging.sms` is 0 there — the one
quota that costs us cash per unit is funded by the wallet rather than given away, which closes
the re-registration abuse the old trial docblock had documented.

Numbers, monthly, for the free rung: 300 invoices, 200 IMEI units, 200 products, 200 parties,
100 repair tickets, 100 quotes, 50 purchase receipts, 0 SMS. Deliberately below the 500 that
the business plan gave *paid* Basic, because that number was priced at ۲۹۰ هزار تومان and this
one is priced at nothing. `pro` is 5,000 invoices; `enterprise` is unlimited except the
cost-bearing totals (25 seats, 50 GB), which an override lifts per shop.

Recorded in the same pass: golden rule 7 rewritten in `CLAUDE.md` (gating is quantity, not
availability — and a metered create path that does not call `consume()` is a bug of the same
class as a tenant table without RLS), «سازمانی» → «نامحدود», `laravel/pennant` approved for
removal, Moadian never metered and explicitly low-priority («کلا بیشتر مغازه‌ها معاف از
مالیاتن اونو نمی‌خوان»), and the platform absorbing system-SMS cost under a per-tenant daily
cap. Version `0.14.2`, docs only, no release — there is still no production box.

## 1405/06/07 (2026-08-29) — 12.1: the upgrade click, which had never worked

Phase 12's first PR is not new machinery, it is the path the machinery will depend on. Three
defects, all on the single click that turns "you hit your limit" into revenue.

**`applyPayment()` never wrote `subscriptions.plan_id`.** A shop pressed upgrade, paid, and
stayed on its old plan. The period was extended, the invoice was marked paid, both events
fired, and nothing was wrong anywhere a test was looking. The reason it survived from Phase 2
is worth naming: `subscription_invoices.lines` is a deliberate human-readable snapshot — an
invoice must read the same after a plan is renamed — so the settlement path had the plan's
*name* and no way to act on it. The fix keeps both jobs separate: `lines` says what the
invoice **said**, a new `plan_id` column says what it **meant**. A renewal (paying again for
the plan you are on) deliberately leaves `plan_changed_at` null rather than stamping a change
that did not happen.

**The upgrade button 404'd.** `billing/index.tsx` has always posted `plan.code` at a route
bound by id. Seventeen green billing tests coexisted with a button that could not work,
because every one of them drove `BillingService` directly and **not one posted to the route**.
That is the same lesson as `bin/smoke`'s landing check, in a different costume: a suite that
tests the layer under the door proves nothing about the door. `Plan` now routes by `code`;
`PlanResource` is pinned to `id` so a model-level change does not silently rewrite the panel's
URLs, which nobody asked to move.

**A shop with no subscription row that paid got nothing at all** — `applyPayment()` fired its
event and returned. Found while reading the branch, not reachable through the normal signup
flow, and fixed anyway: "we took the money and the shop got nothing" is not a failure mode to
leave to the provisioning path being perfect.

Plus the listener `SubscriptionActivated` had promised in its own docblock since Phase 2 and
never had. `SubscriptionResolver` is a singleton memoising one subscription per tenant id, so
without it the very request that took the payment kept answering from the pre-payment plan —
the upgrade was real in the database and invisible to the process that made it.

Six new tests. `0.14.3`, no release — still no production box.

## 1405/06/08 (2026-08-30) — 12.2: the quota kernel, and a boundary that was wrong by 3½ hours

`App\Support\Quota` exists: the `QuotaGuard` contract every metered module will call, the
`Metric` value object and its registry, the verdict DTO, two events, and `PeriodClock`.
Nothing calls it yet — Platform binds the real implementation in 12.3 — so the container
answers with `NoQuota`, bound with `bindIf` while Platform will bind with `singleton`. That
asymmetry is the `PartyExposure` lesson written into a new file: two providers binding one
interface with `bind` means a directory listing picks the winner, and the losing outcome is
not a crash but a product whose limits silently do nothing.

**Two simplifications the owner's monthly decision unlocked.** With `Window::Day` gone,
`Month` ⇔ counted and `Total` ⇔ computed exactly — so `MetricKind` was deleted before it was
written. A second enum could only agree with the window or disagree with it, and the second
is the bug. The pairing is enforced where it can actually be wrong instead: `Metric`'s
constructor refuses a counted metric carrying a `measure` closure (two answers to "how much
has been used", nothing to say which is right) and a standing capacity carrying none
(nothing to read, so a seat cap that never fires). And the clock is a new `PeriodClock`
rather than a move of Reporting's `ShopClock`: they answer different questions, and moving
ten call sites to share a name is churn, not reuse. The promotion is now a 12.12 task, owned
by the first thing outside Reporting that actually wants the SQL expression.

**The trap, and it is a good one.** `Jalali::startOfMonth()` returns the first of the Jalali
month at midnight **UTC**, which is 03:30 Tehran. That is exactly right for the period key —
the key is its *date* part, and the date is the same either way — and exactly wrong for
`resetsAt`, which is what a screen shows a shopkeeper. Returned unchanged, the billing page
would have promised «سهمیهٔ بعدی ۰۳:۳۰» while the counter had already refilled at ۰۰:۰۰: a
shopkeeper told to wait for something that had already happened. What makes it worth writing
down is that the obvious test does not catch it. Asserting the *date* of the reset passes
with both the right and the wrong instant; only asserting the time of day fails. So
`QuotaPeriodClockTest` asserts `H:i === '00:00'`, plus the two controls that fail if anyone
reaches for a Carbon convenience method later: UTC midnight must NOT roll the credit over,
and neither must the end of a Gregorian month.

`config/hamyar.php` arrives with it. Its one opinionated line: a fallback plan that resolves
to no row **throws**, rather than falling back to unlimited. The lenient reading would hand
every lapsed shop everything — failing open, in the one layer whose whole job is to fail
closed. `0.14.4`, no release; there is still no production box.

## 1405/06/08 (2026-08-30) — 12.3: the meter, and three states nothing had ever written

`usage_counters`, `tenant_limit_overrides` and `usage_events` exist, with
`DatabaseQuotaGuard` and `LimitResolver` on top. No module calls the guard yet; the machine
is complete and the call sites land next.

**A spend is one statement.** `INSERT … SELECT … WHERE n <= limit ON CONFLICT DO UPDATE …
WHERE used + EXCLUDED.used <= limit RETURNING used`. The shape it replaces — read, decide
in PHP, write — is the double-spend bug: at the last unit two requests both read "one
left", both say yes, and the shop gets two for one, silently, on its busiest day. Every
placeholder in the bounded statement carries an explicit cast because Postgres cannot infer
a parameter's type outside a `VALUES` column position and fails at prepare time without
them; the unbounded statement uses `VALUES` and needs none.

**The fork harness was written and then deleted.** Twenty children at the last unit, a PDO
connection each, counting winners — and it went in the bin, because forking inside PHPUnit
is fragile for reasons that have nothing to do with the code under test, and a concurrency
test that hangs the build once a fortnight teaches everyone to re-run CI instead of reading
it. `AtomicityTest` asserts the two properties that are both deterministic and the ones
that actually matter: **one statement per spend** — a refactor into read-decide-write fails
the test, and that refactor *is* the bug — and **the cap evaluated against committed
state**, proved by making a decision from a stale read and watching the statement refuse
it. Postgres's own `ON CONFLICT` re-evaluation under READ COMMITTED is documented behaviour
we rely on rather than re-prove, and `DECISIONS-FOR-REVIEW.md` says so plainly rather than
letting a green suite imply coverage it does not have.

**Three states modelled since Phase 2 with nothing writing them.** `subscriptions:expire`
is the first writer of `past_due`, `grace_ends_at` and `canceled`. Two consequences had
been invisible the whole time: there was **no grace period** — `isUsable()` reads a column
nobody set, so an active row stopped being usable the instant its period ended, which is
the opposite of what its own docblock promises about Iranian gateway outages — and **MRR
counted every shop that had ever paid**, for ever, because nothing ever left `active`. A
zero-price subscription is exempt: it is not late, it is free.

**Failing closed where it counts.** A fallback plan that resolves to no row throws. The
lenient reading hands every lapsed shop unlimited everything and says nothing; in the test
suite it would also let a file that forgot its subscription fixture pass unmetered, which
is exactly how a create path that never calls `consume()` stays green for ever.

`bin/check-quota-scoping` joins the lint job. These three tables carry RLS but not
`BelongsToTenant` — the panel reads across shops and the Eloquent scope would return
nothing inside `runAsPlatform()` — so every production query must carry its own
`where('tenant_id')`, and the gate makes that mechanical rather than a matter of whether
the reviewer remembered. It skips the suite on purpose: an isolation test queries unscoped
precisely to prove RLS is the thing doing the work.

## 1405/06/08 (2026-08-30) — 12.4 and 12.5: the flip

Every module is open to every shop, `basic` is free, and `TrialPolicy` is gone. This is the
release where the gate's decisions stop being a document.

**Deleting a policy rather than inverting one.** The obvious move was to keep `TrialPolicy`
and turn it inside out — trial gets *these* quotas now. The free rung made the whole class
unnecessary: a shop evaluates the product by using it, indefinitely, so there is no trial to
have a policy about. `BASELINE_PLAN_CODE`, the borrowed-limits list and the trial branches in
`SubscriptionResolver::limit()` and the resolver's `$baseline` memo all went with it.
`startTrial()` became `startOnFreePlan()`, and a new shop is now `active` on a zero-price
plan with `current_period_end` null — which `isUsable()` already read as "usable" and
`hasLivePeriod()` already read as "nothing to prorate against", so the first paid upgrade is
charged in full without a special case being written for it.

**The middleware kept its shape and changed its question.** `EnsureModuleEnabled` used to
ask whether a shop's plan included a module. Thirteen route groups, the nav, the dashboard's
widget list and three public-page tests consume that shape correctly for a *different*
question — have we switched this on? — which is exactly what ADR 0011 has needed since
Moadian shipped as an adapter with no provider. Deleting it would have been a twenty-file
diff to arrive back where we started. What changed is the answer's source
(`modules.is_enabled`, a panel toggle) and the copy: «این بخش موقتاً در دسترس نیست» instead
of «در پلن فعلی فروشگاه شما فعال نیست».

The test that guarded the old behaviour now guards the opposite, and says so at the top of
the file — `PlanGatingTest` became `ModuleSwitchTest`, and its cases went from "Basic is
refused Repairs" to "the free plan gets every module, and only a switch we flipped takes one
away". Renaming rather than editing in place was deliberate: a file called PlanGating
asserting that plans do not gate is a name that lies.

**A Filament field name cannot contain a dot**, and every metric key has one. Discovered by
reasoning rather than by a red build — `limits.sales.invoices` would have become nested
state and every metric would have collided with its own module prefix. `PlanForm::fieldFor()`
flattens to `quota_sales__invoices` and `EditsPlanLimits` translates back, in one place, so
the form and the pages that fill it cannot disagree about what a field is called.

**One dead number found on the way.** `RevenueOverview` counted "trials in progress" — a
stat that could only ever read zero once the free plan took the trial's place, sitting on
the one dashboard that is supposed to say how the business is doing. Replaced by the count
of shops on the free rung, which is the same query shape and is the actual upgrade pool.

`0.15.0`, BREAKING-prefixed: an existing plan row's meaning changes. Still no production box,
so still no release.

## 1405/06/08 (2026-08-30) — 12.6 to 12.14: metering everything, and four bugs it uncovered

Every module now spends credits. The interesting part is not the call sites — those are a
line each — it is that making previously-unreachable paths reachable found four defects,
none of them in the quota code.

**`runAsPlatform()` was not re-entrant, and RLS does not error.** Its `finally` forced the
flag off, so a nested call — an event listener recording something inside a platform-context
write — cleared it for the outer block still running. Postgres does not complain when a
policy denies you; it returns nothing. So the symptom was `findOrFail` failing on a row that
plainly existed, a hundred lines from the nested call that caused it. That took an hour to
find and would have taken a day in production. The depth is counted now, and cleared once by
the outermost caller — which is the discipline `runFor()` has had since Phase 1, and this
never did.

**Two resolver bugs, both silent.** `LimitResolver` read `subscriptions` without the
platform flag, so called for an arbitrary tenant id — from a job, from the panel — it saw no
subscription and metered a shop on the top rung at the free rung's credits. And
`nextPlanFor()` walked the public plans until it met the current one, which never happens
for a shop on a private plan: the customer most likely to have a negotiated price was the
one told there was nowhere to upgrade to.

**Proration could produce a price the product cannot say out loud.** `intdiv` truncated to
the rial; `Money::toToman()` refuses to round money and throws. That was unreachable while
every upgrade subtracted an unused credit that happened to round the total off — and became
reachable the instant a plan was free, because then the amount due IS the raw portion. The
billing page 500s. Fixed in `ProrationCalculator::portion()`, which now truncates to the
toman: same direction as ADR 0006 (the remainder stays with the customer) and all three
figures on the line now add up, which a total-only rounding would not have given.

Three smaller ones on the way: a party and its contacts written as two unwrapped statements
(a failure between them left a customer with no phone number), a blob written to disk before
its row existed (orphaned files nothing references), and the POS quote number taken outside
a transaction.

**On testing.** PHP and Postgres now run on this laptop, which changed how this session
worked: Pint and Larastan went from three-minute CI round trips to three seconds, and the
last eight defects were found locally rather than by reading GitHub logs. The suite still
has 17 failures here that CI does not — Persian `LIKE` terms producing invalid UTF-8, a
local mbstring difference — so the branch was validated by *diffing* its failures against
`main`'s on the same machine. Same 17, same tests. That is the honest way to use a local
environment that is not CI: not as an oracle, but as a comparison.

Deferred with reasons: the quota SMS alerts and the renewal-reminder listener both need a
pattern registered with an SMS provider we have no account for. Inventing a template id
would produce a string the gateway rejects at send time — the same reason Phase 8 deferred
`sms.ir`, and the banner already carries this message.

---

## 2026-08-30 — `0.16.0`: the bundle tables go, and the meter gets tested where it is met

Phase 12.15 as scheduled: `plan_module`, `subscription_addons`,
`modules.is_addonable`/`addon_price`, `SubscriptionAddon`, `Subscription::addons()`,
`Plan::modules()`, `Module::plans()` and `laravel/pennant` all dropped, one release after
`0.15.0` stopped reading them. `ModuleResource` was on that list and is deliberately kept:
its add-on fields went last release, and what remains is the only screen that flips
`modules.is_enabled` — deleting it would have left a kill-switch only Tinker can reach.

Then the phase's Definition-of-Done item nobody had walked — *every metered key has an
enforcement-site test* — which is where the session actually went.

**There were none.** Every quota test in the repository meters a synthetic `quota.widgets`
metric against the guard in isolation. Correct, and deliberately isolated so the guard's
tests break when the guard breaks; but a guard that is perfect and never called is
indistinguishable, from the shop floor, from no guard at all. Writing the first one — POS at
its ceiling — took four attempts to go green, and each failure was a real thing.

**`QuotaExceeded extends RuntimeException` had disabled the block on most of the product.**
A dozen controllers wrap their domain call in `catch (RuntimeException $e)` and turn it into
a field-level validation message — the established way this codebase reports «موجودی کافی
نیست» beside the input that caused it. Every one of those arms swallowed the quota block on
the way past. At the till, a shop that hit its monthly invoice cap got the raw English
`Quota exceeded for [sales.invoices]: 300 used of 300, 1 requested.` under the line-items
field, and `quota_block` — the Persian sentence, the reset date, the upgrade button — never
reached the page at all.

Nothing crashed. The refusal was correct, the transaction rolled back, no credit was spent;
only the *telling* was wrong, which is exactly why nothing caught it. Every existing test
asserted on the counter, and the counter was right. It is the same shape as the `bindIf` bug
and the `jdate()` one: no error, the wrong implementation just wins.

Fixed at the root rather than at the call sites. `QuotaExceeded extends Exception` puts it
outside every one of those arms by construction, including in controllers nobody has
written yet — where adding `catch (QuotaExceeded) { throw; }` above a dozen existing arms
works today and silently stops working at the thirteenth.

**Six metered features have no way in.** Looking for an enforcement site to test found
that, across 104 write routes, nothing creates a cheque, an expense or income, a recurring
template, a rental contract, a campaign, or a treasury account. Each has its service, its
ledger-posting matrix, its events and its tests — a `Cheque` row is written in nine test
files and zero production files — and each is priced on the ladder, so the free rung
advertises «۵۰ ثبت چک» for something a shop cannot do once. This is precisely the failure
CLAUDE.md's last workflow rule names, and it had been sitting under three ticked boxes
since Phase 7. Those are now `[~]` with the reason on the line, and §12.16 lists the six
missing screens.

Not built here, deliberately: six create screens is Phase 7/8 product work, not a release
about dropping tables. Written down rather than fixed quietly, because the dangerous
version of this bug is the one nobody says out loud.

**Ten `QuotaEnforcementTest` files**, one per metered module, driving the real route at its
ceiling: refused, `quota` in the session, the block payload carrying the keys the React
component reads, its message in Persian, and no counter row *at all* — a different claim
from a row reading zero, and the only one that says the transaction really rolled back.
With the exemptions nothing had ever asserted: a repair-delivery invoice does not spend a
sales credit, a report on screen does not spend an export, collecting an installment is
free, and a system SMS is never metered. The Files module had no tests whatsoever before
today; it now has four.

**Three more, all found by agents writing the enforcement tests, none of them in the quota
arithmetic.** The counter was never wrong. What was wrong, every time, was what a shop was
*told*:

- **The second block of any month was a white 500.** The renderer writes a `usage_events`
  row on every block; the unique index makes the month's second one a duplicate, which
  `UsageEvents::write()` catches. But a `runAsPlatform()` sat *inside* the transaction, and
  it restores its flag in a `finally` — which ran while the transaction was still aborted,
  died with 25P02, and **replaced** the duplicate-key exception on the way up, so the catch
  never matched. The block worked once per metric per month and then went blank. The `try`
  was outside `transaction()`, correctly, exactly as CLAUDE.md says; the rule simply did not
  cover `finally`. It does now, and `bin/check-savepoint-recovery` enforces it — which
  immediately found a second instance in `TenantProvisioner`, where the nesting is
  unavoidable and now carries its reason.
- **Revoking an invitation did not free the seat.** The measure counted pending invitations
  without `whereNull('revoked_at')`, while `Invitation::isPending()` and the users screen
  both say a revoked one is gone. A shop at its cap that mistyped a mobile was locked out
  for seven days while its own screen said the seat was free.
- **Total-window metrics were promised a monthly reset.** «سهمیهٔ ۲ کاربر این ماه … تمام
  شد» for a standing capacity that nothing refills. `resets_at` was already null, so the
  card said "this month" and then would not name the date. Fixed in the message, the card
  and the banner — and the fix on the PHP side landed first, which is how an agent caught
  the React half contradicting it in the adjacent line.

**What the delegation was actually worth.** Six agents wrote thirteen files; between them
they found three production bugs I had not, corrected my brief twice where I had the
enforcement site in the wrong place (Purchasing meters at *receipt*, not at draft; Inventory
at *dispatch*, not at create), and refused to assert four claims they could not make
honestly — writing down why instead of engineering a contrived failure to satisfy the
template. That last part is the one worth keeping: a test suite that asserts only what is
true is worth more than one that is complete.

**On running six agents against one Postgres.** They raced on `RefreshDatabase` and produced
`relation "migrations" does not exist` in files nobody had touched. Six databases, one per
agent, fixed it. Worth remembering before the next fan-out that writes tests: parallel
agents need parallel schemas, not just parallel worktrees.

Docs caught up with Gate 6 in the same pass: `docs/specs/platform.md`, the Gating rule in
`docs/specs/README.md`, an acceptance line on each of the 14 metered specs,
`docs/architecture.md` — which still described `SET LOCAL`, the exact thing golden rule 1
exists to forbid — `docs/VERSIONING.md`, and `docs/testing.md`, which cited two helpers
that do not exist. `docs/load-testing.md`'s "never against production" note is rewritten to
key on *what the data is worth* rather than on a box's name, so it cannot expire the way it
did; and the volume seeder now seeds onto the unlimited plan, since fifty shops writing
thousands of invoices is precisely what the meter exists to refuse.

Not deployed: there is no production server. `bin/release --deploy` and `bin/smoke` stay
suspended until the owner provides the new box.

---

## 2026-08-30 — `0.17.0`: the upgrade round trip, and the shops the widget could not see

Phase 12.6, whose boxes were never ticked. Checking the code rather than trusting the memory
of having written it found that most of it was there and the piece that mattered most was
not.

**A shop that paid mid-sale did not get its sale back.** The DoD says a shop runs out of
credit at the till, upgrades, and *lands back on the same form*. Every clause was true
except the last: `billing/callback` sent everyone to the invoice receipt. An operator blocked
mid-sale paid, landed on a receipt, and had to walk back to the till and retype a basket
they had already built with a customer waiting — the upgrade worked, the sale did not
happen, and the whole thing reads as the payment having failed. `payment_attempts.return_to`
carries the screen now, on the attempt row rather than the session, because the customer may
come back in a different browser — which is exactly why that route sits outside `auth` and
`tenant` (ADR 0017).

**`ReturnPath` is an allow-list, and writing it that way was not paranoia.** This value
starts in a query string, survives a round trip through somebody else's website, and comes
back to be handed to `redirect()` on `https://…/billing/callback` — a URL a customer has
been trained to trust. Anything not obviously a path on our own site is discarded and the
caller falls back to the receipt, so a bad value costs a convenience and never the payment.
The case worth naming is `//evil.test`: it starts with a slash, passes a
`str_starts_with('/')` check, and is a *host* to every browser.

**A third bug, and the same shape as the last three: the counter was right, the telling was
wrong.** `blocked_at` was stamped with an `UPDATE` on `usage_counters`, and a shop refused on
its **first** attempt has no row for that update to match. The metric most often capped at
zero is `messaging.sms` on the free rung — every shop that has never paid us — so a free
shop could be refused daily and never turn red, on the widget whose entire job is to start
the upgrade conversation. The shops most worth talking to were the only ones invisible.
Reads `usage_events` now, which is written whether or not a counter exists, scoped to the
current period so a block in Mordad does not still show red in Mehr.

That is now **five** bugs in two releases where the quota arithmetic was correct and what a
shop was *told* was wrong. It is worth naming as a pattern rather than a coincidence: this
subsystem's tests all reach naturally for the counter, because the counter is the thing with
a number in it. Nothing about a counter assertion can see a swallowed exception, a 500 on the
second block, a seat that never came back, a month promised to a capacity that has none, or a
red light that never lights. **Assert on what reaches the operator.**

**On ticking boxes.** Four of the six items in 12.6 were done months ago and unticked, and
one was never built at all — and from the roadmap alone the two were indistinguishable. An
unticked box that is secretly done trains you to skim; the one time it is genuinely undone
is the time you skim past it. Checked each against the code, ticked what was real, built what
was not.

---

## 2026-08-30 — `0.18.0`: what the product says when something goes wrong

Two findings, both from the same question: not "does the server refuse correctly" — it
always did — but "what does the shopkeeper read when it does".

**Every validation message in the product was English.** `config('app.locale')` is `fa`, the
fallback is `en`, and there was **no `lang/` directory at all**, so Laravel answered from its
own English file inside `vendor/`. «The identifier field is required.» — left-to-right,
naming a database column, on a right-to-left page, in a product whose entire market is Iran.

It hid for eighteen releases because 21 of the 24 FormRequests hand-write Persian for the
specific rules somebody remembered — 121 keys. So every path anybody actually tested was
Persian, and every path nobody anticipated was English. Plus all 40 inline
`$request->validate()` calls, which have no `messages()` at all.

`lang/fa/validation.php` now covers all 109 rules with the nested size/password structures
intact, and 220 `attributes` labels. The attributes half is the one that decides whether a
message reads as Persian or as a leak of the schema: without an entry, the raw column name
is substituted mid-sentence.

**The till could refuse a sale without telling anybody.** An audit of all 34 submitting
components — every finding then adversarially verified against the real FormRequest, and the
verifier told to default to "not a bug" — confirmed 24 orphan keys across 9 files.
`PosSaleRequest` can return **twelve** the POS screen could never display; its error region
was `errors.lines ?? errors.branch_id ?? errors.invoice`, three hardcoded keys, and
`PaymentBox` is not passed the error bag at all. A cashier who typed a tendered amount below
the total pressed F9 and the screen did not change. No message, no highlight, nothing to
debug — because from their side there was no error. So they press it again.

That is CLAUDE.md's "a home for errors that belong to no field" rule, almost verbatim,
happening on the one screen where somebody is standing at a counter with a customer waiting.

`<FormErrors>` takes the **whole bag** rather than a list of keys, and that inversion is the
design. A component you have to tell which keys to show needs updating every time somebody
adds a rule — and the keys nobody thought to place are exactly the ones that go missing.
Default-visible, hide-deliberately. `handled` collapses nested keys so naming `lines` covers
`lines.0.quantity` without doubling up on forms that already do the right thing.

**On the review pass that did not run.** The session limit killed three agents, including
both reviewers of the Persian file. Doing that review myself found two things they were
meant to: **nine duplicate attribute keys** — PHP silently keeps the last, so which label a
shopkeeper sees depended on file order — and inconsistent ezafe («شماره تماس» beside «شمارهٔ
پیگیری»). Worth recording that the failure mode of a dead reviewer is not an error but a
plausible-looking file, and that the two defects it would have caught were both invisible to
`php -l` and to every test.

**And two of my own tests were wrong, not the code.** I asserted placeholders in order —
but Persian puts the condition first («چون :other برابر :value است، :attribute…»), so the
order differs on every conditional rule and differing is correct. And I asserted no Latin in
any label, but «کد IMEI» is what is printed on the box and said out loud in the shop.
Both now assert the true thing: placeholder *sets*, and Latin outside an allow-list of
proper nouns.

---

## 2026-08-30 — `0.19.0`: why this project develops slower than its sibling, measured

The owner asked why Hamyar moves slower than their `invoice_system` project, and guessed at
VMs and machine load. Measuring both settled it, and most of the answer was actionable.

**The comparison, on real numbers rather than impressions:**

| | invoice_system | Hamyar |
|---|---|---|
| lines of code | 66,000 | **132,000** |
| tests | 1131, per-test **SQLite** | 1486, real **PostgreSQL** |
| CI | **4 min** | **13 min** |
| releases | 359 tags / 90 days | 3 tags / 24 days |
| PRs | 1 (works on `main`) | 56 |
| local stack | native | colima VM + 6 containers |

First correction: **Hamyar is not the lighter system, it is twice the size.** Multi-tenant
with RLS, 18 modules, Jalali, RTL. Some of the difference is inherent and should not be
argued away.

**But most of the gap was not inherent.** A test that touches the database costs 0.35s
against 0.02s for one that does not — 17x, and not negotiable, because RLS is the guarantee
this product sells and SQLite cannot express it. What *was* negotiable: **`paratest` ships
with Pest and had never been switched on.** Eighteen releases of running 1486 database tests
one after another.

Turning it on: **969s → 136s locally (7x), 13min → 6min in CI (2.2x)** — CI gains less
because a GitHub runner has 4 cores against the laptop's 12. Plus the plan catalogue, which
was seeded per test since Phase 12: 1486 × 76ms = 113 seconds of pure repetition.

**Two seams that look correct and are not**, both worth the entry in `docs/lessons.md`:
`afterRefreshingDatabase()` fires *after* the per-test transaction opens, so a seed there
runs every test and rolls back with it (26 failures to discover). And overriding
`migrateDatabases()` on `Tests\TestCase` **never executes at all** — Pest applies
`RefreshDatabase` to the generated subclass, and a trait method beats an inherited parent
method. Dead code that looks alive; the same family as `jdate()` and `bindIf`.

The seam that works is `$this->seeder` — a *property*, which does inherit — read into
`migrate:fresh --seeder=…` once per process, before any transaction opens.

**The honest part of the answer is that some of the slowness was mine.** I ran the full
suite locally twice in one day (16 minutes each) and fanned out six agents that each ran
tests — the exact thing this project's own memory forbids — and they raced on one database
and cost a round of false diagnosis. The per-test catalogue seed was also mine, added in
Phase 12 without measuring what it cost.

**And the part that is not fixable with code:** 3 tags against 359. `bin/release` is
suspended because there is no production box. invoice_system puts every change on real
production the same day, and that feedback loop is itself most of its speed.

**On `main`-only versus PRs**, since the owner asked: recommended keeping PRs. The bottleneck
was never the PR, it was the 13-minute wait inside it — now largely gone. And the one real
asymmetry is that invoice_system is single-tenant: here a scoping mistake leaks one shop's
data to another, and a mandatory gate before `main` buys something there that it does not.

---

## 2026-08-31 — UI redesign, Phase 0: land the work, then build something that can see it

The redesign programme's first phase. Two things had to happen before any new screen work:
the existing redesign had to stop living on one laptop, and the browser suite had to become
capable of noticing a regression.

**The working tree held 14 modified files and 7 untracked paths, all uncommitted** — the
treasury and invoice redesigns, plus shared-primitive changes affecting all 75 pages. Split
into three reviewable PRs rather than one: `#64` the shared layer (the 40px interaction floor,
the RTL numeric-column fix, the opaque sheet, `<ShareBar>`), `#65` treasury, `#66` the invoice
document. Shared primitives shipped alone and first, because everything else consumes them.

**The dependency graph did not match the plan.** The plan put `CRM/Parties/Index.tsx` with the
invoice PR and `share-bar.tsx` with treasury; both actually belong to the primitives PR — the
Parties change exists because of the `DataTable` alignment fix, and `/design` imports ShareBar.
Splitting by file list rather than by dependency would have produced two PRs that did not
compile on their own. Worth checking imports before drawing PR boundaries, not after.

**The smoke suite was testing empty screens.** Every case ran against a tenant with no rows, so
nine screens rendered empty states and nine empty states passed — green on precisely the states
least able to break, which is the third time this file has learned that shape (mount, then
theme, now fixture). It now seeds a real shop: a catalogue with variants, four handsets, three
parties, a ticket, four accounts, and a sale **posted through the POS** rather than fabricated,
because an invoice is a counter row plus movements plus ledger entries plus a quota consumption
and a hand-built header is a row no screen would ever show. Four paths added; 20 cases → 36.

And it carries a witness: the paths known to render a table must actually have rows in it,
verified by disabling the seed and watching it fail rather than by assuming. `/repairs` is
excluded on purpose — its list is hand-rolled `<div>`s, so a row count there would assert
nothing and pass for the wrong reason.

**Formatting was the one frontend rule with a script and no gate**, and 25 files across 9
modules had drifted with nobody deciding they should. `docs/design-system.md` §3 had been
documenting a `format:check` script that did not exist. Both fixed in `#68`. The reformat was
proved cosmetic rather than assumed: 14 affected screens rendered before and after, `innerText`
normalised the way JSX normalises whitespace, compared — 14 of 14 byte-identical.

**Browser QA found one real defect and cleared everything else.** A 56-case sweep (7 paths ×
4 viewports × 2 themes) against the local stack: no console errors anywhere, one `<h1>` per
page, theme witness correct in all 56, and no overflow except `/design`, which scrolled 600px
in a 375px viewport because the PrintLayout paper specimens cannot reflow — an A4 sheet is
210mm because that is what leaves the printer. They now scroll in their own lane. The `min-w-0`
is the load-bearing half; without it the overflow just moves up to the document.

Two findings recorded and deliberately not fixed: the `/sales` status chips are 28px, which is
the dense step the button ladder reserves for chips, and `/sales/invoices/{id}/print/a4`
overflows by 11px at 375 — pre-existing, on a page no Phase-0 PR touched, and print has its own
phase. `TabsList` at 25px is the same class and goes with the touch-floor sweep.

**A note on RTL debugging:** horizontal overflow in this app escapes to the *left*. An
overflow-hunting script that looks for elements past the right edge finds nothing and reports
a clean page while the document is 225px too wide.

Five PRs, all five checks green on each: `#64`, `#65`, `#66`, `#67`, `#68`.

---

## 2026-08-31 — UI redesign, Phase 1: complete the design system, and find what it never compiled

Four PRs. The plan called this "design-system completion" and expected additive token work.
Most of it turned out to be finding things that had never worked.

**The toast surface was wired to nothing.** `app-shell.tsx` imported `Toaster` from `sonner`
rather than from `@/components/ui/sonner`, so the project's own wrapper — lucide icons, the
`--popover`/`--border`/`--radius` bindings, the theme — had **zero consumers anywhere**. Dead
code that read like the mechanism, sitting beside the raw component that actually rendered.
And the raw one defaults to `theme="system"`, so every toast followed the operating system.
The wrapper would not have saved it either: it called `useTheme()` from `next-themes` with no
provider mounted, which returns an object with no `theme` key, so its `= 'system'` default won
every render and arrived in the same place.

The cure is one authority, not a second provider. `next-themes` is gone; `hooks/use-theme.ts`
observes the `dark` class on `<html>` — the thing the pre-paint script already sets — with a
`MutationObserver`, so whoever changes it is seen. Verified across the full OS × app matrix,
including both crossed cases, which are the only two that could ever have shown the bug.

**`z-sticky` has never generated a rule.** Tailwind v4 builds `z-` utilities from the
`--z-index-*` namespace; the tokens were declared `--z-sticky`. So the sticky header has
shipped with `z-index: auto` since the day it was written. Found by grepping the built
stylesheet for each class after `shadow-mid` compiled and the `z-` ones did not — the general
rule, now in both documents: **a defined token is not evidence of a generated class.**

**The overlays ran a second elevation ramp** — Tailwind's `shadow-md`/`shadow-lg` plus a
`ring-1` for an edge, beside the token ramp that describes two steps. `--shadow-mid` is the
missing one. The rings went with it, checked at pixel level in both themes.

**`Card` landed with consumers, deliberately.** 141 sites, twenty-five spellings of the same
box. `SettingsSection`, `StatCard` and the treasury `AccountCard` migrated in the same change
— which is what proved the API, and what stops it becoming another `--spacing-section`
(defined in this file, used by nothing, and the argument against every speculative token).
Verified to move no pixel: 23 card surfaces across four pages, measured before and after.

**Three plan items were dropped after reading the code.** The plan wanted
`--color-money-positive/negative/neutral` to resolve "four contradictory sign conventions".
They are not contradictory — they answer three different questions, and `Timeline` maps a
positive amount to `warning` because a positive amount is a *debit*. A single sign-to-colour
token would have made it wrong. Only the naming drift was real (`Money` said `destructive`
where everything else says `danger`). Rhythm and type-scale tokens were dropped for the
`--spacing-section` reason. Font preload was deferred to the performance phase, where an LCP
measurement can say which of 24 hashed faces are worth preloading.

**A judgement worth recording.** `Card`'s `lg` padding was briefly `sm:p-8`, collapsing a 4px
split. `SettingsSection` is `sm:p-7` with nine consumers, so "removing drift" meant repainting
nine pages in a phase whose whole point is that pages are not being touched. Reverted to match
what exists. A shared component that silently restyles the app is worse than two padding
scales.

Four PRs, all five checks green on each: `#70`, `#71`, `#72`, `#73`.

---

## 2026-08-31 — UI redesign, Phase 2: the shell, and three controls that did nothing

Four PRs. The phase was planned as "AppShell & navigation" and was mostly a matter of making
controls that already looked finished actually work.

**Two nav items had 404'd for as long as they existed.** «تنظیمات» pointed at `/settings`,
whose module routes file held nothing but a comment block; «اقساط» pointed at `/installments`,
where the module registers `/collections` and `/plans/{plan}` and nothing at the root. Every
user, on every page, had two sidebar items that failed.

The settings screens all existed — users, two-factor, sessions, activity, branches, billing —
scattered across four modules with no door. `SettingsCatalogue` follows `ReportCatalogue`:
grouped the way a shopkeeper files them, a description under each title, no rows for things
you cannot open, and permissions checked by *name* so the module does not import Inventory's
`Branch` to ask about a string.

The guard that would have caught it reads `navigation.ts` **from PHP** and walks every href.
Parsing TypeScript in a test is ugly; the alternative is a second copy of the nav in PHP,
which is a thing to keep in sync rather than a thing that checks. Verified by pointing «اقساط»
back at the broken URL and watching it fail.

**There was no logout.** `POST /logout` has existed since authentication did and nothing in
the interface pointed at it — a shopkeeper could sign in and not sign out, on what is often a
shared counter tablet. `UserMenu` also answers the question a shared device raises: which of
the staff is signed in. Hence a letter, not a person glyph.

**The search box had no `onClick`.** The most prominent control in the product, on every
screen, was decoration — with a placeholder promising products and invoice numbers. It now
opens a palette over the endpoints the pickers already use. The placeholder was *narrowed* to
what it actually does: the only general product search renders a barcode per row for the label
sheet, and nothing indexes invoice numbers. Promising two things it could not do is how the
previous version got away with doing nothing.

The browser found two defects reading would not have: it fired both endpoints on an empty
term (31 rows and two requests just to press ⌘K), and Enter did not navigate because nothing
was selected in that unstable list. Both fixed by gating remote search behind two characters.

**Thirteen pages went around the shell's heading.** Five passed no `title` and hand-rolled an
`<h1>` at 40px — the step reserved for a screen's headline figure — and eight rendered a second
one. Both are the same missing slot. `PageHeader` is it, and `AppShell` now takes `title` **or**
`header` as a discriminated union, so passing both is a compile error rather than a review
note. Five pages migrated; two turned a «بازگشت» outline button into a real back link, which
had been making "where I came from" compete with "what I can do here".

**A guard I nearly ignored.** The gallery's three PageHeader specimens put three extra `<h1>`s
on `/design` — the component built to enforce one heading per page breaking it on the surface
that demonstrates it. `/design` is dev-only and it would have been easy to wave through;
waving it through is the first step to ignoring the sweep. Fixed with a documented
`headingLevel` escape hatch scoped to that one caller.

**Also learned:** run `composer stan` locally before pushing PHP. Eleven Larastan errors came
back from CI on a test file, all from `fresh()` returning `static|null` and `inTenantContext()`
returning `mixed`.

Two things recorded and deliberately not fixed: `/installments` still has no plans index (a
page to build, not a nav entry to fix), and `/treasury/accounts/{id}` renders 16×16
reconciliation checkboxes, which belongs with the touch-target sweep.

Four PRs, all five checks green on each: `#75`, `#76`, `#77`, `#78`.

---

## 2026-08-31 — UI redesign, Phase 3: the touch floor, and three components not built

Three PRs, and a decision the owner had to make because the design system contradicted
itself.

**The floor was advisory in a second way.** Phase 0 raised `Button`, `Input` and `Select` to
40px; a scan of twenty-three screens — every interactive element, plus the ones that exist
only while a menu or dialog is open — found eight more kinds of control below it. Dropdown
items at 29px, command-palette items at 33px and its input at 21px inside a 44px box, tabs
triggers at 25px, the dialog close at 28px, `DataTable`'s sort control at 21px, and a report
preset's delete at **14px** — a destructive action two millimetres from the one that applies
the preset.

Two of the fixes are worth remembering as a pattern: the treasury reconciliation checkbox
stays 16px and gained a 40×63 label around it, because a checkbox drawn at 40px reads as a
button; and `InputGroup`'s control now fills its box, because the thing that looked like the
field was mostly not the field.

**The contradiction.** Rule 9 said ≥40px. `button.tsx` said `sm` (28px) is what "a toolbar, a
table row or a filter chip" asks for. Both written down, both cited, and 35 controls sitting
at 28px — mostly status filter chips, which are often the only way to narrow a list. They
clear WCAG 2.5.8 AA (24×24) and not this project's own floor. Put to the owner rather than
resolved unilaterally: **40px wins, the carve-out is deleted**, and `sm`/`xs` survive only for
controls nobody taps. Recorded in `docs/design-system.md`, the `hamyar-ui` skill and the
ladder itself. Two exceptions stay explicit — inline links in prose (WCAG says so), and the
target not needing to be the box.

**`FilterBar`.** Twelve list pages had each written the same four things: a term in state, a
300ms timer, a `visit()` spreading filters over a change, and a row of chips. Already
drifting — some at 300ms, some at 250. It also carries the three each page had skipped: the
filters collapse into a sheet below `md` with a count on the trigger, every chip has
`aria-pressed`, and the result count is `aria-live="polite"`. `/sales` adopted it, which
dropped the sweep's sub-40px findings from 16 to 8.

`withoutEmpty()` ships beside it because Inertia serialises `null` as an empty parameter, so
clearing a filter produced `/sales?q=&status=` — the same list and a worse URL to send
somebody.

**Two components the page resolver could serve.** `schedule-table.tsx` and
`RegisterForm.tsx` are components, and they were inside `pages/`, which `lib/pages.ts` globs
to build the page registry — both were emitted as page chunks. Nothing rendered them as
pages; nothing had to, for a resolver that can name something to be a route somebody
eventually writes.

**`permission` is not `empty`.** Three screens had hand-written "your account does not have
access". `EmptyState` treated it as `empty`, which is a different claim: *nothing is here*
versus *there is something here and it is not yours*. Conflating them is how a shop concludes
their data has gone missing.

**Three planned extractions were not built, on evidence.** `ResponsiveTable`'s table-and-cards
pair exists in one file. `MoneyLadder`'s grid exists in one file, twice. Four tables want a
footer and none of them is a `DataTable` yet. The system's own rule is that a component is
extracted when repetition exists, not when a plan predicted it would — and `--spacing-section`
is still in `app.css`, defined and used by nothing, as the standing argument.

Three PRs, all five checks green on each: `#80`, `#81`, `#82`.

---

## 2026-08-31 — UI redesign, Phase 4: the silent failures, and the gate that counts differently

Two PRs. ROADMAP 12.6a is closed, and the gate it asked for is running.

**Six forms retrofitted, one entry found stale, one worse than recorded.** The list was
written for `0.18.0` and verified adversarially then; code moves. `settings/two-factor` had
since gained both the keys its endpoints produce, so it is ticked as *verified* rather than
re-fixed. `settings/users` was worse than listed: `toggle` is a `useForm({})` driving two
endpoints that refuse things — «نمی‌توانید حساب خودتان را غیرفعال کنید» on `user` and «فروشگاه
باید حداقل یک مالک داشته باشد» on `roles` — and rendered no errors whatsoever.

`Catalog/Products/Edit` had a subtler shape: it rendered `errors.axes`, which matches the
top-level key and none of the nested ones `VariantMatrixRequest` produces. The inline render
had to go rather than stay alongside a `FormErrors`, because the component treats a key as
handled when the form handles any *prefix* of it — listing `axes` would have hidden
`axes.0.name` too.

**`reset-password` was the one worth proving in a browser.** It needs no mistake by anybody: a
reset link that loses its `identifier` renders normally, accepts a new password, and does
nothing. Driven against the real form, it now answers «شمارهٔ موبایل را وارد کنید.» where it
previously answered with silence.

**The gate counts a different thing, and that is the point.** The `0.18.0` audit counted
*proven orphan keys* and found 9 files. `bin/check-form-errors` counts *missing protection*
and finds **37** — a form with no region is one validation rule away from being a form with an
orphan, so the second number is the one that predicts the tenth occurrence.

Failing the build on 37 would have meant a change nobody could review, or a gate switched off
on day one — and a gate people switch off also teaches them the rule is optional. So it ships
as a ratchet: `bin/.form-errors-baseline` lists what was already unprotected, anything not on
it fails immediately, **and a listed file that gains a region also fails**, with an instruction
to delete the line. A baseline that only grows is a list nobody empties. Both directions were
verified by breaking them.

`bin/check-apex-domain` joined `composer guards` in the same change — it had been running in
CI and in no composer script since it was written, so `composer test` never ran it locally.
Eight guards now.

**Validation messages count in Persian.** Twenty messages in `lang/fa/validation.php`
interpolate `:min`/`:max`/`:size` and Laravel substituted Latin digits into Persian prose — in
the most frequent interaction the product has. The fix converts the substituted *parameters*
of counting rules, not the finished message, because `hex_color`'s message carries `#1A2B3C`
as an example of the format and «#۱A۲B۳C» is not a thing anybody can type. Tested in both
directions.

**A process note:** running `composer stan` locally before pushing PHP would have saved two CI
round trips this phase — one on a test file's `fresh()` returning `static|null`, one on
narrowing an inherited method's visibility.

Two PRs, all five checks green on each: `#84`, `#85`.

---

## 2026-08-31 — UI redesign, Phase 5: the dashboard, under a hard constraint

One PR, and the only phase so far with a number it was not allowed to move.

`/dashboard` already fails its performance budget **at zero concurrency** — 1.3s warm against
a 1000ms threshold (`docs/load-tests/2026-08-20.md`, §7). So the redesign was allowed no new
queries and no new client work, and the honest proof of that is the diff: one `.tsx` file,
zero PHP. The query guard (`< 60`) and the 300ms report-latency budget both still pass.

It ended up *removing* client work. The page carried a local `count()` helper converting
digits by hand because `StatCard`'s hint is a string; with the tiles gone every number goes
through `<Num>`, which reads the tenant's digit setting itself, so the helper,
`useTenantSettings` and `toPersianDigits` all left the file.

**The composition problem.** Nine boxes, all the same size: four `StatCard`s, a chart, and
five more cards. Two of the four tiles were not about today at all, so "how did we do today"
and "what is late" were interleaved and neither read first.

Now two bands. Takings are the anchor — one figure at 40px, with the invoice count and
today's profit under it as facts *about* it rather than beside it as rivals — and the
thirty-day chart sits in the same band, because a day's takings only mean something against
the month they sit in. Then everything late in one ranked list.

That list is **ordered, not sorted**. Two of its four rows have no amount: an uncollected
device and an out-of-stock line are counts, and ranking a count against a sum is arithmetic
dressed as priority. The order is stated instead — money late outranks goods stuck, because
money late may never arrive.

**Two defects the browser found and reading would not have.** The chart had two titles: the
heading above it and `BarChart`'s own readout row said almost the same words, at the same
size, one directly over the other. And the anchor was losing to the chart — at
`minmax(0,20rem)` the figure sat in a narrow column of whitespace while the thirty-day total
read as the louder number.

**One thing worth watching:** on a day with no sales the anchor is a lone «۰», which reads
thin. It is honest, the supporting line says «هنوز فاکتوری ثبت نشده است», and the design
system's own position is that zero keeps full ink weight rather than being muted into
something that looks like a load failure. Worth re-checking against a shop with real
same-day traffic.

One PR, all five checks green: `#87`.

---

## 2026-08-31 — UI redesign, Phase 6 (part): the financial family, and two deferrals that paid

Two PRs. The family's four raw tables are gone and the two components Phase 3 declined to
build got their second consumer one phase later, which is the outcome that argument was
betting on.

**The alignment was wrong on the screens that can least afford it.** The day-close, the
account statement and the cheque list all set their money columns `text-end` — physical
*left* under `dir="rtl"`, which lines up the most-significant digits and leaves the units
ragged. A day-close is a shop reading a column against a bank's; a cheque list is a shop
looking for the row that matches a number on a piece of paper. `DataTable`'s `numeric` flag
carries the fix and the reasoning; these three were hand-rolled and never got it.

**`DataTable.footer` and `MoneyLadder` were built here rather than in Phase 3**, and the
reason is worth keeping: in Phase 3 each had exactly one candidate, and the system's rule is
that extraction follows repetition rather than a plan. The day-close supplied the second of
each — a totals row, and a profit-and-loss block that was `flex justify-between`, which is
the 99px-of-scatter defect measured on the invoice summary, on the one screen whose entire
job is arithmetic somebody checks by eye. `invoice/summary.tsx` moved onto the shared ladder
in the same change, so it is an extraction and not a copy.

`footer` renders **per column** rather than taking a `ReactNode`, so «جمع» cannot drift out
from under the figures it totals when somebody reorders the headings. Unreconciled gets no
total: a sum of per-account exposure would read as a figure the shop owes somebody.

**Two things the browser found.** Putting `withUnit` on a ladder rung pushed the page 13px
sideways at 375 — «۸٬۶۶۸٬۰۰۰ تومان» is 98px in a fixed `9ch` track, which is why the invoice
ladder keeps its unit outside. And two cards side by side were counting in different digit
systems: the P&L ladder rendered `12,000` while the expense breakdown beside it rendered
«۱۲٬۰۰۰», because one used the tenant's prose setting and the other the Latin tabular figures
rule 4 gives columns.

**The last native `<select>` is gone.** `h-8`, under the floor, and rendering the platform's
own dropdown — which on Android ignores the app's theme entirely: a white system list over a
black page.

**The gate caught me.** Adding error regions to Cheques and Collections made
`bin/check-form-errors` fail — not on a new violation but on its other direction: two files
gained a region while still listed as unprotected, and it refused the build until the lines
were deleted. That is the half of the ratchet that stops a baseline becoming a list nobody
empties, and it fired on my own work two phases after I wrote it. **37 → 35.**

Cheques also rendered **one error key out of seven**: `ChequeController::act()` validates
seven and the page placed `cheque`, so a row action refused on any of the others was a 302
and a row that did not move — on a screen whose buttons are «به بانک» and «برگشت خورد».

Still open in this family: billing index and receipt (the receipt bypasses `PrintLayout`),
and the installment plan screens.

Two PRs, all five checks green on each: `#89`, `#90`.

---

## 2026-08-31 — UI redesign, Phase 6 (rest): billing, and a page I judged by reading

One PR, and the clearest reminder yet that reading a file is not looking at a screen.

**The receipt was the last printable surface outside `PrintLayout`.** It hand-rolled
`print:hidden` and a bare `window.print()`, so it had no `@page` — it printed on whatever
paper the browser defaulted to — and no light island. `app.css` restores every semantic token
to its `-on-light` step inside `[data-paper]` because a sheet is white paper inside a
possibly-dark document; without it, a shop working in dark mode got a receipt whose
«تسویه‌شده» badge was `#4CC47F` on white — 2.2:1, effectively invisible — on the one page they
keep as proof of payment. Measured after, in both themes: sheet `rgb(255,255,255)`, badge
`rgb(15,123,63)`.

**I said the billing index was sound, from reading it.** The table *was* sound: its money
column has no alignment class, which under `dir="rtl"` resolves to `start` — physical right —
so the units already line up. The pricing grid beside it overflowed the page at every width
looked at: 392px in 375, 868 in 768, 1305 in 1280.

Three compounding causes, none visible in the source without measuring:

1. No `min-w-0` on the cards. A grid track is `minmax(auto, max-content)` and `auto`'s floor
   is *min-content*, so a card that would not compress below 328px made its track 376px wide
   inside a 375px viewport.
2. The price was `text-3xl` — 56px, the hero step, on one of three cards in a row.
   «۱٬۱۹۰٬۰۰۰ تومان» came to 326px in a 218px track.
3. The grid went three-up at `md`.

**The third is the trap the treasury summary already wrote down, and it has now bitten
twice:** the sidebar appears at `lg`, so the content column is *narrower* at 1024 than at 768.
Three tracks at `lg` are 208px. The rule that generalises — **a multi-column band inside the
shell splits at `xl`, not `lg`** — is worth treating as a standing rule rather than a note on
one component.

**The lesson to keep:** a page that reads correctly can still be broken, and the only way to
know is to put a number on it. Every phase so far has found something this way; this is the
first where I had already announced the opposite.

Also fabricated a subscription invoice to view the receipt at all — the demo tenant has none —
got the `lines` JSON shape wrong, hit a real domain invariant («Amount 1 rial is not a whole
number of toman»), fixed the fixture rather than the code, and deleted the row afterwards.

One PR, all five checks green: `#93`.

---

## 2026-08-31 — UI redesign, Phase 7 (POS): the screen nothing was watching

One PR. The work I planned was alignment and touch targets; the thing that mattered was an
overflow nobody had ever looked for.

**The till scrolled sideways on a phone.** Its three actions sat in a `flex items-center
gap-2` with no `flex-wrap` and came to 411px inside a 375px viewport. `AppShell` carries a
comment about exactly this, written after the same defect on the products list — the shell's
row wraps, so a group inside it that does not wrap runs off the edge — and the POS
re-created it.

Nothing caught it because **nothing was looking**. `/sales/pos` is the highest-traffic screen
in the product and it was not in the browser suite, which Phase 0 recorded as a gap and did
not close. It is in now, and the case was verified by reverting the fix and watching it fail:
«[/sales/pos] scrolls sideways on mobile in light mode: 473px of content in a 375px
viewport».

That is the second time this phase-by-phase pattern has held: **the defect is never in the
part being redesigned.** The billing overflow was in the pricing grid beside the table I had
judged; this one was in the header above the cart I came to fix.

The planned work landed too — three `h-9` fields raised to the floor (the ones somebody
changes with a customer standing there), the cart's «جمع» column off `text-end`, and both
money panels onto `MoneyLadder`. Measured: digits land on one axis per panel, where «۰» and
«۱۰۰٬۰۰۰٬۰۰۰» had sat 73px apart on the panel a cashier reads while counting change.

**A regression I made and caught in the same session:** laddering the totals cost «مبلغ قابل
پرداخت» its «تومان», which is the one figure on that screen somebody says out loud. Restored
with `unitPlacement="block"` — a nine-digit figure plus its unit will not fit a `9ch` track
inline and does fit on its own line.

**`data-density="compact"` was dropped from the plan.** It shortens `--density-row` to 36px,
and the design system already says 36px rows are "for scanning, never for touch targets" —
but these rows *contain* the 40px controls this change just raised. The plan item predates
the floor decision and the two cannot both hold.

Still open in Phase 7: Repairs intake, deliver, board and ticket detail, and Returns/Create.

One PR, all five checks green: `#95`.

## 1404-06-10 (2026-09-01) — Returns, and the two repairs screens with money on them

**The return form 500'd on ordinary data (`#97`).** `/sales/invoices/{id}/returns/create`
divided a line's total by its quantity in rial and handed the result to `Money::toArray()`,
which refuses a figure that is not a whole toman rather than silently rounding money. A
line of two at 10,652,010 rial — a perfectly ordinary discounted line — divides to
5,326,005, and the whole screen died. Every existing test missed it because every fixture
divided cleanly; the new one does not, and it was proved by reverting the fix and watching
it fail with the exact production exception.

The per-unit share **ceils**. ADR 0009's amendment sets the direction and I had it backwards
in my own question first: rounding goes the way that does not flatter the party doing it, so
VAT floors because the shop charges it and a refund ceils because the shop pays it. Ceiling
alone would over-refund a whole line, so a whole line is priced at its exact `line_total`
and only a partial return uses the rounded unit.

**The two repairs money screens (`#98`).** The demo tenant had **zero repair tickets**, so
`/repairs` had only ever been seen empty and `/repairs/tickets/{id}/deliver` had never been
rendered at all. Seeding one through the app's own services — intake, a consumed part, an
approved quote, a prepayment — turned up three defects that only exist with data:

- **`approved_amount` was on the wire and rendered nowhere.** The entire quote-approval
  flow exists so a shop does not bill past what the customer agreed to, and Deliver is
  where the bill is decided. The check was living in somebody's memory. It now names the
  figure and warns when the bill passes it — a warning, not a block, because a customer can
  approve more by phone and a block would strand a repaired device behind a screen.
- **The submit button sat 144px to the side of its own form.** `fixed inset-x-0` centres on
  the viewport; the form centres in the content column beside a 288px sidebar. Measured, not
  guessed. `sticky` inside the form aligns by construction.
- **The settlement never showed the sum of the bill, nor the payments being entered** — so
  typing a payment made the amount due drop with no row accounting for it.

Ticket detail's checklist was a `<table>` with no `<thead>`: three unlabelled cells per row,
on the record that settles «صفحه از قبل شکسته بود» three weeks later.

**My own regression test was flaky, and the reason is a real finding.** It asserted on
`items.1.unit_refund.value`. `SalesInvoice::items()` is a plain `hasMany` with **no
`orderBy`**, so the order lines come back in is whatever Postgres finds convenient — and
rewriting a row, which the test's own `forceFill` does, is exactly what moves one. It
passed locally and on its own PR, then failed in CI against the phone at 60,000,000 rial.
The test now finds its line by quantity and never by index.

The underlying looseness is left alone deliberately: **invoice line order is undefined
across the whole Sales module**, so lines can appear in a different order between two views
of the same invoice, print included. Adding `->orderBy('id')` would fix it in one line, and
it would also change the line order of every invoice already printed. That is a product
decision, not a redesign one — flagged for the owner, not taken.

**A note worth keeping: `composer` is not installed on this laptop**, so `composer guards`
had been exiting "command not found" with empty output, which reads exactly like a pass. The
guards do run in CI on every PR, so nothing merged unchecked — but locally they must be run
as `php bin/<guard>`.

The recurring lesson, a fourth time: **read is not the same as looked at**, and the defect is
never in the part being redesigned. I came to Deliver for its money rows and found the
primary action in the wrong place and the approved figure missing entirely.

Still open in Phase 7: Repairs board, index and intake — the second repairs PR.

Two PRs, all five checks green: `#97`, `#98`.

## 1404-06-10 (2026-09-01) — Phase 7 closes: the board, the queue and intake

**The board's whole purpose was mouse-only (`#100`).** Moving a card was HTML5
drag-and-drop and nothing else. That does not fire on touch, and `draggable` has no
keyboard equivalent — so on the tablet at the bench, the device a kanban board is most
likely to be read on, every card was a link to somewhere else and nothing on the screen
could move one. The board rendered its entire reason for existing as decoration.

Each card now carries a menu of the moves its column allows, fed by the same `allows` array
the drop targets read and filtered to the columns on screen. From «آماده تحویل» it offers
«در حال تعمیر» and **not** «تحویل‌شده» — delivery writes a `SalesInvoice` and belongs to
the delivery form, so the board must not be a shortcut past it. Driven keyboard-only to
prove the card actually changes column.

**The queue's eleven filter controls were 28px.** `FilterBar` puts them at the floor, sheets
them below `md`, and announces the result count. The hand-rolled card list becomes a
`DataTable`, because the queue is scanned down a column. Lateness is said in words as well
as colour.

**Intake's error region was a hand-rolled `<FormErrors>`** that printed every key including
`quota`, which `AppShell` was already rendering through `<QuotaBlock>` with an upgrade
button — so a shop at its monthly limit read the refusal twice, the second time as a bare
sentence with nothing to do about it. Checked first that the shell renders `QuotaBlock`
unconditionally: without that, swapping to the shared component would have *created* a
silent failure on a metered path rather than removing a duplicated one. Intake's own layout
reasoning — one column, tap targets, nothing pre-answered — was right and is untouched.

**A four-digit security sentinel collided with random data.** `PasscodeSecurityTest` greps
the whole rendered payload for the device unlock code, and the sentinel was `'4517'`. Every
page carries `auth.user.mobile`, eleven random digits, which contains `4517` in 0.067% of
runs. It failed CI on a PR of three React files, which cannot reach `viewData('page')` at
all.

The instinct in that moment is to re-run it, and **that instinct is the real damage** — a
security assertion that cries wolf trains people to wave the third amber through. Fixed by
making the sentinel unmistakable, and verified the only way that counts: by planting a real
leak in the controller and watching the test fail on it. In `docs/lessons.md`.

Worth naming: two of this session's four CI reds were flaky tests rather than broken code,
and one of those was mine. Chasing each to its actual cause rather than re-running cost
maybe an hour and removed two future ambers.

Phase 7 is closed. Every repairs screen and the POS sweep clean at 375/768/1280/1440 in
both themes: no overflow, one `<h1>`, no control under 40px, no console errors — 48 cases
across six screens.

Two PRs, all five checks green: `#100`, and the sentinel fix inside it.

## 1404-06-11 (2026-09-01) — Phase 8: the register family

**The registers were empty, and an empty table hides everything.** One invoice, no quotes,
five products. Before judging any of these screens I seeded twelve invoices of deliberately
mixed magnitude and three quotes through the real `InvoiceTotals`/`FinaliseInvoice` path,
thirty parties, and long real Persian product names — the sixty-character kind a shop
actually types.

With that data the wide sweep came back **104 cases and one finding**, which says Phases
0–3 did their job. But neither money defect below is visible on a one-row table, and both
were sitting on the busiest screens in the product.

**The money columns were aligned on the wrong edge (`#102`).** The sales book set
`text-end` on «مبلغ» and «باقی‌مانده». In RTL that resolves to physical **left**: it lines
up the most-significant digits of a Latin numeral and leaves the units ragged. Measured
across twelve rows at 1440 — **28px of spread and four distinct right edges on the total,
61px and four on the outstanding**. After `DataTable`'s `numeric`: one edge, 0px. Same
defect and same fix on the SMS cost column.

**Three actions could fail with nothing on screen**, each on something that matters:
converting a quote into an invoice (`ValidationException::withMessages(['quote' => …])`, a
key with no field), resending a document to سازمان امور مالیاتی, and toggling or deleting a
follow-up. All three came back as redirects that re-rendered an identical page. Each was
proved by planting the refusal and watching the message appear where nothing appeared
before. Baseline 32 to 29.

**The follow-up desk deleted a reminder on one click.** No confirmation, on an irreversible
action whose button sits one row from «انجام شد», both icon-only. Six other pages already
use `ConfirmDialog`; the desk was the outlier.

**The Hamta list is capped at 200 rows and said nothing** — «تمام شد» and «تا اینجا نشان
دادیم» looked identical on the screen whose job is proving a compliance backlog is clear.

Two things I declined to make consistent, both for the same reason — the abstraction would
have made the screen worse:

- **Moadian keeps its own status labels.** `StatusBadge`'s map is one flat key space shared
  by every module, where `rejected` means «مرجوع بدون تعمیر» and `pending` means «در انتظار
  پرداخت». Adopting it would print the wrong Persian on a tax screen.
- **The follow-up desk stays a list, not a `DataTable`.** A follow-up is a task, not a
  record: one line of subject, a date read as "late or not", two actions. Five columns
  would spread what is really one sentence and two buttons.

**A correction to my own tooling, and to earlier claims.** The SMS filter chips were 28px
and my sweep had never seen them: they are `Button asChild`, which renders an `<a>`, and the
selector only matched `button` elements. **Anchor-buttons had never been measured anywhere
in this programme.** The selector now includes `[data-slot="button"]`; re-running it over
every screen already reported clean found Messaging to be the only one affected, so the
earlier results stand — but they were narrower than I said they were.

Two PRs, all five checks green: `#102`, `#103`.

## 1404-06-11 (2026-09-01) — Phase 9: the editor family, and the checkbox nobody had built

**There was no `Checkbox` component (`#106`, `#107`).** Fourteen places wrote the same six
lines instead, across Storefront, POS, trade-in, returns, products, labels, parties,
branches, stock counts and the treasury statement. They had already drifted — `gap-2` and
`gap-3`, three different `items-*`, four wrapped in a `min-h-11` label reaching for the
touch floor by hand and ten not.

Underneath the duplication was the defect: `size-4` is a **16px target**, a quarter of what
rule 9 requires, on the control that says a returned handset may go back on the shelf. And
`accent-primary` cannot be themed past the browser's own rendering, so these were the one
control in the product that ignored the token layer — visibly so in dark mode.

The fix is not a bigger box. **The label is the target**: the row is `min-h-10` and all of
it activates the control while the box stays a 20px mark. That is why `label` is a prop
rather than something callers compose. The unlabelled form carries a transparent `::before`
for its 40×40 area.

Two things worth keeping from building it:

- Putting a `<button role="checkbox">` inside a `<label>` risks a **double toggle**. It does
  not here, and that was measured rather than assumed — along with label-click, box-click,
  Space, and `disabled`.
- **My first hit-area test said it was broken. The test was wrong**, not the component: it
  clicked viewport coordinates for an element below the fold, so `elementFromPoint` returned
  null everywhere including the box's own centre. The instinct in that moment is to go and
  fix a component with nothing wrong with it.

The `::before` computed 38px on the first attempt — under the floor it exists to clear — so
the inset is `-inset-[11px]` and it now computes exactly 40. Ten `size="sm"` controls in
shared domain components went to the floor with it: both picker retry buttons, the history
link, three in report presets, the date picker's month navigation and its day cells.

**Four silent failures on things that move money or stock**, one per screen: assigning
staff to a branch (`#105`), revoking a reseller price-list link (`#107`), deleting a
purchase invoice line, and every one of the purchase editor's four forms (`#108`). Two of
them also deleted on a single click, one of them on a URL the screen itself describes as
unrecoverable.

**The two smallest targets in the product**, both found by measuring rather than looking:
the axis-value X on the products editor at **12×12px**, and forty 28px controls on one
branches page. The label sheet also pushed itself 180px off the side of a phone — the grid
track floor again, where a grid item's `auto` minimum resolves to min-content and an
unbreakable row refuses to shrink.

**Storefront had zero domain components when this programme started.** It now has a single
heading, a `DataTable`, `FormErrors`, a confirmed revoke, and the last hand-written native
`<select>` in the tenant app is gone. That correction cascaded: a comment in the
installments screen still claimed two remained, true when written and not any more.

Form-errors baseline: 29 to 26.

Four PRs, all five checks green: `#105`, `#106`, `#107`, `#108`.

**Left open deliberately:** splitting `Purchasing/Invoices/Edit.tsx` (878 lines) into
module-local components. The maintenance argument is real, but it is a pure refactor over
IMEI capture and goods receiving — the two paths in the module most expensive to get wrong —
and it deserves its own PR and verification rather than riding on a defect fix.

## 1404-06-12 (2026-09-02) — Phase 10: the passports, and every back arrow in the product

**Every «بازگشت» arrow pointed the wrong way (`#110`).** Thirteen files wrote
`<ArrowRightIcon className="size-4 rtl:rotate-180" />`. In an RTL page a back link points
toward the reading *start* — physical right — and `ArrowRightIcon` already points there.
The variant turns it around, so the arrow on every back link in the product pointed away
from where the link went. `PageHeader` had it right all along.

It survived thirteen reviews **because it reads as careful RTL work**. Mirroring an icon is
usually right, and the identical class two files away in `Pagination` is correct there.
Same class, opposite reasoning, one of them a bug. That is now the ninth guard,
`bin/check-rtl-arrows`, which deliberately says nothing about chevrons.

**The first measurement said there was no bug.** `getComputedStyle(svg).transform` returned
`none` everywhere, including on files carrying the class — because Tailwind v4 compiles
`rotate-180` to the standalone `rotate` property. Reading the generated CSS is what turned
"the variant does nothing" into "the variant does exactly what it says". Second time this
session a probe reported working code broken; both are in `docs/lessons.md`.

**The IMEI passport lost half its width when a sidebar appeared (`#112`).** Its timeline ran
704px at 768 and **328px at 1024** — the same content, more than halved by moving to a wider
screen, because the shell's sidebar and the page's own split both land at `lg`. Third
occurrence of that trap, and the first where nothing overflows: it just collapses, which is
quieter and worse. The design system's rule now carries all three measurements and names the
two failure modes apart.

All four passports gained a `PageHeader`, which puts «بازگشت» where it belongs — it had been
sitting among the page actions, as though returning to the list were something you can do to
the record.

**Two corrections to my own work**, both caught before acting:

- An earlier structural audit of mine reported eight raw `<table>`s across the passports.
  There are none — I had misread my own column alignment, and the number I read as "tables"
  was the `lg:grid` count, which turned out to be the actual finding.
- A `PageHeader` title typed as `string` tempted an `as unknown as string` cast. The
  constraint is deliberate; the title became a plain string instead.

**Unrelated and urgent (`#111`).** `composer audit` began failing every PR — including one
that changes only TSX — on two `league/commonmark` advisories published that afternoon, one
high severity. Transitive through `laravel/framework`, so a targeted update moved 2.9.0 to
2.10.0 with nothing else in the lock file. Shipped on its own so the unblocking was
separable from the queue behind it.

Three PRs, all five checks green: `#110`, `#111`, `#112`.
