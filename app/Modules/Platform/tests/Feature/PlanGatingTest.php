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

    // Runs on every deploy; a second run must not duplicate anything.
    app(PlanCatalogueSeeder::class)->sync();

    expect(Module::query()->count())->toBe($modules);
    expect(Plan::query()->count())->toBe($plans);
});

it('does not overwrite a price edited in the panel', function (): void {
    // Gate 2: prices are provisional business data and live in Filament. A seeder that
    // rewrote them on every deploy would revert the owner's change silently, which is
    // the worst possible failure mode for a number that decides what customers pay.
    $basic = Plan::query()->where('code', 'basic')->firstOrFail();
    $basic->update(['price' => 3_450_000]);

    app(PlanCatalogueSeeder::class)->sync();

    expect($basic->fresh()?->price)->toBe(3_450_000);
});

it('still corrects module structure the code owns', function (): void {
    // The other half of the same rule: nobody may mark a module addonable in the panel
    // if the application cannot actually sell it separately.
    $sales = Module::query()->where('code', 'sales')->firstOrFail();
    $sales->update(['is_core' => false, 'is_addonable' => true]);

    app(PlanCatalogueSeeder::class)->sync();

    $sales->refresh();

    expect($sales->is_core)->toBeTrue();
    expect($sales->is_addonable)->toBeFalse();
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

    app(TenantContext::class)->runAsPlatform(fn () => SubscriptionAddon::query()->create([
        'tenant_id' => $this->tenant->getKey(),
        'subscription_id' => $subscription->getKey(),
        'module_id' => $repairs->getKey(),
        'price' => $repairs->addon_price ?? 0,
        'starts_at' => now()->subDay(),
    ]));

    app(TenantContext::class)->runFor(
        $this->tenant,
        fn () => expect(($this->resolve)()->grants('repairs'))->toBeTrue()
    );
});

it('stops granting an add-on after it ends', function (): void {
    $subscription = subscribe($this->tenant, 'basic');
    $repairs = Module::query()->where('code', 'repairs')->firstOrFail();

    app(TenantContext::class)->runAsPlatform(fn () => SubscriptionAddon::query()->create([
        'tenant_id' => $this->tenant->getKey(),
        'subscription_id' => $subscription->getKey(),
        'module_id' => $repairs->getKey(),
        'price' => 0,
        'starts_at' => now()->subMonth(),
        // Removed add-ons run to period end rather than being deleted (ADR 0006).
        'ends_at' => now()->subDay(),
    ]));

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

/* --------------------------------------------------------------- trial -- */

it('caps a Pro trial at Basic invoice volume', function (): void {
    // Gate 2 item 3: generous on features, tight on quotas.
    subscribe($this->tenant, 'pro', [
        'status' => Subscription::STATUS_TRIALING,
        'trial_ends_at' => now()->addDays(14),
    ]);

    app(TenantContext::class)->runFor($this->tenant, function (): void {
        $resolver = ($this->resolve)();

        // Pro itself allows 5,000; the trial borrows Basic's 500.
        expect($resolver->limit('invoices_per_month'))->toBe(500);
        // But the FEATURES stay Pro — that is the whole point of the trial.
        expect($resolver->grants('repairs'))->toBeTrue();
        expect($resolver->grants('installments'))->toBeTrue();
    });
});

it('grants zero bonus SMS during a trial', function (): void {
    // The one quota that costs us cash per unit. Bought, never granted.
    subscribe($this->tenant, 'pro', [
        'status' => Subscription::STATUS_TRIALING,
        'trial_ends_at' => now()->addDays(14),
    ]);

    app(TenantContext::class)->runFor(
        $this->tenant,
        fn () => expect(($this->resolve)()->limit('sms_credit_bonus'))->toBe(0)
    );
});

it('restores the real plan limits once the trial converts', function (): void {
    subscribe($this->tenant, 'pro', [
        'status' => Subscription::STATUS_ACTIVE,
        'trial_ends_at' => now()->subDay(),
    ]);

    app(TenantContext::class)->runFor($this->tenant, function (): void {
        $resolver = ($this->resolve)();

        expect($resolver->limit('invoices_per_month'))->toBe(5_000);
        expect($resolver->limit('sms_credit_bonus'))->toBe(500);
    });
});

it('never lets a trial exceed the plan it borrows from', function (): void {
    // If someone makes Basic unlimited in the panel, the trial must not inherit that.
    Plan::query()->where('code', 'basic')->firstOrFail()
        ->limits()->where('key', 'invoices_per_month')->update(['value' => null]);

    subscribe($this->tenant, 'pro', [
        'status' => Subscription::STATUS_TRIALING,
        'trial_ends_at' => now()->addDays(14),
    ]);

    app(TenantContext::class)->runFor(
        $this->tenant,
        // Falls back to Pro's own 5,000 rather than becoming unlimited.
        fn () => expect(($this->resolve)()->limit('invoices_per_month'))->toBe(5_000)
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

    $subscription = app(TenantContext::class)->runAsPlatform(
        fn (): Subscription => Subscription::query()->where('tenant_id', $tenant->getKey())->firstOrFail()
    );

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
