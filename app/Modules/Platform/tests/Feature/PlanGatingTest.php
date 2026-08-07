<?php

declare(strict_types=1);

use App\Modules\Identity\Models\User;
use App\Modules\Platform\Models\Module;
use App\Modules\Platform\Models\Plan;
use App\Modules\Platform\Models\Subscription;
use App\Modules\Platform\Models\SubscriptionAddon;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Platform\Services\PlanCatalogueSeeder;
use App\Modules\Platform\Services\SubscriptionResolver;
use App\Modules\Platform\Services\TenantProvisioner;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Route;

beforeEach(function (): void {
    app(PlanCatalogueSeeder::class)->sync();

    $this->tenant = Tenant::factory()->withDomain()->create();
    $this->url = tenantUrl($this->tenant);

    app(TenantProvisioner::class)->seedRoles($this->tenant);

    $this->user = app(TenantContext::class)->runFor(
        $this->tenant,
        fn () => User::factory()->create()
    );

    $this->resolve = function (): SubscriptionResolver {
        $resolver = app(SubscriptionResolver::class);
        $resolver->forget();

        return $resolver;
    };
});

afterEach(fn () => app(TenantContext::class)->forget());

/**
 * @param  array<string, mixed>  $overrides
 */
function subscribe(Tenant $tenant, string $planCode, array $overrides = []): Subscription
{
    $plan = Plan::query()->where('code', $planCode)->firstOrFail();

    return Subscription::query()->create(array_merge([
        'tenant_id' => $tenant->getKey(),
        'plan_id' => $plan->getKey(),
        'status' => Subscription::STATUS_ACTIVE,
        'current_period_start' => now()->subDays(10),
        'current_period_end' => now()->addDays(20),
    ], $overrides));
}

/* ------------------------------------------------------------- catalogue -- */

it('syncs the catalogue idempotently', function (): void {
    $modules = Module::query()->count();
    $plans = Plan::query()->count();

    // Runs on every deploy; a second run must not duplicate anything.
    app(PlanCatalogueSeeder::class)->sync();

    expect(Module::query()->count())->toBe($modules);
    expect(Plan::query()->count())->toBe($plans);
});

it('stores plan prices as integer rial', function (): void {
    $basic = Plan::query()->where('code', 'basic')->firstOrFail();

    // 290,000 toman. Storing toman here would be a silent factor-of-ten error.
    expect($basic->price)->toBe(2_900_000);
    expect($basic->price)->toBeRial();
});

it('treats a null limit as unlimited rather than zero', function (): void {
    $enterprise = Plan::query()->where('code', 'enterprise')->firstOrFail();

    expect($enterprise->limit('invoices_per_month'))->toBeNull();
    expect($enterprise->limit('users'))->toBe(15);
});

/* ------------------------------------------------------------- resolving -- */

it('grants exactly the plan modules', function (): void {
    subscribe($this->tenant, 'basic');

    app(TenantContext::class)->runFor($this->tenant, function (): void {
        $resolver = ($this->resolve)();

        expect($resolver->grants('sales'))->toBeTrue();
        expect($resolver->grants('repairs'))->toBeFalse();
        expect($resolver->grants('moadian'))->toBeFalse();
    });
});

it('grants a module bought as an add-on', function (): void {
    $subscription = subscribe($this->tenant, 'basic');

    $repairs = Module::query()->where('code', 'repairs')->firstOrFail();

    SubscriptionAddon::query()->create([
        'subscription_id' => $subscription->getKey(),
        'module_id' => $repairs->getKey(),
        'price' => $repairs->addon_price ?? 0,
        'starts_at' => now()->subDay(),
    ]);

    app(TenantContext::class)->runFor(
        $this->tenant,
        fn () => expect(($this->resolve)()->grants('repairs'))->toBeTrue()
    );
});

it('stops granting an add-on after it ends', function (): void {
    $subscription = subscribe($this->tenant, 'basic');
    $repairs = Module::query()->where('code', 'repairs')->firstOrFail();

    SubscriptionAddon::query()->create([
        'subscription_id' => $subscription->getKey(),
        'module_id' => $repairs->getKey(),
        'price' => 0,
        'starts_at' => now()->subMonth(),
        // Removed add-ons run to period end rather than being deleted (ADR 0006).
        'ends_at' => now()->subDay(),
    ]);

    app(TenantContext::class)->runFor(
        $this->tenant,
        fn () => expect(($this->resolve)()->grants('repairs'))->toBeFalse()
    );
});

it('grants NOTHING when there is no subscription', function (): void {
    // Fails closed, like RLS. The safe failure is "nothing works and someone
    // complains", never "everything is free".
    app(TenantContext::class)->runFor(
        $this->tenant,
        fn () => expect(($this->resolve)()->grantedModuleCodes())->toBeEmpty()
    );
});

it('grants nothing once a subscription lapses', function (): void {
    subscribe($this->tenant, 'pro', [
        'status' => Subscription::STATUS_PAST_DUE,
        'grace_ends_at' => now()->subDay(),
    ]);

    app(TenantContext::class)->runFor(
        $this->tenant,
        fn () => expect(($this->resolve)()->grantedModuleCodes())->toBeEmpty()
    );
});

it('keeps a past_due shop working inside its grace window', function (): void {
    // Iranian gateways have transient outages; cutting a shop off the hour a payment
    // fails would lock someone out of their own till mid-sale.
    subscribe($this->tenant, 'pro', [
        'status' => Subscription::STATUS_PAST_DUE,
        'grace_ends_at' => now()->addDays(3),
    ]);

    app(TenantContext::class)->runFor(
        $this->tenant,
        fn () => expect(($this->resolve)()->grants('sales'))->toBeTrue()
    );
});

/* ----------------------------------------------------------- route gating -- */

it('403s a route whose module the plan does not include', function (): void {
    Route::middleware(['web', 'tenant', 'auth', 'tenant.user', 'module:repairs'])
        ->get('/__test/repairs', fn () => 'ok')->name('test.repairs');

    subscribe($this->tenant, 'basic');

    // Hiding the nav item is convenience; THIS is the enforcement (golden rule 7).
    $this->actingAs($this->user)->get($this->url.'/__test/repairs')->assertForbidden();
});

it('allows the route when the plan includes the module', function (): void {
    Route::middleware(['web', 'tenant', 'auth', 'tenant.user', 'module:repairs'])
        ->get('/__test/repairs2', fn () => 'ok');

    subscribe($this->tenant, 'pro');

    $this->actingAs($this->user)->get($this->url.'/__test/repairs2')->assertOk();
});

/* ------------------------------------------------------------- inertia -- */

it('shares every module as a feature flag, including the disabled ones', function (): void {
    subscribe($this->tenant, 'basic');

    // Both states must be present so the frontend can tell "not in your plan" (an
    // upsell) from "unknown key" (a bug).
    $this->actingAs($this->user)
        ->get($this->url.'/dashboard')
        ->assertInertia(fn ($page) => $page
            ->where('features.module:sales', true)
            ->where('features.module:repairs', false)
        );
});

/* ---------------------------------------------------------- onboarding -- */

it('puts a newly onboarded shop on a trial that includes repairs', function (): void {
    // A shop evaluating us has to see the features that differentiate the product.
    $tenant = app(TenantProvisioner::class)->provision([
        'name' => 'تازه‌وارد',
        'subdomain' => 'newcomer',
        'owner_name' => 'علی',
        'owner_mobile' => '09127770000',
        'owner_email' => null,
        'password' => 'secret-secret-1',
    ]);

    $subscription = Subscription::query()->where('tenant_id', $tenant->getKey())->firstOrFail();

    expect($subscription->status)->toBe(Subscription::STATUS_TRIALING);
    expect($subscription->isTrialing())->toBeTrue();

    app(TenantContext::class)->runFor(
        $tenant,
        fn () => expect(($this->resolve)()->grants('repairs'))->toBeTrue()
    );
});

it('does not leak one shop plan into another', function (): void {
    pest()->group('isolation');

    $other = Tenant::factory()->withDomain()->create();

    subscribe($this->tenant, 'enterprise');
    subscribe($other, 'basic');

    app(TenantContext::class)->runFor(
        $this->tenant,
        fn () => expect(($this->resolve)()->grants('moadian'))->toBeTrue()
    );

    app(TenantContext::class)->runFor(
        $other,
        fn () => expect(($this->resolve)()->grants('moadian'))->toBeFalse()
    );
});
