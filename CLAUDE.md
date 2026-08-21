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
1. TENANCY: single database, shared schema. Every tenant table has `tenant_id`,
   a composite index leading with it, the `BelongsToTenant` trait (global scope +
   auto-fill) and a Postgres RLS policy — all four, in the same migration.
   The tenant id is pinned on the connection with
   `select set_config('app.tenant_id', <id>, false)` — **session-scoped, not
   `SET LOCAL`**. `SET LOCAL` is transaction-scoped and Laravel does not wrap a
   request in a transaction, so it would silently set nothing and every tenant
   query would return zero rows. Session scope means the value must be cleared at
   each boundary; four are covered and tested (ADR 0007): end of request, around a
   queued job, `TenantContext::runFor()`, and connection (re)establishment.
   RLS policies read `current_setting('app.tenant_id', true)` and use both `USING`
   and `WITH CHECK`, so an unset context denies everything rather than erroring —
   the layer fails closed. Never write a query that bypasses the scope
   (`withoutTenancy()` only inside Platform module, with a comment why).
   Platform-owned billing tables (`subscriptions`, `subscription_invoices`) keep RLS
   but read across tenants via `TenantContext::runAsPlatform()` — narrow by design,
   never a blanket bypass (ADR 0002 amendment).
   `php artisan tenancy:check` enforces all of this and runs in CI.
1b. APEX DOMAIN: the production domain is **`mobiyar.com`** (chosen 2026-08-20; the
   box is Hetzner/Helsinki, wildcard TLS for `mobiyar.com` + `*.mobiyar.com`).
   **Knowing it does not license writing it down.** It still comes from
   `config('app.domain')` only, and must stay configurable everywhere it surfaces —
   links, printed receipts, repair-tracking QR codes, reseller price-list links, SMS
   templates and emails. A hostname literal in a template or a test fixture is a bug,
   **and that now includes `mobiyar.com` itself** — this is the rule's harder half, not
   its expired half. Tenants resolve by `domains.hostname` rows, so changing the apex
   stays a config change plus a data migration, never a code change; and the local
   stack still runs on `app.localhost`, which only works because nothing hardcodes.
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
   Messaging, Reporting, Files, Settings, Storefront, Hamta, Moadian) — 18 in all.
   Cross-module calls only
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

## Environments — one box, and it is production (as of 2026-08-21)

| | where | what it holds |
|---|---|---|
| local | colima/Docker on the dev laptop | throwaway demo tenant |
| production | `mobiyar.com` — Hetzner, Helsinki | seeded fixtures. **No real customers yet** |

**There is no staging box, and that is deliberate, not an omission.** Staging and
production run identical software; the only thing that distinguishes them is what the data
is worth. With zero customers the data on `mobiyar.com` is worth nothing, so a second box
would cost money to teach us nothing. Phase 11.4 is the evidence in the other direction:
deploying to real hardware found **eleven faults** — WAL archiving that had never once
succeeded, a certbot container with no DNS plugin, an nginx that was never reloaded after
renewal — and **not one of them was reachable from a local test**. Deploy-layer bugs only
exist on the deploy layer.

So while this holds:

- **`main` deploys to `mobiyar.com`.** A green PR is a release. Ship small and often;
  a broken deploy costs a redeploy and nothing else.
- **The full suite runs in GitHub Actions**, on GitHub's machines — not the laptop and not
  the server. Locally run only the tests for the change (`--filter`); CI is the gate that
  counts. The laptop is for writing code and looking at screens, not for burning cores.
- **Destructive operations are allowed on `mobiyar.com`** — `migrate:fresh`, the 50-shop
  volume seed, the k6 load test. `docs/load-testing.md` still says "never against
  production"; that sentence is **suspended** until the tripwire below, and must be
  reinstated with it, not quietly deleted now.

**The tripwire: the first real shop.** The day one paying customer's data lands on that
box, every bullet above expires at once — `migrate:fresh` becomes unthinkable, the load
test needs its own machine, and a second box must exist *before* the next risky deploy,
not after the first incident. Whoever onboards that shop owns rewriting this section the
same day. **A stale "no real customers yet" is the most expensive sentence this repo could
contain**, because it goes on reading as permission long after the permission ended.

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
- **`make hooks`, once per clone.** Sets `core.hooksPath` so `.githooks/pre-push` refuses a
  direct push to `main`. This is not belt-and-braces — the rule above was broken once, in
  Phase 10, by finishing a merge and starting the next phase without branching.
  **Be precise about what enforces it.** GitHub rulesets and branch protection are
  **Pro-gated for private repositories**, and this repo is private because it is a
  commercial product — so *the platform enforces nothing here*. What exists is a local hook
  (prevention, only on a clone that ran `make hooks`) and `.github/workflows/guard-main.yml`
  (detection: a red build when a commit reaches `main` without a PR). Neither is airtight,
  and writing "enforced" in this file when it is not would be the more expensive error —
  a rule everybody believes is mechanical is one nobody checks.
  Override for a genuine emergency: `ALLOW_MAIN_PUSH=1 git push`.
- Counters (invoice/ticket numbers) via `counters` table with row lock — never MAX(+1).
- **An idempotent insert that catches a unique violation must run in a nested
  transaction.** Postgres aborts the *entire* transaction on a constraint violation, so
  catching 23505 inside an outer one leaves it dead and every later statement fails with
  `25P02: current transaction is aborted`. Wrapping the insert in `DB::transaction()`
  gives it a SAVEPOINT to roll back to instead. Two places have needed this —
  `AbandonedSweep::insertOnce()` and `SendSms::record()` — and the second presented as
  twelve *unrelated* tests failing after the one that collided, which is why it is written
  down rather than rediscovered a third time. Every test runs inside `RefreshDatabase`'s
  transaction, so this is not an edge case: it is the default condition.
  **And the `try` goes OUTSIDE the `DB::transaction()`, not inside it.** `DB::transaction()`
  rolls back to its SAVEPOINT when the closure *throws*; a closure that catches its own
  exception never triggers that, so the recovery query runs on a connection that is still
  aborted and dies with the same 25P02 the wrapper was added to prevent. Third occurrence,
  in `SubmitInvoice::enqueue()`:

  ```php
  // WRONG — the catch runs inside the aborted nested transaction.
  DB::transaction(fn () => { try { insert(); } catch { select(); } });

  // RIGHT — the closure throws, the savepoint rolls back, the catch runs on a healthy one.
  try { DB::transaction(fn () => insert()); } catch { select(); }
  ```
- **A `function_exists`-guarded global helper must not take a name a dependency also
  defines.** Same failure as `bindIf` below and same tell — nothing crashes, the wrong
  implementation just wins the name. `App\Support\helpers.php` defined `jdate()`;
  **morilog/jalali defines one too**, both guarded, and the package's autoloaded first. Ours
  was dead for eight phases and looked live: `jdate($t)` returned `1405-06-02 21:18:47`
  where every screen in this product shows `۱۴۰۵/۰۶/۰۲`. Nothing used it until a Blade view
  did. Renamed to `jalali()`; before adding a global helper, grep the vendor tree for
  `function <name>`.
- **A null-object default is bound with `bindIf`, never `bind`.** Module providers are
  discovered in directory order, so a default and its real implementation binding the same
  interface with `bind` means the last writer wins — and which one that is depends on a
  directory listing. The symptom is not a crash: it is **a guard that silently passes**,
  half the time, on half the deployments. `Cheques` binding `PartyExposure` lost to CRM's
  `NoPartyExposure` exactly this way, and the credit check went on approving customers it
  should have stopped.
- **Every form has a home for errors that belong to no field.** A validation failure on
  `accessories` or `lines` has nowhere to render beside an input, so without a general
  error region the submit button silently does nothing — and the operator, with a
  customer at the counter, presses it again and concludes the software is broken. Render
  every key of the error bag, not just the ones you thought to place.
- **A multipart form gets a test that posts with its optional-array keys absent.** A
  `FormData` body cannot express an empty array: an unticked checkbox group is not sent
  as `[]`, it is not sent at all. So `present`/`required` on an optional array rejects
  the ordinary case, and only a test that omits the key entirely will catch it —
  building the payload in PHP always includes it.

## Workflow every session
1. Read `docs/ROADMAP.md`; pick the next unchecked `[ ]` task (top to bottom).
2. Plan briefly → implement → run **only the tests for the change**
   (`php artisan test --compact --filter=…` or a file path) → fix until green.
   **Do not run the full `composer test` locally as a habit.** It pins the laptop's cores
   for minutes through the colima VM to re-prove code nobody touched, and GitHub Actions
   already runs all four gates (Pint, RTL, Larastan, Pest) on every push for free.
   The full local run is for one case only: you are about to open or merge a PR and want
   the answer before CI gives it. Otherwise push and read the checks.
3. Check the box, append one line to `docs/PROGRESS.md` (date, what, notable decisions).
4. Architectural decision made? Add `docs/adr/NNNN-*.md`.
5. Stop at DECISION GATES defined in the roadmap and ask the human before proceeding.
6. **You own the full git lifecycle.** When a phase's PR is green — every CI check passed
   — and its DoD has been walked, merge it yourself (`gh pr merge --squash
   --delete-branch`), pull `main`, and carry on. Do not wait to be told. **PR mechanics are
   not a stopping point.** The only places to stop are DECISION GATES and design-review
   checkpoints defined in the roadmap.
   If checks are red or the DoD is unwalked, that is work to do, not a question to ask.
   **Check the checks, never `mergeStateStatus`.** Wait until `gh pr checks <n>` lists
   every job and none is `pending`, then merge only if none is failing. `mergeStateStatus`
   answers a different question — *may this branch merge* — and on a **private repository
   with no required checks it returns `CLEAN` before CI has even been queued**, because
   nothing is required. Branch protection is Pro-gated here (see the `make hooks` note
   above), so "required" is empty by construction and that field is permanently
   optimistic. It merged #38 with zero checks reported; they happened to pass afterwards,
   which is luck rather than a gate:

   ```bash
   # WRONG — CLEAN just means "nothing is blocking", including "nothing has run".
   until [ "$(gh pr view "$n" --json mergeStateStatus -q .mergeStateStatus)" = CLEAN ]; do sleep 30; done

   # RIGHT — wait for the jobs themselves, then read their verdicts.
   until [ "$(gh pr checks "$n" | grep -c pending)" = 0 ] && [ "$(gh pr checks "$n" | wc -l)" -gt 0 ]; do sleep 30; done
   gh pr checks "$n" | grep -qv $'\tpass\t' && echo "not merging" || gh pr merge "$n" --squash --delete-branch
   ```
7. **End every session with a push.** `git push` the working branch before the session
   closes — always, even mid-phase, even with no PR open and the phase half-built.
   Unpushed commits exist on exactly one disk. A branch nobody has pushed is not
   "in progress", it is one hardware failure from gone. Set upstream on first push
   (`git push -u origin <branch>`); pushing a work-in-progress branch is normal and
   costs nothing, since only `main` is protected.
Never mark a task done with failing tests. Never skip the isolation test.
**Never tick a box for user-facing behaviour that no route and no screen reach** — a
service whose tests pass but which only Tinker can call is not a shipped feature, and the
checkbox would be claiming something the tests never said. Ask whether a shopkeeper can
do the thing; if the answer needs a terminal, the box stays open with a reason beside it.

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
- Load-test runbook (run 2026-08-20 against `mobiyar.com`; p95 fails on `/dashboard`):
  docs/load-testing.md · docs/load-tests/2026-08-20.md
- Persian source docs (business plan / design & tooling supplement):
  docs/01-master-plan-fa.md · docs/03-design-and-claude-setup-fa.md

## Precedence

Everything above this line is project law. Everything below it is generic Laravel
guidance generated by `php artisan boost:install` and refreshed by `boost:update`.

Where the two conflict, **the golden rules win**. The known conflicts:

- Boost suggests general Eloquent and money patterns; ours are stricter — integer
  rial, ledgers instead of stored totals, `BelongsToTenant` on every tenant model.
- Boost's Tailwind guidance is direction-agnostic; ours forbids physical direction
  classes outright (golden rule 9), enforced by `bin/check-direction-classes`.
- Boost assumes tests may use SQLite; ours require real PostgreSQL, because RLS is
  the tenancy guarantee and SQLite cannot express it.

Do not edit the block below by hand — `boost:update` overwrites it.

===

<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

The Laravel Boost guidelines are specifically curated by Laravel maintainers for this application. These guidelines should be followed closely to enhance the user's satisfaction building Laravel applications.

## Foundational Context
This application is a Laravel application and its main Laravel ecosystems package & versions are below. You are an expert with them all. Ensure you abide by these specific packages & versions.

- php - 8.4.24
- inertiajs/inertia-laravel (INERTIA) - v2
- laravel/framework (LARAVEL) - v12
- laravel/prompts (PROMPTS) - v0
- larastan/larastan (LARASTAN) - v3
- laravel/mcp (MCP) - v0
- laravel/pint (PINT) - v1
- pestphp/pest (PEST) - v4
- phpunit/phpunit (PHPUNIT) - v12
- @inertiajs/react (INERTIA) - v2
- react (REACT) - v19
- tailwindcss (TAILWINDCSS) - v4

## Conventions
- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts
- Do not create verification scripts or tinker when tests cover that functionality and prove it works. Unit and feature tests are more important.

## Application Structure & Architecture
- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling
- If the user doesn't see a frontend change reflected in the UI, it could mean they need to run `npm run build`, `npm run dev`, or `composer run dev`. Ask them.

## Replies
- Be concise in your explanations - focus on what's important rather than explaining obvious details.

## Documentation Files
- You must only create documentation files if explicitly requested by the user.

=== boost rules ===

## Laravel Boost
- Laravel Boost is an MCP server that comes with powerful tools designed specifically for this application. Use them.

## Artisan
- Use the `list-artisan-commands` tool when you need to call an Artisan command to double-check the available parameters.

## URLs
- Whenever you share a project URL with the user, you should use the `get-absolute-url` tool to ensure you're using the correct scheme, domain/IP, and port.

## Tinker / Debugging
- You should use the `tinker` tool when you need to execute PHP to debug code or query Eloquent models directly.
- Use the `database-query` tool when you only need to read from the database.

## Reading Browser Logs With the `browser-logs` Tool
- You can read browser logs, errors, and exceptions using the `browser-logs` tool from Boost.
- Only recent browser logs will be useful - ignore old logs.

## Searching Documentation (Critically Important)
- Boost comes with a powerful `search-docs` tool you should use before any other approaches when dealing with Laravel or Laravel ecosystem packages. This tool automatically passes a list of installed packages and their versions to the remote Boost API, so it returns only version-specific documentation for the user's circumstance. You should pass an array of packages to filter on if you know you need docs for particular packages.
- The `search-docs` tool is perfect for all Laravel-related packages, including Laravel, Inertia, Livewire, Filament, Tailwind, Pest, Nova, Nightwatch, etc.
- You must use this tool to search for Laravel ecosystem documentation before falling back to other approaches.
- Search the documentation before making code changes to ensure we are taking the correct approach.
- Use multiple, broad, simple, topic-based queries to start. For example: `['rate limiting', 'routing rate limiting', 'routing']`.
- Do not add package names to queries; package information is already shared. For example, use `test resource table`, not `filament 4 test resource table`.

### Available Search Syntax
- You can and should pass multiple queries at once. The most relevant results will be returned first.

1. Simple Word Searches with auto-stemming - query=authentication - finds 'authenticate' and 'auth'.
2. Multiple Words (AND Logic) - query=rate limit - finds knowledge containing both "rate" AND "limit".
3. Quoted Phrases (Exact Position) - query="infinite scroll" - words must be adjacent and in that order.
4. Mixed Queries - query=middleware "rate limit" - "middleware" AND exact phrase "rate limit".
5. Multiple Queries - queries=["authentication", "middleware"] - ANY of these terms.

=== php rules ===

## PHP

- Always use curly braces for control structures, even if it has one line.

### Constructors
- Use PHP 8 constructor property promotion in `__construct()`.
    - <code-snippet>public function __construct(public GitHub $github) { }</code-snippet>
- Do not allow empty `__construct()` methods with zero parameters unless the constructor is private.

### Type Declarations
- Always use explicit return type declarations for methods and functions.
- Use appropriate PHP type hints for method parameters.

<code-snippet name="Explicit Return Types and Method Params" lang="php">
protected function isAccessible(User $user, ?string $path = null): bool
{
    ...
}
</code-snippet>

## Comments
- Prefer PHPDoc blocks over inline comments. Never use comments within the code itself unless there is something very complex going on.

## PHPDoc Blocks
- Add useful array shape type definitions for arrays when appropriate.

## Enums
- Typically, keys in an Enum should be TitleCase. For example: `FavoritePerson`, `BestLake`, `Monthly`.

=== tests rules ===

## Test Enforcement

- Every change must be programmatically tested. Write a new test or update an existing test, then run the affected tests to make sure they pass.
- Run the minimum number of tests needed to ensure code quality and speed. Use `php artisan test --compact` with a specific filename or filter.

=== inertia-laravel/core rules ===

## Inertia

- Inertia.js components should be placed in the `resources/js/pages` directory unless specified differently in the JS bundler (`vite.config.js`).
- Use `Inertia::render()` for server-side routing instead of traditional Blade views.
- Use the `search-docs` tool for accurate guidance on all things Inertia.

<code-snippet name="Inertia Render Example" lang="php">
// routes/web.php example
Route::get('/users', function () {
    return Inertia::render('Users/Index', [
        'users' => User::all()
    ]);
});
</code-snippet>

=== inertia-laravel/v2 rules ===

## Inertia v2

- Make use of all Inertia features from v1 and v2. Check the documentation before making any changes to ensure we are taking the correct approach.

### Inertia v2 New Features
- Deferred props.
- Infinite scrolling using merging props and `WhenVisible`.
- Lazy loading data on scroll.
- Polling.
- Prefetching.

### Deferred Props & Empty States
- When using deferred props on the frontend, you should add a nice empty state with pulsing/animated skeleton.

### Inertia Form General Guidance
- The recommended way to build forms when using Inertia is with the `<Form>` component - a useful example is below. Use the `search-docs` tool with a query of `form component` for guidance.
- Forms can also be built using the `useForm` helper for more programmatic control, or to follow existing conventions. Use the `search-docs` tool with a query of `useForm helper` for guidance.
- `resetOnError`, `resetOnSuccess`, and `setDefaultsOnSuccess` are available on the `<Form>` component. Use the `search-docs` tool with a query of `form component resetting` for guidance.

=== laravel/core rules ===

## Do Things the Laravel Way

- Use `php artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using the `list-artisan-commands` tool.
- If you're creating a generic PHP class, use `php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

### Database
- Always use proper Eloquent relationship methods with return type hints. Prefer relationship methods over raw queries or manual joins.
- Use Eloquent models and relationships before suggesting raw database queries.
- Avoid `DB::`; prefer `Model::query()`. Generate code that leverages Laravel's ORM capabilities rather than bypassing them.
- Generate code that prevents N+1 query problems by using eager loading.
- Use Laravel's query builder for very complex database operations.

### Model Creation
- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `list-artisan-commands` to check the available options to `php artisan make:model`.

### APIs & Eloquent Resources
- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

### Controllers & Validation
- Always create Form Request classes for validation rather than inline validation in controllers. Include both validation rules and custom error messages.
- Check sibling Form Requests to see if the application uses array or string based validation rules.

### Queues
- Use queued jobs for time-consuming operations with the `ShouldQueue` interface.

### Authentication & Authorization
- Use Laravel's built-in authentication and authorization features (gates, policies, Sanctum, etc.).

### URL Generation
- When generating links to other pages, prefer named routes and the `route()` function.

### Configuration
- Use environment variables only in configuration files - never use the `env()` function directly outside of config files. Always use `config('app.name')`, not `env('APP_NAME')`.

### Testing
- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `php artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

### Vite Error
- If you receive an "Illuminate\Foundation\ViteException: Unable to locate file in Vite manifest" error, you can run `npm run build` or ask the user to run `npm run dev` or `composer run dev`.

=== laravel/v12 rules ===

## Laravel 12

- Use the `search-docs` tool to get version-specific documentation.
- Since Laravel 11, Laravel has a new streamlined file structure which this project uses.

### Laravel 12 Structure
- In Laravel 12, middleware are no longer registered in `app/Http/Kernel.php`.
- Middleware are configured declaratively in `bootstrap/app.php` using `Application::configure()->withMiddleware()`.
- `bootstrap/app.php` is the file to register middleware, exceptions, and routing files.
- `bootstrap/providers.php` contains application specific service providers.
- The `app\Console\Kernel.php` file no longer exists; use `bootstrap/app.php` or `routes/console.php` for console configuration.
- Console commands in `app/Console/Commands/` are automatically available and do not require manual registration.

### Database
- When modifying a column, the migration must include all of the attributes that were previously defined on the column. Otherwise, they will be dropped and lost.
- Laravel 12 allows limiting eagerly loaded records natively, without external packages: `$query->latest()->limit(10);`.

### Models
- Casts can and likely should be set in a `casts()` method on a model rather than the `$casts` property. Follow existing conventions from other models.

=== pint/core rules ===

## Laravel Pint Code Formatter

- You must run `vendor/bin/pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/pint --test --format agent`, simply run `vendor/bin/pint --format agent` to fix any formatting issues.

=== pest/core rules ===

## Pest
### Testing
- If you need to verify a feature is working, write or update a Unit / Feature test.

### Pest Tests
- All tests must be written using Pest. Use `php artisan make:test --pest {name}`.
- You must not remove any tests or test files from the tests directory without approval. These are not temporary or helper files - these are core to the application.
- Tests should test all of the happy paths, failure paths, and weird paths.
- Tests live in the `tests/Feature` and `tests/Unit` directories.
- Pest tests look and behave like this:
<code-snippet name="Basic Pest Test Example" lang="php">
it('is true', function () {
    expect(true)->toBeTrue();
});
</code-snippet>

### Running Tests
- Run the minimal number of tests using an appropriate filter before finalizing code edits.
- To run all tests: `php artisan test --compact`.
- To run all tests in a file: `php artisan test --compact tests/Feature/ExampleTest.php`.
- To filter on a particular test name: `php artisan test --compact --filter=testName` (recommended after making a change to a related file).
- When the tests relating to your changes are passing, ask the user if they would like to run the entire test suite to ensure everything is still passing.

### Pest Assertions
- When asserting status codes on a response, use the specific method like `assertForbidden` and `assertNotFound` instead of using `assertStatus(403)` or similar, e.g.:
<code-snippet name="Pest Example Asserting postJson Response" lang="php">
it('returns all', function () {
    $response = $this->postJson('/api/docs', []);

    $response->assertSuccessful();
});
</code-snippet>

### Mocking
- Mocking can be very helpful when appropriate.
- When mocking, you can use the `Pest\Laravel\mock` Pest function, but always import it via `use function Pest\Laravel\mock;` before using it. Alternatively, you can use `$this->mock()` if existing tests do.
- You can also create partial mocks using the same import or self method.

### Datasets
- Use datasets in Pest to simplify tests that have a lot of duplicated data. This is often the case when testing validation rules, so consider this solution when writing tests for validation rules.

<code-snippet name="Pest Dataset Example" lang="php">
it('has emails', function (string $email) {
    expect($email)->not->toBeEmpty();
})->with([
    'james' => 'james@laravel.com',
    'taylor' => 'taylor@laravel.com',
]);
</code-snippet>

=== pest/v4 rules ===

## Pest 4

- Pest 4 is a huge upgrade to Pest and offers: browser testing, smoke testing, visual regression testing, test sharding, and faster type coverage.
- Browser testing is incredibly powerful and useful for this project.
- Browser tests should live in `tests/Browser/`.
- Use the `search-docs` tool for detailed guidance on utilizing these features.

### Browser Testing
- You can use Laravel features like `Event::fake()`, `assertAuthenticated()`, and model factories within Pest 4 browser tests, as well as `RefreshDatabase` (when needed) to ensure a clean state for each test.
- Interact with the page (click, type, scroll, select, submit, drag-and-drop, touch gestures, etc.) when appropriate to complete the test.
- If requested, test on multiple browsers (Chrome, Firefox, Safari).
- If requested, test on different devices and viewports (like iPhone 14 Pro, tablets, or custom breakpoints).
- Switch color schemes (light/dark mode) when appropriate.
- Take screenshots or pause tests for debugging when appropriate.

### Example Tests

<code-snippet name="Pest Browser Test Example" lang="php">
it('may reset the password', function () {
    Notification::fake();

    $this->actingAs(User::factory()->create());

    $page = visit('/sign-in'); // Visit on a real browser...

    $page->assertSee('Sign In')
        ->assertNoJavascriptErrors() // or ->assertNoConsoleLogs()
        ->click('Forgot Password?')
        ->fill('email', 'nuno@laravel.com')
        ->click('Send Reset Link')
        ->assertSee('We have emailed your password reset link!')

    Notification::assertSent(ResetPassword::class);
});
</code-snippet>

<code-snippet name="Pest Smoke Testing Example" lang="php">
$pages = visit(['/', '/about', '/contact']);

$pages->assertNoJavascriptErrors()->assertNoConsoleLogs();
</code-snippet>

=== inertia-react/core rules ===

## Inertia + React

- Use `router.visit()` or `<Link>` for navigation instead of traditional links.

<code-snippet name="Inertia Client Navigation" lang="react">

import { Link } from '@inertiajs/react'
<Link href="/">Home</Link>

</code-snippet>

=== inertia-react/v2/forms rules ===

## Inertia v2 + React Forms

<code-snippet name="`<Form>` Component Example" lang="react">

import { Form } from '@inertiajs/react'

export default () => (
    <Form action="/users" method="post">
        {({
            errors,
            hasErrors,
            processing,
            wasSuccessful,
            recentlySuccessful,
            clearErrors,
            resetAndClearErrors,
            defaults
        }) => (
        <>
        <input type="text" name="name" />

        {errors.name && <div>{errors.name}</div>}

        <button type="submit" disabled={processing}>
            {processing ? 'Creating...' : 'Create User'}
        </button>

        {wasSuccessful && <div>User created successfully!</div>}
        </>
    )}
    </Form>
)

</code-snippet>

=== tailwindcss/core rules ===

## Tailwind CSS

- Use Tailwind CSS classes to style HTML; check and use existing Tailwind conventions within the project before writing your own.
- Offer to extract repeated patterns into components that match the project's conventions (i.e. Blade, JSX, Vue, etc.).
- Think through class placement, order, priority, and defaults. Remove redundant classes, add classes to parent or child carefully to limit repetition, and group elements logically.
- You can use the `search-docs` tool to get exact examples from the official documentation when needed.

### Spacing
- When listing items, use gap utilities for spacing; don't use margins.

<code-snippet name="Valid Flex Gap Spacing Example" lang="html">
    <div class="flex gap-8">
        <div>Superior</div>
        <div>Michigan</div>
        <div>Erie</div>
    </div>
</code-snippet>

### Dark Mode
- If existing pages and components support dark mode, new pages and components must support dark mode in a similar way, typically using `dark:`.

=== tailwindcss/v4 rules ===

## Tailwind CSS 4

- Always use Tailwind CSS v4; do not use the deprecated utilities.
- `corePlugins` is not supported in Tailwind v4.
- In Tailwind v4, configuration is CSS-first using the `@theme` directive — no separate `tailwind.config.js` file is needed.

<code-snippet name="Extending Theme in CSS" lang="css">
@theme {
  --color-brand: oklch(0.72 0.11 178);
}
</code-snippet>

- In Tailwind v4, you import Tailwind using a regular CSS `@import` statement, not using the `@tailwind` directives used in v3:

<code-snippet name="Tailwind v4 Import Tailwind Diff" lang="diff">
   - @tailwind base;
   - @tailwind components;
   - @tailwind utilities;
   + @import "tailwindcss";
</code-snippet>

### Replaced Utilities
- Tailwind v4 removed deprecated utilities. Do not use the deprecated option; use the replacement.
- Opacity values are still numeric.

| Deprecated |	Replacement |
|------------+--------------|
| bg-opacity-* | bg-black/* |
| text-opacity-* | text-black/* |
| border-opacity-* | border-black/* |
| divide-opacity-* | divide-black/* |
| ring-opacity-* | ring-black/* |
| placeholder-opacity-* | placeholder-black/* |
| flex-shrink-* | shrink-* |
| flex-grow-* | grow-* |
| overflow-ellipsis | text-ellipsis |
| decoration-slice | box-decoration-slice |
| decoration-clone | box-decoration-clone |
</laravel-boost-guidelines>
