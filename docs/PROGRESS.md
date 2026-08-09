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
