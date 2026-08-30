# Hamyar («سامانه همیار») — Multi-tenant SaaS for mobile-phone shops (Iran market)

Cloud platform where mobile-phone stores subscribe to plans and get modular tools:
POS/sales, serialized IMEI inventory, repairs workflow, CRM, cheques, installments,
treasury, SMS, reports. Persian (fa-IR), RTL, Jalali calendar, currency = IRR integer.

> **Why the rules below are one line each:** every one was paid for by a real bug, and the
> story is in **`docs/lessons.md`**. Read that when a rule looks arbitrary or you are about
> to argue with one. Do not re-litigate a rule without reading its entry first.

## Stack (locked — do not swap without an ADR)
- PHP 8.4, Laravel 12, PostgreSQL 16, Redis 7, Horizon queues
- Inertia.js v2 + React + TypeScript + Tailwind v4, RTL, font Vazirmatn
- Super-admin (central): Filament v4. Tenant app: Inertia pages only.
- Tests: Pest v4 · Static: Larastan level 8 · Style: Pint · CI: GitHub Actions (5 checks)
- Key packages: spatie/laravel-permission (teams = tenant_id), shetabit/multipay (Zarinpal),
  morilog/jalali, spatie/laravel-medialibrary, spatie/laravel-activitylog,
  maatwebsite/excel, picqer/php-barcode-generator, bacon/qr-code

## Golden rules (violations = bug, fix before anything else)

1. **TENANCY** — single database, shared schema. Every tenant table gets all four in the
   same migration: `tenant_id`, a composite index leading with it, the `BelongsToTenant`
   trait, and a Postgres RLS policy. The tenant id is pinned with
   `select set_config('app.tenant_id', <id>, false)` — **session-scoped, never `SET LOCAL`**
   (ADR 0007; see lessons). RLS uses both `USING` and `WITH CHECK` so an unset context
   denies everything. Never bypass the scope — `withoutTenancy()` only inside Platform,
   with a comment why. Platform-owned billing tables read across tenants via
   `TenantContext::runAsPlatform()` only (ADR 0002 amendment). `php artisan tenancy:check`
   enforces this in CI.
1b. **APEX DOMAIN** — production is `mobiyar.com`, and it comes from `config('app.domain')`
   **only**. A hostname literal anywhere — template, test fixture, SMS, QR code, receipt —
   is a bug, **including `mobiyar.com` itself**. Tenants resolve by `domains.hostname` rows.
   Enforced by `bin/check-apex-domain`.
1c. **THE NAME IS «سامانه همیار»** (slug `hamyar`), renamed from MobiShop 2026-08-29.
   In prose «همیار»; introducing it, «سامانه همیار». The apex domain did not change with it.
   **Several production identifiers still read `mobishop` and are load-bearing** — the
   compose project name, the database and roles, `/srv/mobishop`, `/var/backups/mobishop`,
   and the nginx upstream `mobishop_app`. Renaming any is a migration on the box, not an
   edit here. **See `docs/lessons.md` before touching one.**
2. **MONEY** — integers in IRR (rial). No floats near money. Column type BIGINT.
3. **LEDGERS** — stock quantity and balances are never updated in place; they are SUMs over
   `stock_movements` / `ledger_entries`. Write movements, not totals.
4. **SERIALIZED UNITS** — phones live in `product_units` (imei1/2, cost, status, condition,
   source party). Selling/repairing/reserving is a state transition recorded with history.
   IMEI history must answer: bought from whom → sold to whom → repaired when.
5. **DATES** — store UTC; render Jalali via helpers. Never store Jalali strings.
6. **MODULES** — code lives in `app/Modules/<Name>` (18: Platform, Identity, Catalog,
   Inventory, Purchasing, Sales, Repairs, CRM, Treasury, Cheques, Installments, Messaging,
   Reporting, Files, Settings, Storefront, Hamta, Moadian). Cross-module calls only via
   events or bound public interfaces. Pest arch tests enforce this.
7. **GATING IS QUANTITY, NOT AVAILABILITY** (ADR 0018, DECISION GATE 6). Every module is
   open to every shop; `modules.is_enabled` is a platform kill-switch, not something a plan
   buys. A plan sells **how much work a shop may record in a Jalali month**: every metered
   action calls `QuotaGuard::consume()` **inside the same transaction that writes the row it
   counts**. A metered create path that does not call `consume()` is a bug of the same class
   as a tenant table without RLS. Reads are never blocked; a lapsed shop falls back to the
   free plan; the first rung is free.
8. **TESTS** — every new behaviour ships with Pest feature tests, and every tenant-scoped
   endpoint gets a cross-tenant isolation test (tenant B gets 404/403 on tenant A's rows).
9. **UI** — follow the `hamyar-ui` skill. Design tokens only; RTL logical classes only
   (`ml-`/`pl-`/`left-`/`text-left` = bug, enforced by `bin/check-direction-classes`);
   shadcn/ui with `rtl:true`; domain components (Money/JDatePicker/StatusBadge/FormErrors)
   instead of ad-hoc markup; components land on `/design` before feature pages.

## Environments

> **No production server exists right now** (owner, 2026-08-29: «فعلا سرور پروداکشنی ندارم»).
> PRs still merge on green and `VERSION` still bumps, but **`bin/release --deploy` and
> `bin/smoke` are suspended** and nothing may be reported as shipped. `.claude/OPS.local.md`
> and `.deploy.local` are stale until the owner replaces them. **Whoever brings up the new
> box deletes this note the same day.**

- Local: colima/Docker on the dev laptop, throwaway demo tenant.
- Production (when it exists): `main` deploys to it; a green PR is a release; ship small
  and often. There is deliberately **no staging box** — see `docs/lessons.md`.
- **The full suite runs in CI, not on the laptop.** Locally run only the tests for the
  change (`--filter`). Never fan out parallel agents that run tests; if unavoidable, give
  each its own `DB_DATABASE`.
- **The tripwire is the first real shop.** The day a paying customer's data lands on a box,
  destructive operations expire at once and a second box must exist. See `docs/lessons.md`.

## Commands
- `make up` / `make down` — dev stack · `make fresh` — migrate:fresh --seed
  (demo.localhost, admin@demo.test / password) · `make hooks` — once per clone
- `composer test` — Pint + Larastan + Pest · `composer test:isolation` · `composer guards`
- `npm run dev` / `npm run build` · `php artisan make:module <Name>`
- `bin/release --deploy` — tag, publish, ship, prove. Procedure: `docs/RELEASE_PROCESS.md`
- `bin/smoke <apex>` — is the live site serving this? Also `curl -s https://<apex>/health`

## Conventions
- FormRequest for validation; thin controllers; domain logic in module Services/Actions.
- Policies on every resource; permission names `module.action`.
- Inertia/API responses via Resources; money out as integer + formatted string.
- **Persian UI strings in `lang/fa/**`; never hardcode Farsi in components.** A new
  validated field needs a label in `lang/fa/validation.php` or it renders its column name.
- Counters (invoice/ticket numbers) via the `counters` table with a row lock — never MAX+1.
- Conventional commits (`feat(sales): …`); one logical change per commit; never push to main.
- **`main` is protected** — a PR plus all five checks. `enforce_admins` is deliberately off.
  If protection is ever removed or the repo goes private, say so here (see lessons).

**Seven guards run in CI and encode rules people kept breaking.** Run `composer guards`
locally. Each refuses a shape, and each exists because that shape shipped at least once:

| guard | refuses |
|---|---|
| `check-direction-classes` | physical direction classes (golden rule 9) |
| `check-savepoint-recovery` | 23505 recovery — or any `finally` issuing SQL — inside a transaction |
| `check-global-helpers` | a global helper a vendor package also defines |
| `check-forgettable-singletons` | `forget()` on a non-singleton binding |
| `check-queued-tenancy` | a job neither tenant-aware nor declared platform-wide |
| `check-quota-scoping` | an unscoped query on a platform-owned quota table |
| `check-apex-domain` | a hardcoded hostname |

Rules the guards cannot catch, each with its story in `docs/lessons.md`:

- **A null-object default is bound with `bindIf`, never `bind`** — otherwise the last
  provider in directory order wins and a guard silently passes.
- **Anything caught broadly on a metered path must let `QuotaExceeded` through.** It extends
  `Exception`, not `RuntimeException`, precisely so `catch (RuntimeException)` cannot eat it.
- **Every form renders every key of the error bag**, not just the ones you thought to place.
  Use `<FormErrors errors={errors} handled={[…]} />`; a submit that silently does nothing is
  how an operator concludes the software is broken.
- **A multipart form gets a test that omits its optional-array keys** — `FormData` cannot
  express an empty array, so `present`/`required` on one rejects the ordinary case.
- **Return `null`, never `[]`, for "nothing to report" in a shared Inertia prop.** An empty
  PHP array is a truthy JSON array on the client.
- **`??` cannot tell null from missing** — use `array_key_exists()` when null is meaningful.

## Workflow every session
1. Read `docs/ROADMAP.md`; take the next unchecked `[ ]` task, top to bottom.
2. Plan briefly → implement → run **only the tests for the change** → fix until green.
3. Tick the box; append one line to `docs/PROGRESS.md` (date, what, notable decisions).
4. Architectural decision? Add `docs/adr/NNNN-*.md`. New scar? Add to `docs/lessons.md`.
5. **Stop at DECISION GATES** in the roadmap and ask the human. Those and design-review
   checkpoints are the *only* stopping points — PR mechanics are not.
6. **You own the full git lifecycle.** Green checks + walked DoD → merge it yourself
   (`gh pr merge --squash --delete-branch`), pull `main`, carry on. Red checks or an
   unwalked DoD is work to do, not a question to ask. **Read `gh pr checks`, never
   `mergeStateStatus`** (see lessons).
7. **End every session with a push**, even mid-phase. Unpushed commits exist on one disk.
8. **Green means merge, and merge means deploy** — `bin/release --deploy`. A green PR is
   not a decision waiting to be made; it is finished work nobody is shipping.
9. **A change that is not on the box is not done.** Not the checkbox, not the PROGRESS line,
   not the report. `bin/smoke` against the live site is the only evidence that counts.

Never mark a task done with failing tests. Never skip the isolation test. Never report a
change as shipped on the strength of a merge. **Never tick a box for user-facing behaviour
that no route and no screen reach** — ask whether a shopkeeper can do the thing; if the
answer needs a terminal, the box stays open with a reason beside it.

## Domain terms (fa → en)
فاکتور=invoice · پیش‌فاکتور=quote · حواله=transfer · انبارگردانی=stock count ·
قبض پذیرش=repair intake receipt · رسوبی=abandoned device · چک=cheque · قسط=installment ·
صندوق=cash account · کارتخوان=POS terminal account · طرف حساب=party ·
معاوضه=trade-in · همکار=reseller price level · همتا=HAMTA (IMEI registry, no public API)

## Pointers (open on demand — not auto-imported)
- **Why the rules exist: `docs/lessons.md`**
- Functional specs: `docs/specs/README.md` · Architecture: `docs/architecture.md`
- Design system & landing brief: `docs/design-system.md` · Testing: `docs/testing.md`
- **Shipping anything starts at `docs/RELEASE_PROCESS.md`** · Deploy: `docs/deploy.md`
- Versioning: `docs/VERSIONING.md` · Release history and reasons: `CHANGELOG.md`
- Load testing: `docs/load-testing.md` · `docs/load-tests/2026-08-20.md`
- Persian source docs: `docs/01-master-plan-fa.md` · `docs/03-design-and-claude-setup-fa.md`
- Production coordinates: `.claude/OPS.local.md`, `.deploy.local` — **gitignored, and this
  repository is public. Never copy a host, an IP or a secret out of them.**

---

**On the removed Boost block.** `php artisan boost:install` appends ~450 lines of generic
Laravel/Pest/Inertia/Tailwind guidance below this line. It was deleted deliberately on
2026-08-30: it is four times the length of this file's actual project law, it is read into
context every session, and almost all of it is either standard framework knowledge or
already stricter here. The three places it actively conflicted are settled above — integer
rial and ledgers over Eloquent defaults, no physical direction classes, and real PostgreSQL
rather than SQLite because RLS is the guarantee this product sells.

Boost's MCP tools remain useful and are unaffected: `search-docs` for version-specific
package documentation, `tinker` and `database-query` for debugging, `browser-logs` for
frontend errors. Use them.

**If `boost:update` re-appends the block, delete it again and leave this note.**
