<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\BrowserTestCase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test case bindings
|--------------------------------------------------------------------------
|
| Feature tests (app-level and module-owned) get the full application and a
| refreshed database.
|
| Unit tests get the application but NOT the database: the money and date helpers
| read configuration (display timezone, currency unit), so they need a container —
| but nothing in tests/Unit is allowed to touch a table. Keeping RefreshDatabase off
| here is what keeps the unit suite sub-second.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature', '../app/Modules');

pest()->extend(TestCase::class)->in('Unit');

// Arch tests assert on the module layout on disk and on provider discovery, both of
// which resolve paths through the container.
pest()->extend(TestCase::class)->in('Arch');

/*
| Browser tests (roadmap 11.1b) get the application and a refreshed database like any
| feature test — the page under test is rendered from seeded rows, so without the
| database they assert the shape of an empty screen.
|
| They are NOT in the `Feature` binding above because they carry their own `browser`
| group and are excluded from the default run: each one starts a real Chromium, and a
| suite that pays a browser launch per test is one people stop running.
*/
pest()->extend(BrowserTestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Browser');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
*/

/**
 * Golden rule 2: money is an integer number of rial. A float here means someone
 * introduced arithmetic that will eventually be off by a rial on a tax invoice.
 */
expect()->extend('toBeRial', function () {
    expect($this->value)->toBeInt();

    return $this;
});

/**
 * Golden rule 5: timestamps cross the wire and land in the database as UTC.
 */
expect()->extend('toBeUtc', function () {
    expect($this->value->timezone->getName())->toBe('UTC');

    return $this;
});

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

/*
|--------------------------------------------------------------------------
| Hosts
|--------------------------------------------------------------------------
|
| The apex domain is not chosen yet (golden rule 1b) and must never be hardcoded —
| including in a test fixture, because a literal here is exactly what makes the
| "it's configurable" claim untrue the day it changes.
|
| Since ADR 0017 there are exactly two hosts, and no helper takes a tenant any more.
| A URL can no longer say which shop it belongs to — the session says it — so the
| replacement for "arrive on that shop's hostname" is `actingForTenant()` below, not a
| third URL builder.
|
*/

/**
 * Absolute URL on the central (apex) host.
 *
 * ## Why this kept its meaning instead of being repointed at the application
 *
 * ADR 0017 moved `/login`, `/register` and the app root off the apex, so it was
 * tempting to redefine this helper as "wherever the app lives now" and leave ~30 call
 * sites untouched. That would have been wrong: the apex is still a real host serving
 * real routes — the public landing and its two legal pages, the Filament panel
 * (`AdminPanelProvider::domain()`) and Horizon (`config('horizon.domain')`) — and
 * several of those call sites exist to prove `/admin` is reachable *there and nowhere
 * else*. Silently repointing the name would have kept them green while they asserted
 * the opposite of what they were written to assert.
 *
 * So the name still means the apex, and everything that moved moved to `appUrl()`.
 *
 * The path defaults to EMPTY, not "/": callers routinely append their own path
 * (`centralUrl().'/terms'`), and a default slash silently produces `//terms`, which 404s.
 */
function centralUrl(string $path = ''): string
{
    return 'http://'.config()->string('app.domain').$path;
}

/**
 * Absolute URL on the application host — `app.<apex>`, one address for every shop
 * ([ADR 0017](../docs/adr/0017-single-host-app.md)).
 *
 * Everything a shop touches is here: `/login`, `/register`, `/dashboard`, every module
 * screen, and every customer-facing token page (`/t/…`, `/a/…`, `/p/…`, `/shop/…`).
 *
 * It takes no tenant, on purpose. The obvious migration from the deleted `tenantUrl()`
 * was to keep the `$tenant` argument and ignore it — one sed and 59 files compile — but
 * a helper that accepts a shop and ignores it is precisely how the isolation suite goes
 * green while asserting nothing, which ADR 0017 names as the failure mode this change
 * must not produce. There is no argument, so a test that means "as shop B" has to say
 * so with `actingForTenant()`.
 *
 * `app.` is a subdomain LABEL, not a hostname: the apex it hangs off still comes from
 * config, so golden rule 1b holds and `bin/check-apex-domain` has nothing to find.
 *
 * Same empty default as `centralUrl()`, for the same reason — `appUrl().'/login'` must
 * not become `//login`.
 */
function appUrl(string $path = ''): string
{
    return 'http://app.'.config()->string('app.domain').$path;
}

/**
 * Pin the current test's session to `$tenant`, the way an isolation test now says
 * "this request is for that shop".
 *
 * ## What it replaces
 *
 * Cross-tenant tests used to express the attack through the URL: request shop B's
 * hostname while holding shop A's credentials. ADR 0017 removed per-shop hostnames and
 * moved the tenant into the session, so the URL cannot express it any more. This
 * helper can, and it is the only thing in the suite that may.
 *
 * ## Why it writes a key production code reserves to one place
 *
 * `LoginController::store()` states the rule that keeps the new boundary sound —
 * *nothing outside the login flow may ever write `tenant_id` into the session*. That
 * rule is about application code. A test needs the opposite power: `ResolveTenant` and
 * `EnsureTenantUser` exist to defend against a session pointing at one shop while its
 * user belongs to another, and nothing can prove they do unless something can build
 * exactly that state. This is that something, and its whole body is the one key they
 * read.
 *
 * ## Order matters, and getting it backwards passes
 *
 * `Tests\TestCase::actingAs()` writes the user's OWN `tenant_id` (correctly — that is
 * what an ordinary authenticated test wants). `withSession()` merges, last write wins,
 * so `actingForTenant($b)->actingAs($userOfA)` quietly restores shop A and the test
 * proves nothing. Sign in first, forge second:
 *
 *     $this->actingAs($userOfA);
 *
 *     actingForTenant($tenantB)->get(appUrl('/sales/invoices'))->assertForbidden();
 *
 * Reach for it only when the session's tenant must differ from the user's, or when
 * there is no user at all. An ordinary authenticated test needs `actingAs()` alone.
 */
function actingForTenant(App\Modules\Platform\Models\Tenant $tenant): TestCase
{
    /** @var TestCase $pinned */
    $pinned = test()->withSession(['tenant_id' => $tenant->getKey()]);

    return $pinned;
}

/*
|--------------------------------------------------------------------------
| The isolation group
|--------------------------------------------------------------------------
|
| Golden rule 8: every tenant-scoped endpoint needs a cross-tenant isolation test.
| They carry the `isolation` group so `composer test:isolation` and the dedicated CI
| job can run them alone — that suite is the one that must never be quietly skipped.
|
| A group is a property of the test *definition*, not of its body, so it is declared
| at the top of a test file:
|
|     pest()->group('isolation');            // whole file
|     it('…', fn () => …)->group('isolation'); // one test
|
*/

/**
 * Run a closure inside a shop's tenant context.
 *
 * Lives here rather than in one test file because three modules' suites need it, and
 * a helper defined in a sibling test file only works by accident of load order.
 *
 * @param  Closure(): mixed  $callback
 */
function inTenantContext(App\Modules\Platform\Models\Tenant $tenant, Closure $callback): mixed
{
    return app(App\Support\Tenancy\TenantContext::class)->runFor($tenant, $callback);
}

/**
 * Spend a credit as a shop, inside a transaction, and hand back the verdict.
 *
 * `runFor()` and `DB::transaction()` both return `mixed`, so without this every quota test
 * would carry its own `@var` annotation to satisfy Larastan — and a suite that annotates
 * its way out of type errors one line at a time is a suite where a real one hides.
 */
function spendQuota(
    App\Modules\Platform\Models\Tenant $tenant,
    string $metric,
    int $units = 1,
): App\Support\Quota\QuotaVerdict {
    /** @var App\Support\Quota\QuotaVerdict $verdict */
    $verdict = app(App\Support\Tenancy\TenantContext::class)->runFor(
        $tenant,
        static fn (): App\Support\Quota\QuotaVerdict => Illuminate\Support\Facades\DB::transaction(
            static fn (): App\Support\Quota\QuotaVerdict => app(App\Support\Quota\QuotaGuard::class)
                ->consume($metric, $units)
        )
    );

    return $verdict;
}

/**
 * How much of one credit a shop has spent, across every period.
 */
function quotaUsed(App\Modules\Platform\Models\Tenant $tenant, string $metric): int
{
    /** @var int|numeric-string $used */
    $used = app(App\Support\Tenancy\TenantContext::class)->runAsPlatform(
        static fn (): mixed => App\Modules\Platform\Models\UsageCounter::query()
            ->where('tenant_id', $tenant->getKey())
            ->where('metric', $metric)
            ->sum('used')
    );

    return (int) $used;
}

/**
 * Does a counter row exist at all? Distinguishes "spent nothing" from "never touched",
 * which is how a refusal that wrote nothing is told apart from one that wrote a zero.
 */
function quotaRowExists(App\Modules\Platform\Models\Tenant $tenant, string $metric): bool
{
    /** @var bool $exists */
    $exists = app(App\Support\Tenancy\TenantContext::class)->runAsPlatform(
        static fn (): bool => App\Modules\Platform\Models\UsageCounter::query()
            ->where('tenant_id', $tenant->getKey())
            ->where('metric', $metric)
            ->exists()
    );

    return $exists;
}

/**
 * Register the quota metrics the guard's own suites meter against.
 *
 * Deliberately not borrowed from a module: the guard's tests must break when the guard
 * breaks, not when Sales renames something. Lives here rather than in a test file because
 * a function defined in one test file does not exist for another that runs before it.
 *
 * Idempotent — the registry is a singleton for the whole process and refuses duplicates.
 */
function registerTestMetrics(): void
{
    $registry = app(App\Support\Quota\MetricRegistry::class);

    if ($registry->has('quota.widgets')) {
        return;
    }

    $registry->register(
        new App\Support\Quota\Metric(
            'quota.widgets', 'ویجت', App\Support\Quota\Window::Month, 'quota', unitFa: 'ویجت'
        ),
        new App\Support\Quota\Metric(
            'quota.unlimited', 'بی‌کران', App\Support\Quota\Window::Month, 'quota'
        ),
        new App\Support\Quota\Metric(
            'quota.seats', 'صندلی', App\Support\Quota\Window::Total, 'quota',
            measure: static function (int $tenantId): int {
                /** @var int|numeric-string $seats */
                $seats = cache()->get("seats:{$tenantId}", 0);

                return (int) $seats;
            },
        ),
    );
}

/**
 * @param  array<string, mixed>  $overrides
 */
function subscribe(App\Modules\Platform\Models\Tenant $tenant, string $planCode, array $overrides = []): App\Modules\Platform\Models\Subscription
{
    $plan = App\Modules\Platform\Models\Plan::query()->where('code', $planCode)->firstOrFail();

    // `subscriptions` is RLS-protected, so selling a plan is a platform act — exactly
    // as it is in TenantProvisioner. If this ever works without the wrapper, the
    // policy has been weakened.
    return app(App\Support\Tenancy\TenantContext::class)->runAsPlatform(fn (): App\Modules\Platform\Models\Subscription => App\Modules\Platform\Models\Subscription::query()->create(array_merge([
        'tenant_id' => $tenant->getKey(),
        'plan_id' => $plan->getKey(),
        'status' => App\Modules\Platform\Models\Subscription::STATUS_ACTIVE,
        'current_period_start' => now()->subDays(10),
        'current_period_end' => now()->addDays(20),
    ], $overrides)));
}

/**
 * The report keys the reporting index rendered for one group.
 *
 * Lives here rather than beside one test because three report suites now assert against
 * the index: a helper defined in a test file is visible only to that file, and the second
 * suite to want it discovers this as "call to undefined function" rather than as a missing
 * import.
 *
 * @return list<string>
 */
function reportKeys(object $page, string $group): array
{
    /** @var array{props: array{groups: array<int, array{key: string, reports: array<int, array{key: string}>}>}} $rendered */
    $rendered = $page->toArray();

    $keys = [];

    foreach ($rendered['props']['groups'] as $candidate) {
        if ($candidate['key'] !== $group) {
            continue;
        }

        foreach ($candidate['reports'] as $report) {
            $keys[] = $report['key'];
        }
    }

    return $keys;
}

/**
 * The integer rial out of a `Money::toArray()` node in an Inertia payload.
 *
 * Money crosses the wire as `{value, formatted}` (golden rule: integer for arithmetic,
 * string for display), so every assertion about a figure has to reach through one level —
 * and `$page->toArray()` is `mixed` all the way down, which Larastan level 8 will not let
 * a test index blind.
 *
 * It asserts the shape rather than coercing it. A payload that lost its `value` key should
 * fail saying so, not silently read as zero and pass an assertion about an empty report.
 */
function rialOf(mixed $node): int
{
    expect($node)->toBeArray()->toHaveKey('value');

    /** @var array{value: int|numeric-string} $node */
    return (int) $node['value'];
}

/**
 * A rendered Inertia page's props, typed as far as they can honestly be typed.
 *
 * @return array<string, mixed>
 */
function propsOf(object $page): array
{
    /** @var array{props: array<string, mixed>} $rendered */
    $rendered = $page->toArray();

    return $rendered['props'];
}

/**
 * The rows of a report payload.
 *
 * @return list<array<string, mixed>>
 */
function rowsOf(object $page, string $key = 'rows'): array
{
    /** @var list<array<string, mixed>> $rows */
    $rows = propsOf($page)[$key] ?? [];

    return $rows;
}

/**
 * Report rows keyed by their `label`, for assertions that name a row rather than count on
 * its position.
 *
 * Ordering is itself asserted in a few places — the SMS report puts the most expensive
 * template first on purpose — so those tests index `rowsOf()` directly. Everywhere else,
 * keying by label means adding a row to a fixture does not renumber every expectation.
 *
 * @param  list<array<string, mixed>>  $rows
 * @return array<string, array<string, mixed>>
 */
function byLabel(array $rows): array
{
    $keyed = [];

    foreach ($rows as $row) {
        $label = $row['label'] ?? null;

        if (is_string($label)) {
            $keyed[$label] = $row;
        }
    }

    return $keyed;
}

/**
 * A model's primary key as a plain int. `getKey()` is `mixed`, and level 8 will not cast
 * it blind.
 */
function idOf(object $model): int
{
    expect($model)->toBeInstanceOf(Illuminate\Database\Eloquent\Model::class);

    /** @var Illuminate\Database\Eloquent\Model $model */
    /** @var int|numeric-string $key */
    $key = $model->getKey();

    return (int) $key;
}

/**
 * A report payload's totals block.
 *
 * @return array<string, mixed>
 */
function totalsOf(object $page, string $key = 'totals'): array
{
    /** @var array<string, mixed> $totals */
    $totals = propsOf($page)[$key] ?? [];

    return $totals;
}

/**
 * The `data` array out of a paginated Inertia payload.
 *
 * List screens ship `{data, links, total}` rather than a bare array, so `rowsOf()` — which
 * expects the rows directly — cannot reach them.
 *
 * @return list<array<string, mixed>>
 */
function paginatedOf(object $page, string $key): array
{
    /** @var array{data?: list<array<string, mixed>>} $block */
    $block = propsOf($page)[$key] ?? [];

    return $block['data'] ?? [];
}
