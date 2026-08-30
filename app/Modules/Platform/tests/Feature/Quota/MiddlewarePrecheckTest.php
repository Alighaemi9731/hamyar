<?php

declare(strict_types=1);

use App\Modules\Identity\Models\User;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Platform\Services\SubscriptionResolver;
use App\Modules\Platform\Services\TenantProvisioner;
use App\Support\Quota\MetricRegistry;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Route;

/**
 * `EnsureQuotaAvailable` — the courtesy check, and the two ways it could quietly become a
 * liability.
 *
 * It is **not** the guarantee. `QuotaGuard::consume()` is, because it is the only thing
 * that runs inside the transaction that writes the counted row. This middleware exists so
 * a shop already out of credit gets its answer without the server doing work it is about
 * to throw away.
 *
 * That makes its failure modes different from the guard's, and worse in one direction:
 *
 * - **A metric key that is not registered.** `->middleware('quota:sales.invoice')` — one
 *   character wrong — would either 500 on every request to that route or, worse, resolve
 *   to nothing and let everything through. A route gated on a typo looks gated in the
 *   source and is not, which is the same shape as the `bindIf` and `jdate()` bugs this
 *   project has been bitten by twice. So the last test walks the real route table.
 * - **Guarding a read.** A shop that has spent its credit must still be able to look up a
 *   customer, print a receipt and run a report. Refusing a GET would take away the data
 *   they have already paid for, on the grounds that they cannot add more.
 */
beforeEach(function (): void {
    $this->tenant = Tenant::factory()->withDomain()->create();
    $this->url = appUrl();

    subscribe($this->tenant, 'pro');
    app(SubscriptionResolver::class)->forget();
    app(TenantProvisioner::class)->seedRoles($this->tenant);

    $this->user = app(TenantContext::class)->runFor($this->tenant, function (): User {
        $user = User::factory()->create();
        $user->assignRole('Owner');

        return $user;
    });

    registerTestMetrics();
});

afterEach(fn () => app(TenantContext::class)->forget());

/**
 * A throwaway route carrying the middleware, so these tests are about the middleware
 * rather than about whichever real screen happens to use it this month.
 */
function routeGuardedBy(string $middleware): string
{
    Route::middleware(['web', 'auth', 'tenant', $middleware])
        ->post('/__quota_probe', fn (): string => 'through');

    return '/__quota_probe';
}

it('lets the request through while there is credit', function (): void {
    $path = routeGuardedBy('quota:quota.widgets');

    $this->actingAs($this->user)->post($this->url.$path)->assertOk();
});

it('refuses before the controller runs when the credit is gone', function (): void {
    capQuota($this->tenant, 'quota.widgets', 0);

    $path = routeGuardedBy('quota:quota.widgets');

    $this->actingAs($this->user)->post($this->url.$path)->assertSessionHasErrors('quota');
});

it('checks without spending, so the pre-check never costs a credit', function (): void {
    $path = routeGuardedBy('quota:quota.widgets');

    $this->actingAs($this->user)->post($this->url.$path)->assertOk();

    // The whole point of `check()` over `consume()`: passing through the door is not the
    // act being metered. If this ever wrote, every guarded route would charge twice — once
    // here and once in the transaction that does the work.
    expect(quotaRowExists($this->tenant, 'quota.widgets'))->toBeFalse();
});

it('counts a bulk request against the credit before starting it', function (): void {
    capQuota($this->tenant, 'quota.widgets', 5);

    // Six wanted, five left. Refused up front rather than after the file is parsed.
    $this->actingAs($this->user)->post($this->url.routeGuardedBy('quota:quota.widgets,6'))
        ->assertSessionHasErrors('quota');
});

it('never guards a read', function (): void {
    capQuota($this->tenant, 'quota.widgets', 0);

    Route::middleware(['web', 'auth', 'tenant', 'quota:quota.widgets'])
        ->get('/__quota_probe_get', fn (): string => 'through');

    // Out of credit entirely, and the GET still answers. A shop that cannot record new
    // work must still reach the work it has already recorded.
    $this->actingAs($this->user)->get($this->url.'/__quota_probe_get')->assertOk();
});

it('gates every route on a metric that actually exists', function (): void {
    $registry = app(MetricRegistry::class);
    $unknown = [];

    /** @var list<Illuminate\Routing\Route> $routes */
    $routes = Route::getRoutes()->getRoutes();

    foreach ($routes as $route) {
        foreach ($route->gatherMiddleware() as $middleware) {
            if (! is_string($middleware) || ! str_starts_with($middleware, 'quota:')) {
                continue;
            }

            $metric = explode(',', substr($middleware, strlen('quota:')))[0];

            try {
                $registry->get($metric);
            } catch (Throwable) {
                $unknown[] = $route->uri().' → '.$metric;
            }
        }
    }

    /*
    | The whole reason this test exists rather than a comment asking people to be careful.
    |
    | A mistyped metric key is invisible in review: `quota:sales.invoice` reads exactly like
    | `quota:sales.invoices`, the route still looks guarded, and nothing fails until a shop
    | reaches the ceiling that is no longer being watched. Walking the real route table is
    | the only check that cannot be forgotten when somebody adds the next one.
    */
    expect($unknown)->toBe([]);
});
