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
