# MobiShop — Multi-tenant SaaS for mobile-phone shops (Iran market)

Cloud platform where mobile-phone stores subscribe to plans and get modular tools:
POS/sales, serialized IMEI inventory, repairs workflow, CRM, cheques, installments,
treasury, SMS, reports. Persian (fa-IR), RTL, Jalali calendar, currency = IRR integer.

## Stack (locked — do not swap without an ADR)
- PHP 8.4, Laravel 12, PostgreSQL 16, Redis 7, Horizon queues
- Frontend: Inertia.js v2 + React + TypeScript + Tailwind CSS, RTL, font Vazirmatn
- Super-admin (central): Filament v4. Tenant app: Inertia pages only.
- Tests: Pest v4 · Static: Larastan level 8 · Style: Pint · CI: GitHub Actions
- Key packages: spatie/laravel-permission (teams = tenant_id), laravel/pennant,
  shetabit/multipay (Zarinpal), morilog/jalali, spatie/laravel-medialibrary,
  spatie/laravel-activitylog, maatwebsite/excel, picqer/php-barcode-generator, bacon/qr-code

## Golden rules (violations = bug, fix before anything else)
1. TENANCY: single database, shared schema. Every tenant table has `tenant_id`.
   All tenant models use the `BelongsToTenant` trait (global scope + auto-fill).
   Postgres RLS is enabled on every tenant table; each request/job runs
   `SET LOCAL app.tenant_id`. Never write a query that bypasses the scope
   (`withoutTenancy()` only inside Platform module, with a comment why).
2. MONEY: integers in IRR (rial). No floats anywhere near money. Column type BIGINT.
3. LEDGERS: stock quantity and party/account balances are NEVER updated in place —
   they are SUMs over `stock_movements` / `ledger_entries`. Write movements, not totals.
4. SERIALIZED UNITS: phones live in `product_units` (imei1/2, cost, status,
   condition, source party). Selling/repairing/reserving a phone = state transition
   on the unit, recorded with history. IMEI history must answer:
   bought from whom → sold to whom → repaired when.
5. DATES: store UTC timestamps; render Jalali via helpers. Never store Jalali strings.
6. MODULES: code lives in `app/Modules/<Name>` (Platform, Identity, Catalog,
   Inventory, Purchasing, Sales, Repairs, CRM, Treasury, Cheques, Installments,
   Messaging, Reporting, Files, Settings, Hamta, Moadian). Cross-module calls only
   via events or public service interfaces. Pest arch tests enforce this.
7. GATING: module availability = Pennant features resolved from the tenant's plan
   + purchased add-ons. Guard both routes (middleware) and UI (shared Inertia props).
8. EVERY new behavior ships with Pest feature tests, and every tenant-scoped
   endpoint gets a cross-tenant isolation test (tenant B must get 404/403 on
   tenant A's resources).
9. UI: follow the `mobishop-ui` skill (.claude/skills/) — design tokens only,
   RTL logical classes only (ml-/pl-/left-/text-left = bug), shadcn/ui base with
   rtl:true, domain components (Money/JDatePicker/StatusBadge/…) instead of ad-hoc
   markup, components land on the /design gallery before feature pages.

## Commands
- `make up` / `make down` — dev stack (app, postgres, redis, minio, mailpit)
- `make fresh` — migrate:fresh --seed (demo tenant: demo.localhost, admin@demo.test / password)
- `composer test` — Pint (check) + Larastan + Pest
- `composer test:isolation` — tenancy isolation suite only
- `npm run dev` / `npm run build`
- New module scaffold: `php artisan make:module <Name>` (custom generator, Phase 0)

## Conventions
- FormRequest for validation; thin controllers; domain logic in module Services/Actions.
- Policies for authorization on every resource; permission names `module.action`.
- API/Inertia responses via Resources; money out as integer + formatted string.
- Migrations: tenant tables get `$table->foreignId('tenant_id')->index()` + RLS in same migration.
- Persian UI strings in `lang/fa/**`; never hardcode Farsi in components.
- Conventional commits (`feat(sales): …`); one logical change per commit; no direct pushes to main.
- Counters (invoice/ticket numbers) via `counters` table with row lock — never MAX(+1).

## Workflow every session
1. Read `docs/ROADMAP.md`; pick the next unchecked `[ ]` task (top to bottom).
2. Plan briefly → implement → run `composer test` → fix until green.
3. Check the box, append one line to `docs/PROGRESS.md` (date, what, notable decisions).
4. Architectural decision made? Add `docs/adr/NNNN-*.md`.
5. Stop at DECISION GATES defined in the roadmap and ask the human before proceeding.
Never mark a task done with failing tests. Never skip the isolation test.

## Domain terms (fa → en)
فاکتور=invoice · پیش‌فاکتور=quote · حواله=transfer · انبارگردانی=stock count ·
قبض پذیرش=repair intake receipt · رسوبی=abandoned device · چک=cheque · قسط=installment ·
صندوق=cash account · کارتخوان=POS terminal account · طرف حساب=party ·
معاوضه=trade-in · همکار=reseller price level · همتا=HAMTA (IMEI registry, no public API)

## Pointers (open on demand with Read — not auto-imported)
- Full functional specs: docs/specs/README.md
- Architecture & tenancy details: docs/architecture.md
- Design system, tokens & landing brief: docs/design-system.md
- Testing policy & suites: docs/testing.md
- Deploy runbook: docs/deploy.md
- Persian source docs (business plan / design & tooling supplement):
  docs/01-master-plan-fa.md · docs/03-design-and-claude-setup-fa.md
