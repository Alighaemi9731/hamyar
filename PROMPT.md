# MASTER PROMPT — paste this into Claude Code, launched inside this pre-seeded project folder

This folder already contains: CLAUDE.md, .claude/skills/hamyar-ui/, .gitignore,
PROMPT.md (this file), START-HERE-FA.md, and docs/ with two Persian reference docs
(01-master-plan-fa.md = business/feature/architecture plan, 03-design-and-claude-setup-fa.md
= design brief & tooling). Treat these as project assets: never delete or overwrite them.

You are the lead engineer building **Hamyar**: a production-grade, multi-tenant SaaS
for mobile-phone shops in Iran (Persian UI, RTL, Jalali calendar). Thousands of shops
will subscribe to plans and use modular features. You will build it phase by phase,
test everything, and keep the project self-documenting. CLAUDE.md in this repo is your
constitution — read it now and obey it in every session.

## Mission & non-negotiables
- Build a modular monolith on Laravel 12 + PostgreSQL 16 + Inertia(React+TS) exactly
  as specified in CLAUDE.md. Do not introduce microservices, MongoDB, or a separate
  API-only frontend. Do not swap stack pieces without writing an ADR and asking me.
- Quality gates: `composer test` (Pint + Larastan lvl 8 + Pest) must be green before
  any task is checked off. Every tenant-scoped endpoint gets a cross-tenant isolation test.
- You own the docs: keep `docs/ROADMAP.md` checkboxes, `docs/PROGRESS.md` log,
  and `docs/adr/` current. Future sessions start by reading ROADMAP, not by asking me.
- Ask me only at DECISION GATES (marked below) or when truly blocked. Otherwise decide,
  record the decision, and move.

## Session protocol (every session, including this one)
1. Read CLAUDE.md and `docs/ROADMAP.md`. 2. Take the next `[ ]` task. 3. Think/plan,
then implement in small conventional commits. 4. Run `composer test`; fix until green.
5. Tick the box, one-line entry in PROGRESS.md. 6. Continue until the phase's
Definition of Done, then present a short demo summary + how I can verify manually.

## First actions right now (Phase 0 kickoff)
0. This directory is NOT empty. Scaffold Laravel 12 into it without touching the
   pre-seeded files: `composer create-project laravel/laravel _tmp` (or the laravel
   installer) in a temp subfolder, move its contents up (merge .gitignore lines into
   the existing one rather than replacing it), delete `_tmp`, verify CLAUDE.md,
   .claude/, docs/, PROMPT.md, START-HERE-FA.md are intact. Then `git init` + first
   commit. Keep `.claude/skills/` tracked in git; ignore `.claude/settings.local.json`.
1. Extend `docs/` with: `ROADMAP.md` (convert ALL phases below into checkbox tasks,
   keeping phase order and DECISION GATES), `PROGRESS.md`, `architecture.md`,
   `testing.md`, `deploy.md`, `adr/0001-stack.md`, `adr/0002-single-db-tenancy-rls.md`,
   `adr/0003-modular-monolith.md`, `design-system.md` (port it in English from the
   tokens/rules in .claude/skills/hamyar-ui/SKILL.md plus sections 1–2 of
   docs/03-design-and-claude-setup-fa.md), and `specs/` — one spec file per module, populated
   from the "Module functional specs" section of this prompt (translate/expand freely;
   specs are the source of truth for acceptance tests).
2. Then execute Phase 0 tasks below.

---

# PHASES

## Phase 0 — Foundation & Developer Experience
Tasks:
- Init git repo, Laravel 12 skeleton, strict config (fa locale, Asia/Tehran display TZ,
  UTC storage), .editorconfig, .env.example fully documented.
- Docker Compose dev stack: php-fpm app, nginx, postgres:16, redis:7, minio, mailpit.
  `make up|down|fresh|test|sh` targets. App reachable at `http://app.localhost`,
  wildcard tenants at `http://*.app.localhost` (document /etc/hosts or dnsmasq note).
- Frontend: Inertia v2 + React + TypeScript + Vite + Tailwind (RTL base layout,
  Vazirmatn font, dark-mode-ready tokens). Shared Inertia props: auth user, tenant,
  enabled features, flash. Jalali date lib + `<JDatePicker/>` and `<Money/>` components.
- Quality: Pest v4 (+ arch plugin), Larastan lvl 8, Pint, GitHub Actions CI
  (lint → stan → tests with real postgres → build). Pre-commit hook optional.
- `php artisan make:module <Name>` generator creating the module folder layout
  (Providers, Http, Models, Services, Events, Policies, database/migrations, tests)
  and auto-registration. Create empty module shells listed in CLAUDE.md rule 6.
- Design system bootstrap: define tokens from the hamyar-ui skill in
  `resources/css/app.css` via Tailwind v4 `@theme`; init shadcn/ui with
  `"rtl": true` in components.json (CLI then emits logical classes; run
  `npx shadcn migrate rtl` on any pasted/legacy components); install the base kit
  (Button, Input, Select, Dialog, Sheet, Tabs, Table, Sonner, Command, Badge,
  Tooltip, DropdownMenu, Popover, Skeleton); pass `dir="rtl"` to all Radix portals.
  Build first domain components: Money, Num, JDatePicker, StatusBadge, EmptyState.
- `/design` gallery route (dev-only): every base + domain component with its state
  matrix (default/hover/focus/disabled/loading/error, light+dark, 390px/1280px).
  Rule going forward: components land in the gallery before feature pages.
- AI tooling: `composer require laravel/boost --dev && php artisan boost:install`;
  merge Boost's generated guidelines BELOW our golden rules in CLAUDE.md (review
  the diff). If MCP isn't auto-registered (Docker), run:
  `claude mcp add -s local -t stdio laravel-boost docker compose exec -T app php artisan boost:mcp`.
  Register Playwright MCP for visual verification:
  `claude mcp add playwright -- npx -y @playwright/mcp@latest`.
  Confirm `.claude/skills/hamyar-ui/SKILL.md` is present and loading.
- Base layout pages: login placeholder, app shell (sidebar fa-IR, topbar, toasts)
  built strictly from the gallery components.
DoD: `make up` then `make fresh` gives a booting RTL app; CI green on a PR; arch test
asserts modules exist and Domain layer does not depend on Http; `/design` renders the
initial kit; Boost MCP answers an Application Info call; zero physical direction
classes in the codebase (grep check wired into `composer test`).

## Phase 1 — Tenancy, Identity, Onboarding  ★ security-critical
Tasks:
- Central tables: `tenants`, `domains` (subdomain unique), platform admin seed.
- TenantContext: resolve tenant by subdomain (fallback: none → central routes).
  Middleware sets context + `SET LOCAL app.tenant_id` per request/transaction;
  queue jobs serialize tenant id and restore context (test this).
- `BelongsToTenant` trait: global scope + creating-hook fill + `tenant()` relation.
  Artisan check command that fails CI if a tenant-ish table/model lacks the trait/RLS.
- RLS: migration helper `$this->enableRls('table')` adding policy
  `USING (tenant_id = current_setting('app.tenant_id')::bigint)`; applied to users table now,
  and to every future tenant table in its own migration.
- Auth: registration = shop onboarding wizard (shop name → subdomain → owner user →
  demo-data toggle), login per tenant domain, password reset, optional TOTP 2FA,
  session management. spatie/permission with teams=tenant_id; seed roles:
  Owner, Manager, Cashier, Salesperson, Technician, Accountant, Warehousekeeper
  with sensible default permission sets (`module.action` naming).
- User management UI: invite by mobile/email, activate/deactivate, assign roles/branches
  (branch entity arrives Phase 3 — keep pivot ready), activity log wired (spatie).
Tests (minimum): registration flow; login; permission denial matrix; **isolation suite
v1**: two seeded tenants, tenant B requesting tenant A user/resource ids → 403/404;
RLS raw-query test proving leakage impossible even without Eloquent scope; queued job
runs under correct tenant.
DoD: two demo tenants seeded; isolation suite green and wired into `composer test:isolation`.
DECISION GATE 1: show me the onboarding + login flow summary; confirm subdomain scheme
and role list before Phase 2.

## Phase 2 — Plans, Modules, Billing, Super-admin
Tasks:
- `modules` registry (code, name_fa, description, is_addon-able) seeded from CLAUDE.md list.
- `plans` (interval month/quarter/year, price IRR, trial_days), `plan_module` pivot,
  `plan_limits` (users, branches, invoices_per_month, storage_mb, sms_credit_bonus).
- `subscriptions` (tenant, plan, status trialing/active/past_due/canceled, period end),
  add-on purchases table, upgrade/downgrade with proration (document formula in ADR).
- Pennant features `module:<code>` + `limit:<key>` resolved from active subscription;
  `EnsureModuleEnabled` route middleware + `features` shared prop; usage counters
  service with soft-lock behavior when limits exceeded (warn → block create actions).
- Payments: shetabit/multipay Zarinpal driver in sandbox; subscription invoices,
  payment init/callback/verify, idempotent webhook-style verification, receipt page,
  renewal reminders (queued SMS/email stub), grace period logic.
- Filament v4 central panel (platform admins only): CRUD tenants/plans/modules/coupons,
  subscription overview, impersonate-tenant-owner (audited), announcements, MRR/churn
  dashboard widgets, SMS credit packages sale.
Tests: plan purchase happy-path with fake gateway; proration math unit tests; feature
gating (module off → 403 + hidden nav); limit exhaustion behavior; impersonation audit.
DoD: I can buy a plan in sandbox, see features unlock, and manage everything in Filament.
DECISION GATE 2: pricing/limits table + proration rule need my sign-off.

## Phase 3 — Catalog, Inventory, Serialized Units, Purchasing
Tasks:
- Branches & warehouses (per-branch defaults); user↔branch restriction enforcement.
- Categories tree, brands, products (type: standard | serialized), variants
  (color/storage/ram matrix), barcodes; price levels (consumer/reseller/vip) +
  product_prices; bulk price update (percent/amount, filterable).
- `product_units` for serialized stock: imei1, imei2, serial, condition
  (new/used/refurb), grade, cost, status enum (in_stock, reserved, sold, in_repair,
  returned, written_off), acquired_from party, acquired_at, hamta fields, notes,
  media (seller ID scan). Full state-machine with history table; IMEI passport page
  showing complete life story (purchases, sales, repairs, transfers).
- `stock_movements` ledger (in/out/transfer/adjust/count refs); quantity-on-hand =
  SUM query with covering indexes; low-stock threshold + alerts list; dead-stock report base.
- Purchasing: suppliers (parties), purchase invoices with standard lines and/or bulk
  serialized unit intake (paste/scan IMEIs), landed costs allocation into unit cost,
  purchase returns; GRN print.
- Transfers between warehouses/branches with dispatch/receive confirmation;
  stock count sessions (blind count option) generating adjustment movements.
- Label printing: price/barcode labels (single + batch, printable HTML @ label sizes).
Tests: movement-ledger invariants (no negative stock unless allowed flag), unit state
transitions (illegal transitions rejected), IMEI uniqueness per tenant, transfer
two-step flow, price-level resolution, purchasing cost math incl. landed costs.
DoD: I can receive 10 phones by pasting IMEIs, see them in stock, print labels,
transfer 2 to branch B, count stock, and every number reconciles with movements.

## Phase 4 — Parties CRM (Customers & Suppliers)
Tasks:
- Unified `parties` (kind: customer/supplier/colleague/both) with multiple contacts,
  addresses, birthday (Jalali), tags/groups, credit limit, opening balance.
- `ledger_entries` engine (party & account dimensions, debit/credit, polymorphic ref);
  party statement page (فاکتورها/دریافت/پرداخت مانده) with running balance; receive/pay
  from party quick-forms (posts to Treasury accounts — accounts arrive Phase 7;
  create minimal `accounts` table now with one default cash account to keep flow testable).
- 360° timeline component (sales, repairs, sms, payments, notes); follow-up reminders
  (assignee, due date, done); loyalty points table + earn rule stub (configurable later).
- Import customers from Excel with column-mapping wizard + dry-run report.
Tests: ledger math (statement = sum of entries), credit-limit block on over-limit
credit sale flag, import edge cases (dupes by mobile, bad rows report).
DoD: customer page shows true balance & full timeline; import of a 500-row sheet works.

## Phase 5 — Sales, POS, Trade-in, Installment creation  ★ revenue-critical
Tasks:
- Sales invoices: draft→final→(void) lifecycle; scan-first POS screen (barcode/IMEI box
  autofocus), line types: variant qty | serialized unit picker (only in_stock units of
  this branch); per-line discount/warranty months; invoice-level discount, VAT toggle,
  shipping; salesperson field + commission accrual entry.
- Numbering per tenant+branch via `counters` row-lock service.
- Payments split across methods: cash / POS-terminal / card-to-card / cheque(ref, Phase 7
  creates real cheque) / customer-credit; change calculation; partial → invoice unpaid
  balance goes to party ledger automatically.
- Returns (full/partial; serialized return flips unit to returned→in_stock with grade
  re-check), quotes → convert to invoice.
- **Trade-in**: inside POS add "buy customer device" line → mini-intake (model, imei,
  condition, agreed price, ID scan, hamta-transfer checklist ack) → creates purchase
  + product_unit(used) and offsets invoice total.
- **Installment sale**: mark invoice as installment → wizard (down payment, count,
  interval, profit % flat, first due Jalali) → generates `installment_plan`+rows;
  guarantor party optional; contract print.
- Printing: thermal 8cm receipt, A5/A4 official invoice, QR to public invoice view;
  template settings (logo, footer terms).
- Profit engine: serialized = exact unit cost; standard goods = weighted-average cost
  at sale time (store cost snapshot on line). Daily Z-report (cash session close).
Tests: full POS happy path; split payments math incl. change & ledger postings;
serialized double-sell race (two parallel finalize → one wins, other clean error);
trade-in creates unit + correct totals; installment schedule math (rounding rule:
last row absorbs remainder); return restores stock & reverses ledger; numbering has
no gaps/dupes under 50 parallel finalizes.
DoD: end-to-end: buy plan→receive stock→sell phone with trade-in + 3 cheques + installments,
print all papers, and every ledger/stock figure reconciles.
DECISION GATE 3: review invoice print templates + rounding rules with me.

## Phase 6 — Repairs  ★ the flagship module
Tasks:
- Intake: customer, device (brand/model/imei — link to unit if it's one we sold),
  reported issue, configurable checklist (per-tenant template builder), photos,
  passcode/pattern (encrypted at rest, masked in UI, permission-gated reveal),
  accessories list, prepaid amount, estimate; printed receipt with tracking code + QR.
- Status state-machine: queued → diagnosing → awaiting_approval → awaiting_parts →
  repairing → ready → delivered | rejected | abandoned; every transition = event
  (SMS hooks Phase 8), history with actor + note; board (kanban) + list views;
  technician assignment, priority, promised date; per-technician workload screen.
- Estimate & customer approval: amount cap without approval (setting); approval via
  public link or manual "approved by phone" with note.
- Parts consumption from Inventory (reserve → consume on complete; return on cancel);
  labor/services catalog (flash, unlock, FRP, data recovery… priced); outsource job to
  external technician party with cost tracking.
- Delivery: payment settle (reuses Phase 5 payment box), signature pad capture stored
  as image, warranty-on-repair days, delivered receipt print.
- Abandoned flow: configurable N days after ready → flag + escalating SMS steps + status.
- Public tracking page (no login): code → status timeline + shop contact; signed URL.
Tests: every legal/illegal transition; approval cap; parts reserve/consume/rollback;
concurrent technician actions; encrypted passcode never in logs/JSON; public page
leaks nothing tenant-private; abandoned scheduler.
DoD: full repair lifecycle demo on seeded data, board usable, tracking page live.

## Phase 7 — Treasury, Expenses/Incomes, Cheques, Installment collection, Rentals
Tasks:
- Accounts: cash boxes, banks, POS terminals, petty cash; opening balances; transfer
  between accounts; account statement (ledger view); simple reconciliation check-off.
- Expense/Income modules with category trees; recurring templates (rent, salaries);
  **Rental module**: contracts for leasing shop desk/space to others (party, amount,
  period, start/end) → auto-generates periodic income entries + reminder.
- Cheques received/issued: full lifecycle (in_hand → deposited → cleared | bounced |
  spent_to_third_party | returned), due-date reminders list, printable receipt,
  posting rules to ledgers at each stage (document matrix in specs).
- Installment collection desk: due/overdue lists, collect (any payment method),
  late-fee policy, early settlement recalculation, SMS nudges hook.
- Daily close (Z) across accounts; simple P&L (period revenue, COGS from cost
  snapshots, expenses) and cash-flow summary.
Tests: cheque lifecycle postings; installment collection & early-settlement math;
recurring generator idempotency; P&L reconciles against a fully seeded scenario.
DoD: a seeded "one crazy month" scenario reconciles to the rial across all reports.

## Phase 8 — Messaging (SMS) & Notifications
Tasks:
- Driver abstraction + Kavenegar first (pattern/lookup send), then sms.ir stub;
  per-tenant credentials optional vs platform-pool billing by credits; credit wallet,
  price tiers, low-credit alert.
- Template manager with variables ({name},{ticket_code},{amount},{due_date_j}…);
  event automations toggle matrix (invoice finalized, repair status changes, ready,
  installment T-3/T-0/overdue, cheque T-2, birthday, abandoned device steps).
- Campaigns: audience builder over CRM filters (last purchase, brand owned, tags,
  balance), schedule, throttled queued sending, per-message status/delivery polling,
  opt-out list honored everywhere.
- In-app notification center (bell) fed by same events.
Tests: driver fake asserting exact payloads; automation matrix; credit deduction
accuracy incl. refund on gateway failure; campaign filter correctness on seeded CRM;
opt-out respected.
DoD: changing a repair status fires the right pattern SMS in sandbox/fake and logs cost.

## Phase 9 — Dashboard & Reporting
Tasks:
- Role-aware dashboard widgets (see spec) with fast SQL (indexed, no N+1; measure).
- 25+ reports (sales daily/monthly, by product/brand/salesperson, profit, technician
  performance, dead stock, stock valuation, party balances aging, cheques calendar,
  installments book, tax/VAT summary, SMS usage…), all with Jalali range filter,
  print CSS + Excel export; saved-filter presets.
Tests: golden-number tests: seeded scenario → each report exact expected figures;
query performance budget test (<300ms on 100k-row seed for top reports).
DoD: numbers everywhere agree with Phase 7 reconciliation scenario.

## Phase 10 — Multi-branch polish, Storefront/Price-list, Hamta, Moadian v1, Data tools
Tasks:
- Multi-branch audit: every module respects branch context; branch switcher; consolidated
  vs per-branch reporting toggle; per-branch counters verified.
- Public storefront (module): shop landing page + product catalog with live prices;
  **reseller price-list link** (password/expiry, per-price-level), PDF export, WhatsApp CTA.
- Hamta module: guided ownership-transfer workflow on used buy/sell (checklist,
  activation-id record on unit, "transfer pending" warnings, *#7777# instructions page).
  Be explicit in UI: no official API — this is record-keeping + guidance.
- Moadian (tax) v1: adapter interface + one intermediary-provider driver behind queue;
  invoice→e-invoice payload mapping, send/poll status, error inbox, resend; feature-flagged.
- Data: full tenant export (Excel/JSON zip), products import, backup-request button
  (admin-side artisan), audit-log viewer UI with filters.
Tests: price-list link security (expiry/password/level), storefront leaks nothing
private, moadian driver contract tests with fake, export completeness snapshot.
DECISION GATE 4: choose the real Moadian intermediary + storefront scope before build.

## Phase 11 — Hardening, Performance, Launch
Tasks:
- Security pass: OWASP ASVS-L1 checklist in docs, rate limits (login/OTP/public pages),
  signed URLs audit, headers/CSP, dependency audit in CI, secrets hygiene,
  encrypted columns inventory (device passcodes!), impersonation & RLS re-verification.
- Performance: seed 50 tenants × realistic volumes; k6 (or artisan bench) on top 10
  endpoints; fix N+1s (strict Model::preventLazyLoading in non-prod already from Phase 0);
  add missing composite indexes; queue latency dashboards.
- Ops: production docker-compose + nginx + SSL guide in docs/deploy.md; zero-downtime
  deploy script; nightly pg_dump + WAL archiving + restore drill documented and
  actually performed once; Sentry + health endpoint + uptime hook; Horizon prod config.
- Launch kit: demo tenant with rich Persian data, 5-minute owner onboarding tour,
  terms/privacy pages, and the public landing page built exactly per
  `docs/design-system.md#landing` (signature "live thermal receipt" hero, section
  order as specced, Blade + Tailwind, JS ≤ 180KB gz, LCP < 2.5s on 4G,
  `prefers-reduced-motion` honored). DECISION GATE: show me the landing at
  wireframe + first styled pass before polishing.
DoD: staging deploy from CI, restore drill log committed, load test report in docs,
go-live checklist all green.

---

# Module functional specs (seed for docs/specs/ — expand each into its own file)

- **Platform**: tenants, plans/modules/limits/add-ons, subscriptions+proration, Zarinpal
  billing, coupons/trial/grace, SMS credit sales, Filament panel, impersonation (audited),
  announcements, MRR/churn.
- **Identity**: onboarding wizard, per-tenant auth, 2FA, roles (7 defaults) with granular
  `module.action` permissions, branch restrictions, activity log.
- **Catalog**: categories/brands/products/variants, barcodes, 3 price levels, bulk pricing.
- **Inventory**: serialized units with IMEI passport & state machine, movements ledger,
  multi-warehouse, transfers (2-step), counts, low/dead stock, labels.
- **Purchasing**: supplier invoices, bulk IMEI intake, landed costs, returns, GRN.
- **Sales**: POS scan-first, split payments, returns, quotes, trade-in intake, warranty,
  commissions, thermal/A4 prints with QR, numbering, Z-report, profit snapshots.
- **Installments**: plan generator, guarantor, collection desk, late fee, early settle,
  SMS nudges, contract print.
- **Cheques**: both directions, full status lifecycle with ledger matrix, reminders.
- **Repairs**: configurable intake checklist+photos+encrypted passcode, kanban statuses,
  approvals with cap, parts reserve/consume, outsource, technician commission, signature
  delivery, abandoned escalation, public tracking, service price book (flash/unlock/FRP…).
- **CRM**: parties unified, ledgers/statements, credit limit, tags, follow-ups, loyalty,
  birthday automation, Excel import, 360 timeline.
- **Treasury**: accounts (cash/bank/pos/petty), transfers, expense/income trees,
  recurring, rental contracts→income, daily close, P&L, cash-flow.
- **Messaging**: multi-driver pattern SMS, credit wallet, template vars, automation
  matrix, campaigns with audience builder, delivery logs, opt-out, in-app notifications.
- **Reporting**: 25+ Jalali-filtered reports with Excel/print, saved presets, dashboards.
- **Storefront**: public catalog, reseller price-list links (password/expiry), PDF.
- **Hamta**: guided transfer workflow + unit-level records + warnings (no API — say so).
- **Moadian**: adapter + intermediary driver, queue, status inbox (feature-flagged).
- **Files**: media on S3 per-tenant prefix, quotas by plan, signed URLs.
- **Settings**: shop profile, fiscal year (Jalali), print templates, numbering, intake
  checklist builder, toggles.

# Anti-goals (do NOT build unless I ask)
Native mobile apps · full online-ordering/e-commerce checkout · double-entry GL beyond
the specified ledgers · multi-currency · Kubernetes · GraphQL · microservices.

Begin now with "First actions right now (Phase 0 kickoff)". After Phase 0 DoD is met,
continue straight into Phase 1 unless a DECISION GATE stops you.
