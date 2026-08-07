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
