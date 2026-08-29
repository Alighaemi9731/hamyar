<?php

declare(strict_types=1);

use App\Modules\Identity\Models\User;
use App\Modules\Platform\Models\Module;
use App\Modules\Platform\Models\Plan;
use App\Modules\Platform\Models\Subscription;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Platform\Services\PlanCatalogueSeeder;
use App\Modules\Platform\Services\SubscriptionResolver;
use App\Modules\Platform\Services\TenantProvisioner;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Route;

/**
 * The module switch after DECISION GATE 6.
 *
 * This file used to be `PlanGatingTest` and asserted the opposite of most of what is
 * below: that a Basic shop was refused Repairs, that an add-on could buy it back, that a
 * lapsed shop was granted nothing at all. Those were correct tests of a product where a
 * plan bought modules. Every module is now open to every shop, and what a plan buys is how
 * much work per month — so the same middleware, the same prop and the same nav now answer
 * a different question: **have WE switched this module on?**
 *
 * The quota suites (`tests/.../Quota`) cover what replaced it as the thing that says no.
 */
beforeEach(function (): void {
    app(PlanCatalogueSeeder::class)->sync();

    $this->tenant = Tenant::factory()->withDomain()->create();
    $this->url = appUrl();

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

/* ------------------------------------------------------------- catalogue -- */

it('syncs the catalogue idempotently', function (): void {
    $modules = Module::query()->count();
    $plans = Plan::query()->count();

    app(PlanCatalogueSeeder::class)->sync();

    expect(Module::query()->count())->toBe($modules);
    expect(Plan::query()->count())->toBe($plans);
});

it('does not overwrite a price edited in the panel', function (): void {
    // Prices are business data and live in Filament. A seeder that rewrote them on every
    // deploy would revert the owner's change silently — the worst possible failure for a
    // number that decides what customers pay.
    $pro = Plan::query()->where('code', 'pro')->firstOrFail();
    $pro->update(['price' => 3_450_000]);

    app(PlanCatalogueSeeder::class)->sync();

    expect($pro->fresh()?->price)->toBe(3_450_000);
});

it('does not overwrite a limit edited in the panel', function (): void {
    // Same rule, and now it matters more: a limit IS the product.
    $basic = Plan::query()->where('code', 'basic')->firstOrFail();
    $basic->limits()->where('key', 'sales.invoices')->update(['value' => 42]);

    app(PlanCatalogueSeeder::class)->sync();

    expect($basic->fresh()?->limit('sales.invoices'))->toBe(42);
});

it('still corrects module structure the code owns', function (): void {
    // Nobody may mark a module core in the panel if the code says otherwise: the
    // catalogue describes what the application can actually serve.
    $sales = Module::query()->where('code', 'sales')->firstOrFail();
    $sales->update(['is_core' => false]);

    app(PlanCatalogueSeeder::class)->sync();

    expect($sales->fresh()?->is_core)->toBeTrue();
});

it('makes the first rung free and gives it real credits', function (): void {
    $basic = Plan::query()->where('code', 'basic')->firstOrFail();

    expect($basic->price)->toBe(0);
    // Free, not empty: a shop has to be able to run a month on it, or it is a demo.
    expect($basic->limit('sales.invoices'))->toBeGreaterThan(0);
    // Except SMS, the one credit that costs us cash per unit — funded by the wallet.
    expect($basic->limit('messaging.sms'))->toBe(0);
});

it('treats a null limit as unlimited rather than zero', function (): void {
    $enterprise = Plan::query()->where('code', 'enterprise')->firstOrFail();

    expect($enterprise->limit('sales.invoices'))->toBeNull();
    // And keeps a finite ceiling on the two that cost us per unit whatever we charge.
    expect($enterprise->limit('identity.users'))->toBe(25);
});

/* --------------------------------------------------------- the switch -- */

it('grants every enabled module to every plan', function (): void {
    // The gate's headline: no plan bundles modules any more.
    subscribe($this->tenant, 'basic');

    app(TenantContext::class)->runFor($this->tenant, function (): void {
        $resolver = ($this->resolve)();

        expect($resolver->grants('sales'))->toBeTrue();
        expect($resolver->grants('repairs'))->toBeTrue();
        expect($resolver->grants('moadian'))->toBeTrue();
    });
});

it('grants every module to a shop with no subscription at all', function (): void {
    // The old behaviour was to grant NOTHING, on the reasoning that gating should fail
    // closed. That was right when a module was a purchase. Now it would mean a shop whose
    // payment is three days late finds every screen 403ing and concludes the software is
    // broken — so what fails closed is the quota layer, which can refuse a create without
    // taking the product away.
    app(TenantContext::class)->runFor(
        $this->tenant,
        fn () => expect(($this->resolve)()->grants('sales'))->toBeTrue()
    );
});

it('closes a module we have switched off, for everybody', function (): void {
    // ADR 0011: Moadian ships as an adapter with no provider. Something has to be able to
    // close those routes for every shop without a deploy, and this is it.
    Module::query()->where('code', 'moadian')->update(['is_enabled' => false]);
    cache()->forget('platform.modules.enabled');

    subscribe($this->tenant, 'enterprise');

    app(TenantContext::class)->runFor($this->tenant, function (): void {
        expect(($this->resolve)()->grants('moadian'))->toBeFalse();
        // And nothing else moves with it.
        expect(($this->resolve)()->grants('sales'))->toBeTrue();
    });
});

/* ----------------------------------------------------------- route gating -- */

it('403s a route whose module is switched off', function (): void {
    Route::middleware(['web', 'tenant', 'auth', 'tenant.user', 'module:repairs'])
        ->get('/__test/repairs', fn () => 'ok')->name('test.repairs');

    Module::query()->where('code', 'repairs')->update(['is_enabled' => false]);
    cache()->forget('platform.modules.enabled');

    subscribe($this->tenant, 'basic');

    // Hiding the nav item is convenience; THIS is the enforcement.
    $this->actingAs($this->user)->get($this->url.'/__test/repairs')->assertForbidden();
});

it('allows the route on the free plan when the module is on', function (): void {
    Route::middleware(['web', 'tenant', 'auth', 'tenant.user', 'module:repairs'])
        ->get('/__test/repairs2', fn () => 'ok');

    subscribe($this->tenant, 'basic');

    $this->actingAs($this->user)->get($this->url.'/__test/repairs2')->assertOk();
});

/* ------------------------------------------------------------- inertia -- */

it('shares every module as a feature flag, all true by default', function (): void {
    subscribe($this->tenant, 'basic');

    $this->actingAs($this->user)
        ->get($this->url.'/dashboard')
        ->assertInertia(fn ($page) => $page
            ->where('features.module:sales', true)
            ->where('features.module:repairs', true)
        );
});

it('shares a switched-off module as false, not absent', function (): void {
    // Both states must be present so the frontend can tell "off right now" from "unknown
    // key" — the first is a message, the second is a bug.
    Module::query()->where('code', 'moadian')->update(['is_enabled' => false]);
    cache()->forget('platform.modules.enabled');

    subscribe($this->tenant, 'basic');

    $this->actingAs($this->user)
        ->get($this->url.'/dashboard')
        ->assertInertia(fn ($page) => $page->where('features.module:moadian', false));
});

/* ---------------------------------------------------------- onboarding -- */

it('puts a newly onboarded shop on the free plan, with no trial to expire', function (): void {
    $tenant = app(TenantProvisioner::class)->provision([
        'name' => 'تازه‌وارد',
        'subdomain' => 'newcomer',
        'owner_name' => 'علی',
        'owner_mobile' => '09127770000',
        'owner_email' => null,
        'password' => 'secret-secret-1',
    ]);

    /** @var Subscription $subscription */
    $subscription = app(TenantContext::class)->runAsPlatform(
        fn (): Subscription => Subscription::query()->where('tenant_id', $tenant->getKey())->firstOrFail()
    );

    expect($subscription->status)->toBe(Subscription::STATUS_ACTIVE);
    expect($subscription->plan->price)->toBe(0);
    // No period and no trial end: free has nothing to renew and nothing to run out.
    expect($subscription->current_period_end)->toBeNull();
    expect($subscription->trial_ends_at)->toBeNull();
    expect($subscription->isUsable())->toBeTrue();
});

it('leaves a free shop usable a year later', function (): void {
    // The property that makes it a rung rather than a trial with the timer removed.
    $tenant = app(TenantProvisioner::class)->provision([
        'name' => 'ماندگار',
        'subdomain' => 'persistent',
        'owner_name' => 'رضا',
        'owner_mobile' => '09127770001',
        'owner_email' => null,
        'password' => 'secret-secret-1',
    ]);

    $this->travel(400)->days();

    /** @var Subscription $subscription */
    $subscription = app(TenantContext::class)->runAsPlatform(
        fn (): Subscription => Subscription::query()->where('tenant_id', $tenant->getKey())->firstOrFail()
    );

    expect($subscription->isUsable())->toBeTrue();
});
