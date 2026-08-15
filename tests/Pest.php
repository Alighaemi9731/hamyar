<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
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
*/

/**
 * Absolute URL on the central (no-tenant) host.
 *
 * The path defaults to EMPTY, not "/": callers routinely append their own path
 * (`tenantUrl($t).'/login'`), and a default slash silently produces `//login`,
 * which 404s.
 */
function centralUrl(string $path = ''): string
{
    return 'http://'.config()->string('app.domain').$path;
}

/**
 * Absolute URL on a tenant's own host.
 */
function tenantUrl(App\Modules\Platform\Models\Tenant $tenant, string $path = ''): string
{
    return 'http://'.App\Modules\Platform\Models\Domain::hostnameFor($tenant->slug).$path;
}

/**
 * A hostname that resolves to no tenant at all.
 */
function unknownTenantUrl(string $path = ''): string
{
    return 'http://not-a-real-shop.'.config()->string('app.domain').$path;
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
