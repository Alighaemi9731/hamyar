# ADR 0018 — Metered plans: every module open, quantity limits per window, a three-rung ladder

- **Status:** **Proposed**, bound for **DECISION GATE 6** (`../ROADMAP.md`, Phase 12)
- **Date:** 2026-08-29 (j1405-06-07)
- **Amends:** [ADR 0006](0006-proration.md) — proration stays binding for upgrades; its
  "Add-ons" section is retired with add-ons. [ADR 0012](0012-tenant-keyed-caches.md) — a
  second worked example of a tenant-led cache. [ADR 0002](0002-single-db-tenancy-rls.md)
  amendment — three more platform-owned RLS tables read through `runAsPlatform()`.
- **Changes:** CLAUDE.md golden rule 7 (owner-authored; the new wording is Gate 6 item 12)
  and golden rule 5's letter for one column (period keys, §3.6 — see Alternatives).
- **Provenance:** owner direction 2026-08-29: «همه امکانات برای همه باز باشه اولش ولی
  محدودیت داشته باشن … روزانه تا یه تعداد … همه موارد و امکانات سایت محدودیت تعداد داشته
  باشن … اگه به محدودیت خوردن … اپگرید کنن به پلن بالاتر … در کل ۳ تا پلن.» Designed from
  a map of all 100 mutating actions in the 18 modules, three independent designs judged
  and merged, and 20 load-bearing claims adversarially checked against the code; the
  corrections are folded in below and named where they changed a decision.

## Context

Today a plan is a **bundle of modules**: `plan_module` decides which of the 16 modules a
shop may open, `subscription_addons` sells the rest one at a time, `EnsureModuleEnabled`
403s a route the plan lacks, and the `features` Inertia prop hides its nav item. The
`plan_limits` table exists with five keys (`users`, `branches`, `invoices_per_month`,
`storage_mb`, `sms_credit_bonus`) and `SubscriptionResolver::limit()` resolves them — and
**nothing enforces any of them** (roadmap 2.3 "usage counters; soft-lock" and 2.6 "limit
exhaustion" were never built). A trial is "Pro features, Basic quotas" for 14 days.

The owner wants the opposite shape: **every module open to every shop from the first
minute, and every kind of work capped by quantity** — mostly per day — with three plans
forming a ladder, so hitting a cap is the moment a shop upgrades.

Two facts from the code shape the design more than anything else:

1. **`COUNT(*)` over domain tables cannot be the meter.** Products, parties, units,
   invoices and branches soft-delete; contacts, payments and invoice items are hard-deleted
   and recreated on every edit; invoices hold drafts, quotes and finals in one table; voids
   keep the row. Every module has at least one reason a live count would be wrong.
2. **The upgrade path is broken today.** `BillingService::applyPayment()` never writes
   `subscriptions.plan_id` — a paid upgrade extends the period and leaves the shop on the
   old plan — and `billing/index.tsx:170` posts `plan.code` to a route bound by id. A
   quota ladder is only as good as the one click that lifts the cap, so this is fixed
   first, before the gate, as a plain bug.

## Decision

### 1. Product model

**Three plans, latin codes unchanged** (`basic` / `pro` / `enterprise` — 62 test files and
three production files name them; a rename buys nothing). Persian names and taglines are
seeded once and Filament-owned afterwards, like prices:

| rung | code | name_fa | tagline_fa |
|---|---|---|---|
| 1 | `basic` | **پایه** | «برای شروع؛ همهٔ امکانات، با سقف روزانه» |
| 2 | `pro` | **حرفه‌ای** (recommended) | «برای مغازه‌ای که هر روز می‌فروشد و تعمیر می‌کند» |
| 3 | `enterprise` | **نامحدود** | «بدون سقف؛ برای چند شعبه و حجم بالا» |

**Every module is open on every plan.** `EnsureModuleEnabled` and the `features` prop
**stay, dormant, as a platform-wide kill-switch** reading a new `modules.is_enabled`
column (Filament toggle «فعال برای همهٔ فروشگاه‌ها»); `features()` reads the DB rows, not
`PlanCatalogue::modules()`, or the toggle would be ignored. The 403 copy becomes «این بخش
موقتاً در دسترس نیست.». ADR 0011 (Moadian without a provider) needs exactly this switch.
Add-ons are **retired as a product**: `plan_module` and `subscription_addons` stop being
read in `0.15.0` and are dropped in `0.16.0` (VERSIONING blue/green rule). SMS credit —
the wallet — is the only thing sold beside the plans.

**Rung 1 — free or paid.** The two designs that scored highest disagreed, and the
completeness review found that "paid basic + lapse falls to basic quotas" is a free
basic through the back door (a rational shop never pays for what lapse gives). So the
choice must be made openly. **Recommendation: rung 1 is free** («رایگان»), because it is
what the owner's words describe, it makes the free plan *be* the trial (no trial
machinery), and it gives lapse a coherent landing. Its one real cost — SMS costs cash per
segment — is closed by **`messaging.sms = 0` on the free plan**: SMS is wallet-funded only,
and the wallet is money. Code cost: `Subscription::isUsable()` gains `active && plan is
free → true`, `BillingService::hasLivePeriod()` treats a free plan as "no live period → the
first paid plan is charged in full, never prorated", `SendRenewalReminders` skips free rows,
`RevenueOverview` MRR is unaffected (it sums `plans.price`). **If the owner chooses paid**,
rung 1 keeps a 14-day trial of `pro` quotas with `messaging.sms` forced to 10/day, and
lapse falls to a distinct config-defined `lapsed` limit set (read-only plus receipts,
0 new documents) — *not* to basic. Gate 6 item 2. The counter, guard and UI are identical
either way; nothing below waits on this item.

**Lapse never locks a shop out.** When `Subscription::isUsable()` is false (period ended
past grace, trial ended, canceled), reads keep working, public receipts and tracking keep
working, and creates are capped by the fallback limit set (`config('hamyar.quota.
fallback_plan')` — the free plan, or the `lapsed` set). The shell banner says «اشتراک شما
به پایان رسیده؛ سقف‌های پلن پایه اعمال می‌شود» with a renew CTA. Platform-initiated
lock-out stays what it is: `tenants.status` = `suspended` or `archived`.

**Lifecycle gets the writers it never had.** Nothing today writes `past_due`,
`grace_ends_at` or `canceled`, so "grace" does not exist and MRR counts lapsed shops as
revenue forever. A `@platform-wide` scheduled `subscriptions:expire` moves `active` →
`past_due` (`grace_ends_at = +N days`) at period end, `past_due` → `canceled` after grace,
`trialing` → `canceled` at trial end, and bumps the tenant's entitlement version on each
transition. Cancellation gets a Filament action («لغو در پایان دوره»); a tenant-side
cancel is out of scope for v1.

**Upgrades and downgrades.** An upgrade is prorated and immediate (ADR 0006);
`applyPayment()` writes `plan_id` and `plan_changed_at` and fires `SubscriptionActivated`.
**Counters are never reset or copied**: the row stores usage, the limit is resolved at
check time, so 30/30 on basic at 14:00 is 30/150 on pro at 14:05 with no special case.
A downgrade records `scheduled_plan_id`, applied at period end by `subscriptions:apply-
scheduled`; a shop over the new ceiling is **frozen, not truncated** — seven users on a
six-seat plan keep working, no eighth.

**Metrics.** Only actions a shopkeeper would call *work* are metered. Two rules explain
every exclusion: **correcting a counted thing is free** (returns, voids, reversals, reopen)
and **getting your own money, phone or receipt is free** (collections, deliveries, public
views, prints). Derived rows (ledger, movements, histories, reservations), customer-actioned
things (approvals, tracking views), security flows and legal obligations (HAMTA, Moadian)
are never metered. Windows: `day` = Tehran wall-clock day, `month` = Jalali month,
`total` = live rows right now. `NULL` = unlimited, the meaning `plan_limits.value` has today.

| metric key | label_fa | window | basic | pro | enterprise | counted at |
|---|---|---|---|---|---|---|
| `sales.invoices` | فاکتور فروش | day | 30 | 150 | ∞ | `FinaliseInvoice::finalise` (draft→final); repair-delivery invoices exempt (§4) |
| `sales.quotes` | پیش‌فاکتور | day | 10 | 50 | ∞ | new `IssueQuote` service (create + QUO number + consume in one tx; fixes the out-of-tx counter at `PosController:209`). A quote that converts then consumes `sales.invoices` too — two units for one sale, by design |
| `inventory.units` | دستگاه (IMEI) ثبت‌شده | day | 20 | 150 | ∞ | `UnitStateMachine::recordAcquisition` — purchase receive **and** trade-in (or a trade-in is a loophole) |
| `catalog.products` | کالای جدید | day | 20 | 100 | ∞ | `ProductController@store` (+tx); `ProductImporter` with `n = counts[create]` |
| `purchasing.invoices` | فاکتور خرید دریافت‌شده | day | 5 | 30 | ∞ | `ReceivePurchaseInvoice::receive` (drafts free) |
| `repairs.tickets` | قبض پذیرش تعمیر | day | 10 | 60 | ∞ | `TicketIntake::take` |
| `crm.parties` | طرف حساب جدید | day | 20 | 150 | ∞ | `PartyController@store` (+tx); `PartyImporter` with `n = OUTCOME_CREATE` |
| `crm.follow_ups` | پیگیری | day | 10 | 50 | ∞ | `FollowUpController@store` (+tx) |
| `installments.plans` | قرارداد اقساطی | month | 5 | 40 | ∞ | `CreateInstallmentPlan::fromInvoice` |
| `cheques.cheques` | ثبت چک | day | 5 | 40 | ∞ | the future `RegisterCheque` service — no route exists today; the roadmap box stays open |
| `inventory.transfers` | حوالهٔ انبار | day | 0 | 10 | ∞ | `TransferService::dispatch` (drafts free). `0` renders as «۰ در روز» with an upgrade CTA; the screen stays visible, so it is not a module gate by the back door |
| `inventory.stock_counts` | انبارگردانی | month | 1 | 4 | ∞ | `StockCountService::apply` |
| `treasury.transfers` | انتقال بین حساب‌ها | day | 5 | 30 | ∞ | `TransferBetweenAccounts::transfer` |
| `treasury.cash_transactions` | ثبت هزینه/درآمد | day | 10 | 60 | ∞ | `RecordCashTransaction::record` when `generatedKey === null` — no screen yet; box stays open |
| `treasury.recurring_templates` | الگوی تکراری | total | 3 | 20 | ∞ | computed; no route yet |
| `treasury.rental_contracts` | قرارداد اجاره | total | 1 | 10 | ∞ | computed; no route yet |
| `messaging.sms` | پیامک | day | **0** (free) / 20 (paid) | 300 | ∞ (wallet still pays) | `SendSms::send` — suppress, never fail (§4) |
| `messaging.campaigns` | کمپین پیامکی | month | 0 | 4 | ∞ | `SendCampaign::send` + pre-flight on `messaging.sms` |
| `reporting.exports` | خروجی اکسل | day | 3 | 20 | ∞ | every `*ReportController@export`, counted **after** a successful build |
| `storefront.price_list_links` | لینک لیست قیمت | total (live) | 1 | 5 | ∞ | `PriceListAccess::mint` |
| `files.attachments` | پیوست | day | 20 | 200 | ∞ | `FileStore::attach` |
| `files.storage_mb` | فضای ذخیره‌سازی | total | 500 | 5,000 | 50,000 | computed `SUM(attachments.size_bytes)` |
| `identity.users` | کاربر فعال | total | 2 | 6 | 25 | computed: active users + pending invitations; checked at invite and reactivate, **not** at accept (the seat was reserved at invite) |
| `inventory.branches` | شعبه | total | 1 | 3 | ∞ | computed: live branches; the default branch counts |

One key, one window. A burst allowance («۱۵۰ در روز ولی نه بیش از ۲٬۰۰۰ در ماه») is a
*second key* with its own row, never a second window on one key. Ship without it; add it
only if `usage_events` shows busy-day blocks converting to churn rather than upgrades.
Enterprise keeps finite operational ceilings on the three cost-bearing totals (users,
storage) — lifted per shop by an override, never sold as a lever. **All numbers are
Filament data**; the table is the seed for a fresh install and the Gate 6 proposal.

### 2. Metric registry — shared kernel, filled by the owning module

`App\Support\*` is the established home for cross-module kernel code (`Counters`,
`Documents`, `Timeline`, `Tenancy`); modules import it without crossing a boundary and
Platform implements it. Nothing under `app/Modules/<X≠Platform>` imports
`App\Modules\Platform\Services\Quota`, and `App\Support\Quota` imports no module —
`tests/Arch/QuotaBoundariesTest.php` fences both.

```
app/Support/Quota/
  Metric.php           final readonly value object (key, labelFa, window, module, kind,
                       measure closure for Computed, unitFa, position, landing)
  Window.php           enum: Day | Month | Total
  MetricKind.php       enum: Counted | Computed
  MetricRegistry.php   singleton (AppServiceProvider); register() throws on a duplicate key
                       or a key not prefixed by "<module>."
  QuotaGuard.php       the contract (§4)
  QuotaVerdict.php     DTO: metric, used, limit, requested, periodKey, resetsAt, allowed,
                       nextPlanCode
  QuotaExceeded.php    exception carrying the verdict
  NoQuota.php          null object: never refuses, counts nothing
  ShopClock.php        period keys (§6)
  Events/{QuotaWarning,LimitReached}.php
```

Each module registers its metrics in its provider with `afterResolving(MetricRegistry)`,
so provider discovery order is irrelevant (the `bindIf` lesson applied to a registry).
Because `afterResolving` fires on the first build only, **no provider's `register()` and
no Filament discovery may resolve the registry during the register phase**; a boot-time
assertion (`quota:audit`, also run in the Pest suite) checks that every module's expected
keys are present. Platform never lists metrics: the Filament limits editor, the landing
rows, the billing page, the `usage` prop and the analytics widgets all iterate the registry.
**A metric with no `plan_limits` row on a plan is unlimited on that plan**, so a module can
ship a new metric without a data migration; Filament shows the missing row in red.

**Counted vs Computed.** Counted metrics (`day`/`month`) are the `usage_counters` row for
the current period, incremented inside the creating transaction. Computed metrics
(`total`) have no row; usage is the module's closure over live rows, checked under a
transaction-scoped advisory lock (§4). `consume()` takes `n ≥ 1` only — no negative deltas;
if storage ever moves from Computed to Counted it gets a separate `release()`.

`plan_limits` keeps its shape (`key` stays `varchar(255)`, `value` nullable bigint,
unique `(plan_id, key)`, central); `key` becomes any registered metric key and the window
lives on the metric, so Filament cannot put one key on two windows. Legacy keys: `users →
identity.users`, `branches → inventory.branches`, `storage_mb → files.storage_mb`;
`invoices_per_month` deleted; `sms_credit_bonus` deleted after being copied to
`plans.sms_credit_grant_count` (an SMS *count*, for the SMS-package phase).

### 3. Data model

All migrations live in `app/Modules/Platform/database/migrations/`, use
`EnablesRowLevelSecurity` and enable RLS **in the same migration** (golden rule 1).

**`usage_counters`** — the meter.
`(id, tenant_id FK, metric varchar(48), period_key char(10), used bigint unsigned default 0,
blocked_at timestamptz null, first_used_at, last_used_at)`; unique
`(tenant_id, metric, period_key)`; index `(tenant_id, period_key) INCLUDE (metric, used,
blocked_at)` for the "today's meters" read. `enableRls(..., allowPlatform: true)`.

**`tenant_limit_overrides`** — negotiated deals.
`(id, tenant_id FK, metric, value bigint unsigned null [NULL = unlimited], reason varchar(200)
required, expires_at null, created_by FK platform_users null, timestamps)`; unique
`(tenant_id, metric)`. Written only from Filament; `allowPlatform: true`.

**`usage_events`** — the analytics ledger.
`(id, tenant_id FK, metric, kind varchar(16) [warning | blocked | bulk_blocked |
upgraded_after], period_key, used, limit_value null, requested unsigned int default 1,
plan_code, user_id null (loose), created_at)`; unique `(tenant_id, metric, period_key,
kind)` makes "one warning and one block per metric per period" a race-free idempotent
insert; indexes `(tenant_id, created_at)` and `(kind, created_at)`. `allowPlatform: true`.

**Tenancy of the three tables — the `Subscription` pattern, exactly.** Their models do
**not** use `BelongsToTenant`: `TenantScope` adds `1 = 0` when no tenant is pinned, so a
trait-scoped model read under `runAsPlatform()` (which pins `app.platform`, never
`app.tenant_id`) returns zero rows and Filament's usage page would be silently empty.
Instead: listed in `TenancyCheckCommand::PLATFORM_OWNED_TABLES` (trait-exempt, RLS still
required and checked), **every query carries an explicit `where('tenant_id', …)`** — made
mechanical by a grep gate over `UsageCounter|TenantLimitOverride|UsageEvent::query()` —
tenant-side writes come only from `DatabaseQuotaGuard` with `TenantContext::idOrFail()` in
hand, Filament reads run inside `PlatformPanelContext`, and all three join both datasets of
`PlatformBillingIsolationTest` ("the platform flag opens a list, not a blanket").

**Changed tables:** `tenants + entitlement_version unsigned int default 1`;
`subscriptions + scheduled_plan_id FK null, + plan_changed_at null`;
`plans + sms_credit_grant_count unsigned int default 0`; `modules + is_enabled bool default
true`. `plan_module`, `subscription_addons`, `modules.is_addonable/addon_price` are
untouched in `0.15.0` and dropped in `0.16.0`.

**Period keys (§6 for the clock).** `Window::Day` → the Gregorian `Y-m-d` of the Tehran
wall-clock day (`2026-08-29`). `Window::Month` → **the Gregorian date of the first day of
the Jalali month** (`2026-08-23` for ۱۴۰۵/۰۶). Both are 10-char Gregorian dates: fixed
width, sortable, computable in SQL by `ShopClock::dayOf()`, never accidentally rendered in
Persian digits, one retention cutoff, and **no Jalali string is ever stored** (golden rule
5's letter is kept, not amended). Jalali is a display concern.

**Retention.** `usage_counters` 400 days; `usage_events` forever (≤ 4 rows per metric per
tenant per period, and it is the pricing signal). `quota:prune` is `@platform-wide`,
weekly, batched by id, scheduled from the first PR that creates the table, with a
`HealthCheck` line «last prune ran at …» — every unscheduled sweep in this repo's history
was discovered by its absence.

**Why a stored total is not a golden-rule-3 violation.** `usage_counters.used` is updated
in place. Rule 3 governs money and stock, where the total is the *truth* and must be
derivable; a quota meter is plan-relative, monotone within its period, and has
`usage_events` as its ledger of the moments that matter. A reviewer will ask; this is the
answer.

### 4. Enforcement path

**Contract.**

```php
interface QuotaGuard
{
    /** Read-only, no lock, never throws. Pre-flight UX, dry-runs, the shared prop. */
    public function check(string $metric, int $n = 1): QuotaVerdict;

    /**
     * Atomically reserve $n against the current period. MUST run inside an open
     * transaction — the one that writes the counted row wherever one exists — so a
     * rollback of that write rolls the reservation back.
     * @throws QuotaExceeded       used + n > effective limit; nothing written
     * @throws OutsideTransaction  no open transaction
     */
    public function consume(string $metric, int $n = 1): QuotaVerdict;

    /** Automated paths (sweeps, automations): counts if it fits, never throws. */
    public function record(string $metric, int $n = 1): QuotaVerdict;

    /** Current-period meters for every Counted metric, for the Inertia prop. */
    public function snapshot(): array;
}
```

Binding follows the `PartyExposure` precedent: `AppServiceProvider::register()` does
`bindIf(QuotaGuard::class, NoQuota::class)`; `PlatformServiceProvider::register()` does
`singleton(QuotaGuard::class, DatabaseQuotaGuard::class)` (`bootstrap/providers.php`
orders `AppServiceProvider` first, so the default always lands first and Platform's
singleton overwrites it). Implementation lives in
`app/Modules/Platform/Services/Quota/{DatabaseQuotaGuard,LimitResolver,UsageEvents,
UsageSnapshot}.php`.

**The atomic write — one `INSERT … ON CONFLICT DO UPDATE … WHERE` per consume.** Chosen
over `SELECT … FOR UPDATE` + create-on-miss because it is one round trip, has no
first-use 23505 race (`ON CONFLICT` *is* the arbiter — `CounterService`'s create-on-miss is
a latent outer-transaction abort), and the `WHERE` makes check-and-increment one statement
under Postgres's row-level serialisation. Two statements, not one with a nullable bind:
binding an untyped PHP `null` fails on Postgres with *could not determine data type of
parameter*, and the unlimited case must never refuse.

```sql
-- SQL_UNBOUNDED (limit NULL): count, never refuse
INSERT INTO usage_counters (tenant_id, metric, period_key, used, first_used_at, last_used_at)
VALUES (?, ?, ?, ?, now(), now())
ON CONFLICT (tenant_id, metric, period_key)
DO UPDATE SET used = usage_counters.used + EXCLUDED.used, last_used_at = now()
RETURNING used;

-- SQL_BOUNDED: both arms capped in SQL. Every select-list placeholder carries an explicit
-- cast — Postgres cannot infer a type for a parameter that is not in an INSERT … VALUES
-- column position (adversarial check c2).
INSERT INTO usage_counters (tenant_id, metric, period_key, used, first_used_at, last_used_at)
SELECT ?::bigint, ?::varchar, ?::varchar, ?::bigint, now(), now()
WHERE ?::bigint <= ?::bigint                                   -- insert arm: n <= limit
ON CONFLICT (tenant_id, metric, period_key)
DO UPDATE SET used = usage_counters.used + EXCLUDED.used, last_used_at = now()
WHERE usage_counters.used + EXCLUDED.used <= ?::bigint         -- update arm: used + n <= limit
RETURNING used;
-- binds: [tenantId, metric, periodKey, n, n, limit, limit] — 2 strings, 5 integers
```

Zero rows back means refused and nothing written; the guard then reads `used` with a plain
`SELECT` for the verdict and throws `QuotaExceeded`. Correctness: two transactions at
`used = limit−1` conflict on the unique index; the second waits on the first's tuple, then
in READ COMMITTED re-evaluates the `DO UPDATE … WHERE` against the committed value — one
succeeds, one is refused. Two first-of-day transactions: one inserts, the other takes the
conflict path; no 23505 ever escapes, so the savepoint rule is not triggered here. The
increment lives in the caller's transaction, so a rolled-back sale rolls the reservation
back; a refused consume throws inside the caller's `DB::transaction`, which unwinds the
domain writes. RLS: session-scoped `app.tenant_id` makes `WITH CHECK` pass on the inserted
row and `USING` on the conflicting one — no `runAsPlatform()` on the write path. The
**insert arm on a fresh period runs in `ConsumeTest` under the CI `NOBYPASSRLS` role**,
so a prepare-time type error is caught, not just the update arm.

**Where `consume()` sits.** Inside the transaction, **after any `lockForUpdate()` and
after `CounterService::nextFormatted()`, at the point where `n` is known** — importers
consume *after* their loop (the create count only exists then; the SQL `WHERE` still
guarantees the cap and the rollback unwinds the rows), single documents consume right
before the domain insert. Lock order counters → usage_counters → domain rows is enforced by
`bin/check-quota-lock-order` (`nextFormatted(` must precede `->consume(` in the same
closure). "Must run inside a transaction" is only a runtime throw at `transactionLevel()
=== 0`, which is **inert inside `RefreshDatabase` tests** (level is already ≥ 1) — so the
Pest fixture binds a spy guard that records the level at each `consume()` and `afterEach`
asserts every recorded level ≥ 2, and `bin/check-quota-in-transaction` requires every
`->consume(` in `app/Modules` to sit lexically inside a `transaction(` closure. Two named
exceptions to "the transaction that writes the counted row": `reporting.exports` (no
counted row — a transaction is opened for the consume, after the workbook built
successfully) and `messaging.sms` (below).

**Computed totals.** `SELECT pg_advisory_xact_lock(hashtext('quota:' || ?::text || ':' ||
?))` on `(tenant_id, metric)`, then `measure(tenantId) + n <= limit` else `QuotaExceeded`.
Transaction-scoped, RLS-agnostic, the first advisory lock in the repo; a hash collision
merely serialises two unrelated writes. Sites: `UserController@invite` (+tx, counts
pending), `UserController@toggleActive` on reactivation (+tx — otherwise
deactivate → invite → reactivate bypasses the cap), `BranchController@store` (+tx),
`FileStore::attach` (bytes), `PriceListAccess::mint` (+tx), the two Treasury totals when
their screens exist. **Invitation accept does not check**: the seat was reserved at invite,
and the accepting invitation is already inside the measure.

**Bulk — all-or-nothing, one reservation.** Importers and IMEI receive reserve `n` once
with the exact create count; the statement accepts or refuses the whole `n`; the
transaction is the file's. `n = 0` skips the consume. The dry-run endpoints
(`catalog.import.analyse`, `crm.import.dry-run`, `purchasing.invoices.imeis.parse`) return a
`quota` field `{metric, needed, remaining, next_plan}` so the UI warns before the commit
button; the commit still consumes atomically. Campaigns pre-check `audience ≤ remaining`
and refuse whole; they do not pre-consume — jobs consume one by one when they run.

**Automated and event-driven creates, decided per class.**

| class | decision |
|---|---|
| Provisioning listeners (`SeedPriceLevels`, `CreateDefaultLocation`, `CreateDefaultAccount`) | exempt — run before a subscription exists |
| Trade-in unit (`TradeInIntake`) | **counts** under `inventory.units` |
| Repair-delivery invoice (`DeliverTicket` → `FinaliseInvoice`) | **exempt** (`metered: false`) — the ticket was counted at intake; refusing a customer their own repaired phone at 30/30 is a one-star review, and the loophole is worth at most a repair-shaped sale |
| Automation SMS and `DailyMessagingSweep` | **count** via `record()` — suppressed, never failing the parent action |
| System SMS (`quota.*` owner alerts today; password-reset and invitation SMS when Phase 8 wires them; 2FA is TOTP and never sends SMS) | exempt via `SendSms::send(..., systemMessage: true)`; **the platform pays**, with a hard per-tenant daily cap (`config('hamyar.quota.system_sms_daily_cap')`) so an alert loop cannot bill us |
| `GenerateRecurring` rows, `AbandonedSweep` escalations, Moadian enqueue/cancel, HAMTA flags, ledger/stock/history/reservation rows, returns, voids, quote conversion | not metered; voids and returns **never refund** (the gapless number was consumed; void-and-reissue would game the meter) |

**`messaging.sms` is the one metric whose refusal never throws.** `SendSms::send` runs
`record()` as today (the `Message` row commits first — the second named exception), then
one small `DB::transaction` doing **charge → consume** in that order, with wallet refusal
throwing so the closure rolls back and a wallet-empty send cannot leak a quota unit; the
`try` sits outside `DB::transaction()` (CLAUDE.md savepoint rule). On either refusal the
message is marked `suppressed` with the matching reason («سقف پیامک روزانهٔ پلن پر شده
است») and the method returns.

**Middleware pre-check is a courtesy, never the guarantee.** `EnsureQuotaAvailable`
(`quota:<metric>[,<n>]`, after `EnsureModuleEnabled` in the priority list) calls `check()`
on non-GET create routes and throws the same `QuotaExceeded` before any work. It is not on
the polymorphic POS route (`action = park|quote|finalise` would refuse parking a basket) —
the service check is authoritative there — and it cannot see the three request paths
outside the `tenant` group (invitation accept, password reset, public price list) or any
job. A test asserts every `quota:` route key is registered.

**Reads are never blocked.** No GET consults the guard except to render meters. Public
receipt, tracking, price list, printing, report screens and export previews stay open
under any quota state and any lapse.

### 5. Resolution and caching

`LimitResolver::for(tenantId, metric)`:

1. an unexpired `tenant_limit_overrides` row → its value (NULL = unlimited); wins over
   everything, trial included;
2. effective plan = the usable subscription's plan, else the fallback limit set
   (`config('hamyar.quota.fallback_plan')`);
3. `TrialPolicy::apply()` while trialing (only forces `messaging.sms`; paid-basic path
   only);
4. the `plan_limits` row → value (NULL = unlimited);
5. no row → unlimited, `Log::warning` once per process, Filament shows the gap in red.

**If the fallback plan resolves to no `plans` row, the resolver throws `FallbackPlanMissing`
— always, tests included.** The lenient reading ("no plan → unlimited") would make a
lapsed shop fail *open*, the opposite of golden rule 1's ethos, and in tests it would let
a suite that forgot its fixture pass unmetered. The ~28 suites that create tenants with no
subscription gain a `subscribe()` line or the explicit `withUnlimitedQuota()` opt-in; a
forgotten fixture fails loudly. `nextPlanFor(metric, needed)` = the first public plan by
position whose limit is NULL or ≥ needed.

**Memo and cache (ADR 0012).** One durable integer, `tenants.entitlement_version`, bumped
by `SubscriptionActivated` (listener `ForgetEntitlements`), `subscriptions:expire`,
`subscriptions:apply-scheduled`, an override save, and a Filament plan-limit save (one
`UPDATE tenants … WHERE id IN (SELECT tenant_id FROM subscriptions WHERE plan_id = ?)`).
`LimitResolver` memoises `array<int tenantId, array{version, limits}>` per process —
tenant-keyed as ADR 0012 demands — and validates it against the version. **Every bump is
write-through**: `Cache::put("t:{$tenantId}:ev", $version)` after the `UPDATE`, so a web
request sees a Filament edit on the next request and a Horizon worker on its next job;
limits are cached as `t:{$tenantId}:limits:v{$version}` for 10 minutes. If Redis is
unavailable the resolver re-resolves every request — never "keep the memo". The memo key
also carries the effective plan code and the usable flag, so a lapse that happens by the
clock (no bump yet) is seen the moment the subscription is re-read. `SubscriptionResolver`
gets the same listener (its docblock already promises it) and its `limit()` is deprecated
in favour of `LimitResolver`. `usage_counters` reads are never cached.

**Hot-path cost, stated honestly.** Any staff shell page: one small index-range `SELECT`
over `(tenant_id, period_key)` returning ≤ 2 × metrics rows, covering via `INCLUDE` and
index-only between vacuums (`used` is hot-updated); limits from the memo. `level:
'blocked'` comes from the denormalised `usage_counters.blocked_at`, not a second table.
POS partial reloads use `except('usage')`; totals are deferred props on `/billing` and the
dashboard, so `/dashboard` (the p95 offender) gains no synchronous query beyond the
shell's one. A metered write: one statement. A refused write: +1 `SELECT`, +1 event insert
after rollback. Middleware pre-check: one `SELECT` on guarded POSTs.

### 6. Time

`App\Support\Quota\ShopClock` (Reporting's `Support\ShopClock` moves here; Reporting keeps
a thin delegate so its tests stay put). Constructed with the timezone
(`config('app.display_timezone')` today; `tenants.settings.timezone` later — because keys
are computed, not stored with a zone, a timezone change simply starts a new bucket).
`periodKey(Window)` is computed **at consume time, inside the transaction, from the wall
clock** — never from `occurred_at` or any user-supplied date: a backdated expense counts on
the day it was typed; a delayed campaign job counts on the day it actually sends.
`resetsAt(Window)` is a UTC instant for the UI (`Jalali::startOfMonth(Jalali::addMonths(
$nowLocal, 1))` for months — both helpers exist). Boundary: `00:00 Asia/Tehran` = 20:30 UTC
through the tz database, never a fixed offset (Iran has had no DST since 2022, and the
database knows). Tests `freezeTime()` in `beforeEach` and `travelTo` 23:55 Tehran, exhaust,
`travel(10)->minutes()`, assert a new row; the control case at 00:25 → 00:35 UTC asserts
**no** rollover; the month case crosses the last day of Shahrivar.

### 7. UX

**Shared prop `usage`** (lazy, staff-only, `[]` outside a tenant, like `branch`):
`{ plan: {code, name, lapsed, trial_ends_at}, next_plan: {code, name, price} | null,
meters: UsageMeterState[], attention: string[], can_upgrade: bool }` with
`UsageMeterState = { key, label, unit, module, used, limit | null, window, resets_at,
level: 'ok' | 'warning' | 'reached' | 'blocked' }`. Mirrored by hand in `types/index.d.ts`.

**Being blocked is error-bag shaped, never a 4xx page.** The repo has no Inertia error
page (a 403 from a POST reaches the shopkeeper as Inertia's English iframe modal), and
domain failures already arrive as `back()->withErrors([...])`. `QuotaExceeded` is mapped
centrally in `bootstrap/app.php`: `back()->withErrors(['quota' => $message])->with(
'quota_block', $payload)` (422 JSON with `errors.quota` for non-Inertia JSON). **The block
is rendered once, in the shell** — `quota_block` is shared as its own prop and
`<QuotaBlock>` sits in `app-shell.tsx` beside `AnnouncementBanner` — because the
adversarial check found that roughly 25 forms render only field-keyed errors, so an
error-bag key alone would vanish on them. Retrofitting those forms with a shared
`<FormErrors>` domain component is its own task (the CLAUDE.md "a home for errors that
belong to no field" rule), not something the quota path depends on. POS additionally
prepends `errors.quota` to its `blockingError` chain, and **parks the draft on refusal**
(the draft is created outside the finalise transaction) so the basket survives the upgrade
round trip. Copy, Persian digits, one sentence plus one action:

> «سقف روزانهٔ **۳۰ فاکتور فروش** در پلن **پایه** پر شده است. پلن **حرفه‌ای** تا ۱۵۰ فاکتور
> در روز را پوشش می‌دهد؛ سهمیهٔ امروز فردا ساعت ۰۰:۰۰ تازه می‌شود.»

`<QuotaBlock>` shows the sentence, the `UsageMeter` for that metric and — when the user has
`billing.manage` — a primary button «ارتقا به حرفه‌ای — ۲٬۴۰۰٬۰۰۰ تومان تا پایان دوره» whose
amount comes from `ProrationCalculator::preview()` (ADR 0006, one source of truth) and
posts to `route('billing.subscribe', $nextPlan)` with `return_to`. **`return_to` is
persisted on `payment_attempts` at initiate time** (the gateway callback arrives with no
session) and validated as a same-host relative path (it is otherwise an open redirect);
`BillingController` honours it after `applyPayment`, landing the operator back on the same
form with «پلن حرفه‌ای فعال شد؛ ادامه دهید». Without the permission: «از مدیر فروشگاه
بخواهید پلن را ارتقا دهد». Secondary: «فردا ادامه می‌دهم» with `resets_at`.
`Plan::getRouteKeyName()` returns `'code'` (`plans.code` is unique).

**Meters.** `UsageMeter` (label, «<Num used/> از <Num limit/> <unit>» or «نامحدود», a
hand-rolled `role="meter"` bar, logical classes only) lands on `/design` first with the
ok / warning / reached / blocked / unlimited / total states. Thresholds: amber at
`used ≥ ceil(0.8 · limit)` with a one-time `flash.warning` toast per metric per period from
the consume path; **red only after a block actually happened** — a shop that sold exactly
its cap and went home is not shouted at. `UsageBanner` in the shell's `no-print` block
renders only when `attention` is non-empty or the plan is lapsed. The dashboard gets a
«سهمیهٔ امروز» row of compact meters as a deferred prop; the billing page shows every meter
on the current-plan card and each `PlanCard` lists `limits` instead of `modules`.

**Landing.** `pricing.blade.php` keeps its ladder-of-rows markup, CSS classes and the
`landing.js` contract; `.tariff__included` lists the headline quotas from `plan->limits`
joined to the registry («<b class="nums">۳۰</b> فاکتور در روز» …, enterprise «نامحدود»)
under «سقف روزانه»; the add-on shelf and `closing.blade.php`'s per-plan framing go; the
lede becomes «همهٔ امکانات برای همه؛ فقط سقف روزانه فرق می‌کند». `terms.blade.php`'s add-on
sentence is rewritten. **The catalogue flip and the landing rewrite merge back-to-back and
release once**, so the site never advertises add-ons the code no longer honours.

**Filament.** `PlanForm`'s limits repeater is pre-filled with one row per registry metric
(label, unit, window badge read-only, nullable value = «نامحدود», missing rows in red);
the modules checklist goes; saving fires `PlanLimitsChanged` → version bump for every
tenant on the plan. `ModuleForm` gets `is_enabled`; `PlansTable` loses `modules_count`;
`ModulesTable` loses the add-on columns. `TenantResource` gets `LimitOverridesRelation-
Manager` («سقف‌های اختصاصی», reason required) and a «مصرف» page: today's and this
month's counters against the effective limit, a 30-day sparkline per headline metric
computed in SQL with `ShopClock::dayOf()` (possible because keys are Gregorian), and the
last 20 `usage_events`. Dead surfaces removed in passing: `ListSubscriptions`'s
`CreateAction`, `TenantForm`'s `is_active` toggle (no column, no handler).

### 8. Events and analytics

`QuotaWarning` is dispatched `DB::afterCommit` from `consume()` when the 80 % line is
crossed and recorded once per period with the savepoint-rule insert (`try { DB::transaction(
insert) } catch (UniqueConstraintViolation) {}`). `LimitReached` is dispatched by
`UsageEvents::blocked()` **from the exception handler, after the refused transaction has
fully unwound** — or from `SendSms`'s suppression branch — never via `afterCommit` inside
the rolling-back transaction, which Laravel discards. The `blocked` / `bulk_blocked` row
carries `requested`, and sets `usage_counters.blocked_at`. `SubscriptionActivated` also
writes `upgraded_after` carrying the metric of the most recent `blocked` row within seven
days — conversion attribution without a join.

Messaging listens with `quota.warning` / `quota.reached` automations (default off, like
the others; the banner is the primary channel), owner's mobile, idempotency key
`quota:{tenant}:{metric}:{period}:{kind}`, `systemMessage: true`, link from
`config('app.domain')` (rule 1b). `SubscriptionRenewalDue` finally gets its Messaging
listener in the same PR, and `SendRenewalReminders`' copy changes from "renew or lose
access" to "renew or fall to پایه quotas". Filament widgets: `QuotaPressure` (distinct
shops blocked in the last 7 days, by metric — «کدام سقف بیشترین ارتقا را می‌فروشد») and
`QuotaConversion` (blocked → upgraded within 7 days, and days-from-signup to first block).

### 9. Migration from today

- **PR 1, before the gate, bug fix only:** `applyPayment()` writes `plan_id` and
  `plan_changed_at` from a `plan_id` now stored on the invoice; `ForgetResolvedSubscription`
  on `SubscriptionActivated`; `Plan::getRouteKeyName()`; `billing/index.tsx` posts by code;
  `BillingPaymentTest` posts to `billing.subscribe` over HTTP for the first time and asserts
  the plan changed. Scheduled downgrade, `entitlement_version` and `subscriptions:expire`
  are Phase 12 decisions and wait for the gate.
- `SubscriptionResolver::grants()` → `Module::isEnabledPlatformWide()`;
  `grantedModuleCodes()` → every enabled code for usable **or lapsed** tenants;
  `Subscription::grantedModuleCodes()` / `addons()` deprecated in `0.15.0`, deleted in
  `0.16.0`; `ProrationCalculator` / `invoiceForPlan()` checked for add-on lines.
- `TrialPolicy`: `BASELINE_PLAN_CODE` and the borrowed list deleted; survives only on the
  paid-basic path as `FORCED = ['messaging.sms' => 10]`. `TenantProvisioner::startTrial()`
  becomes `startOnFreePlan()` (free path) or keeps `pro` with a rewritten comment (paid).
- **Seeding — "seed once, never overwrite" kept, with one backfill mechanism.** The
  one-off data migration renames the three legacy keys, deletes `invoices_per_month`, and
  copies `sms_credit_bonus` into `plans.sms_credit_grant_count` before deleting it. It does
  **not** insert new keys: `PlanCatalogueSeeder::sync()` already backfills every missing
  `(plan, key)` with `firstOrCreate` on every run, and a second inserter would drift. A new
  `platform:sync-limits --force` is the only path that *overwrites* existing values, and
  it is never automatic. The migration gets a test that seeds legacy rows and runs the
  class directly, because `RefreshDatabase` runs it on empty tables.
- `SeedPlatformVolumeCommand` subscribes load-test shops to `enterprise` (or writes an
  unlimited override), so the k6 suite in `docs/load-testing.md` is never refused mid-run
  and its p95 stays comparable with `docs/load-tests/2026-08-20.md`.
- Tests that change (from the map): `PlanGatingTest` splits into `ModuleKillSwitchTest`
  and `PlanLimitsTest`; `DashboardTest:127-150` (a basic owner now sees every widget);
  `PriceListSecurityTest:47-60` and `MoadianSubmissionTest:54-70` drop their
  `SubscriptionAddon` fixtures; `AdminPanelTest` (`PlansTable` column, limits editor,
  overrides, usage page); `ApplicationShellTest`, `PublicInvoiceTest`, `PublicTrackingTest`
  add `usage === []` on public pages. **The 62 `subscribe($t, 'pro')` files stay
  byte-identical and metered** — real behaviour under Pro's finite caps, so every existing
  route and isolation test proves the guard is wired. `withUnlimitedQuota()` is an explicit
  opt-in for suites whose volume genuinely exceeds a cap through a metered path; it is never
  the default, because a default-off guard lets a future create path that forgets
  `consume()` pass every test.
- `laravel/pennant` is unused anywhere in `app/` (the "Pennant `limit:<key>` flags" of
  roadmap 2.3 were never written); removing it is a dependency change and a gate item.
- Versions: the module-open flip and landing rewrite are `0.15.0` with a `BREAKING`
  changelog prefix (an existing plan row's meaning changes); the table drop is `0.16.0`.

## Alternatives considered

- **Count from the domain tables** («ledgers, not totals», the repo's reflex). Rejected:
  soft deletes, hard-deleted-and-recreated children, drafts-in-the-same-table and
  voids-that-keep-rows make a live `COUNT(*)` wrong in every module; the meter would need
  a per-module SQL fragment that drifts from the domain, and it would put an aggregate on
  every create. The counter row is the ledger's *summary*; `usage_events` is its ledger.
- **Reuse the `counters` table.** Rejected: its semantics are *sequence* (gaps on rollback
  are deliberate, numbers never reset), its create-on-miss is not savepoint-wrapped, and
  it has no cap check. The lock discipline is reused; the rows are not.
- **Redis counters** (`INCR` with a TTL). Rejected: not transactional with the domain
  write (a rolled-back sale would stay counted, a crashed request would not), not covered
  by RLS, a second source of truth to reconcile, and lost on a flush. Redis is used for
  the limit memo only, where staleness is bounded and harmless.
- **`SELECT … FOR UPDATE` + create-on-miss.** Rejected in favour of `ON CONFLICT`: two
  round trips, a first-use 23505 race that needs a savepoint, and check-then-write in PHP.
- **Pennant `limit:<key>` flags** (roadmap 2.3's wording). Rejected: a boolean feature
  flag cannot carry a count; Pennant is installed and unused; the registry is the flag.
- **Jalali strings as month keys** (`1405-06`). Rejected for the reason golden rule 5
  exists, and because a mixed-format key column breaks a single retention cutoff
  (`'1405-06' < '2025-…'` lexically, so the first prune would delete every month row).
- **A 4xx page for "limit reached".** Rejected: the app has no Inertia error page, the
  existing error path is the error bag, and the block must carry the upgrade CTA back to
  the form the shopkeeper was on.
- **Middleware as the guarantee.** Rejected: three request paths run outside the `tenant`
  group, jobs have no middleware, and a check outside the transaction cannot be atomic.
- **Delete `module:` middleware and `features`.** Rejected: a 20-file diff for no
  behaviour, and ADR 0011 needs a platform kill-switch.
- **Per-day caps for everything.** Rejected for stock counts, installment contracts and
  campaigns, which a shop does on a monthly rhythm — a per-day cap there reads as arbitrary.
- **Default-off guard in tests** (`subscribe()` binds `NoQuota`). Rejected: a create path
  that forgets `consume()` would pass every test forever.

## Consequences

- **Positive.** One statement per metered write; no race can exceed a cap; a rollback
  cannot leave a phantom count; upgrades unlock instantly without touching counters; every
  module can add a metric without Platform knowing; the landing, the billing page,
  Filament and analytics all read one registry; the first real pricing signal the product
  will have (`usage_events`) exists from day one; the never-enforced `users` and `branches`
  caps finally bite; two latent billing bugs are fixed on the way.
- **Negative, accepted.** A busy honest day is punished by a day cap — «فردا ادامه می‌دهم»,
  the second-key burst allowance and a future day-pass override are the levers, and
  `usage_events` will say whether it is happening. Automations can starve the operator's
  SMS on the small plan; the message log explains «چرا نرفت», and a second key is promised
  if it bites. A long transaction (a 1,000-IMEI receive) holds the `inventory.units` row
  until commit. Advisory locks for Computed totals are invisible to `tenancy:check`, so a
  future user-creation path that forgets `assertCapacity()` bypasses the seat cap silently
  — `quota:audit` and the enforcement-site tests are the mitigation. Every shell page pays
  one small query. The Filament limit editor can leave a metric unlimited on `basic` by
  omission; red rows and `quota:audit` mitigate.
- **Binding once accepted.** `QuotaGuard::consume()` is the only way a metered row is
  counted; a metered create path that does not call it is a bug of the same class as a
  tenant table without RLS. `ProrationCalculator` remains the only proration arithmetic.

## Approved by

Not yet. Bound for **DECISION GATE 6** in `../ROADMAP.md` (Phase 12), which lists the
sixteen items and the recommendation for each. This ADR becomes Accepted when the gate is
cleared and the roadmap block records what was approved.
