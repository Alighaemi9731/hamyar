<?php

declare(strict_types=1);

use App\Modules\Identity\Models\User;
use App\Modules\Platform\Models\Module;
use App\Modules\Platform\Models\Plan;
use App\Modules\Platform\Models\PlanLimit;
use App\Modules\Platform\Models\Subscription;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Platform\Services\PlanCatalogueSeeder;
use App\Modules\Platform\Services\SubscriptionResolver;
use App\Modules\Platform\Services\TenantProvisioner;
use App\Support\Quota\MetricRegistry;
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

    // Not only the counts: a second run must leave every row byte-identical. Duplication
    // is the loud failure; *drift* — a price, a position or a credit that moves a little
    // on every deploy — is the quiet one, and the one a count assertion cannot see.
    $before = catalogueFingerprint();

    app(PlanCatalogueSeeder::class)->sync();

    expect(Module::query()->count())->toBe($modules);
    expect(Plan::query()->count())->toBe($plans);
    expect(catalogueFingerprint())->toBe($before);
});

it('adds a rung the code has newly introduced, with its credits', function (): void {
    // How the fourth rung reaches an existing database: `PlanCatalogue` gains a plan, the
    // seeder runs on deploy, and the row appears with the limits the code shipped. There
    // is no migration for it, so this is the only thing that puts it there.
    $business = Plan::query()->where('code', 'business')->firstOrFail();
    $business->limits()->delete();
    $business->delete();

    app(PlanCatalogueSeeder::class)->sync();

    $restored = Plan::query()->where('code', 'business')->firstOrFail();

    expect($restored->price)->toBeGreaterThan(0)
        ->and($restored->limit('sales.invoices'))->toBeGreaterThan(0)
        // And it lands between the free rung and حرفه‌ای, because `nextPlanFor()` walks
        // the ladder by position: a rung in the wrong place aims every upgrade button on
        // the product at the wrong plan.
        ->and($restored->position)->toBeGreaterThan(Plan::query()->where('code', 'basic')->firstOrFail()->position)
        ->and($restored->position)->toBeLessThan(Plan::query()->where('code', 'pro')->firstOrFail()->position);
});

it('sells one free rung and three paid ones, priced upward', function (): void {
    /*
    | The owner's count, 2026-09-12: «باید رایگان که جدا باشه، پلن‌های پولی ۳ تا باشن» —
    | the free rung stands outside the count, and there are three PAID plans. Asserted on
    | the catalogue rather than on `PlanCatalogue`, because the seeder is what a fresh
    | install actually runs and a rung defined in code but never seeded is not a product.
    */
    $plans = Plan::query()->where('is_public', true)->orderBy('position')->get();

    $free = $plans->where('price', 0);
    $paid = $plans->where('price', '>', 0);

    expect($free)->toHaveCount(1, 'exactly one rung is free');
    expect($paid)->toHaveCount(3, 'three paid plans, the free rung outside the count');

    // The free rung is the first one: `TenantProvisioner` puts a new shop on the cheapest
    // zero-price plan by position, and `nextPlanFor()` offers whatever sits above it.
    expect($plans->first()?->price)->toBe(0);

    // Prices strictly ascend down the ladder. A rung that costs more and sits lower would
    // make the pricing sheet an argument for skipping it.
    $prices = $plans->map(fn (Plan $plan): int => $plan->price)->all();

    $sorted = $prices;
    sort($sorted);

    expect($prices)->toBe($sorted, 'the ladder is not in price order')
        ->and(array_unique($prices))->toHaveCount(count($prices), 'two rungs share a price');
});

it('never sells a smaller quota for a bigger price', function (): void {
    /*
    | The test the fourth rung exists to keep honest.
    |
    | Every metric, every neighbouring pair of rungs: the more expensive one is at least
    | as generous. `null` is unlimited and therefore the top of any comparison — the one
    | place a "bigger number wins" check would get the answer exactly backwards.
    |
    | Inserting a rung mid-ladder is precisely how this breaks: one metric copied from the
    | rung above instead of scaled, and a shop pays more to record less.
    */
    $plans = Plan::query()->with('limits')->where('is_public', true)->orderBy('position')->get();

    $keys = app(MetricRegistry::class)->keys();

    expect($keys)->not->toBeEmpty();

    foreach ($keys as $key) {
        $ladder = $plans->map(fn (Plan $plan): array => [
            'code' => $plan->code,
            'limit' => $plan->limit($key),
        ])->all();

        foreach (array_slice($ladder, 1) as $index => $higher) {
            $lower = $ladder[$index];

            if ($higher['limit'] === null) {
                continue; // unlimited: nothing above it to fall short of.
            }

            $floor = $lower['limit'];

            expect($floor)->not->toBeNull(
                "{$key}: {$lower['code']} is unlimited and the dearer {$higher['code']} is not"
            );

            assert($floor !== null);

            expect($higher['limit'])->toBeGreaterThanOrEqual(
                $floor,
                "{$key}: {$higher['code']} costs more than {$lower['code']} and allows less"
            );
        }
    }
});

it('keeps the free rung at zero SMS on a four-rung ladder', function (): void {
    // A real constraint, not an oversight: SMS is the one credit that costs cash per
    // segment, so the rung that is priced at nothing hands out none of it. A fourth rung
    // is exactly the moment somebody "fixes" this by sprinkling a few onto free.
    expect(Plan::query()->where('code', 'basic')->firstOrFail()->limit('messaging.sms'))->toBe(0);

    // And the first paid rung is where SMS starts, funded by a price.
    expect(Plan::query()->where('code', 'business')->firstOrFail()->limit('messaging.sms'))
        ->toBeGreaterThan(0);
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

/* ------------------------------------------------------------------ helper -- */

/**
 * Every catalogue row a re-run must leave exactly where it found it, as one comparable
 * value.
 *
 * Row counts catch duplication, which is the loud failure. This catches **drift** — the
 * price, position or credit that moves a little on each deploy — which is the quiet one,
 * invisible to any assertion that does not hold the previous value beside the new one.
 *
 * @return array<int, string>
 */
function catalogueFingerprint(): array
{
    $plans = Plan::query()->with('limits')->orderBy('code')->get()
        ->map(function (Plan $plan): string {
            $limits = $plan->limits
                ->sortBy('key')
                ->map(fn (PlanLimit $limit): string => $limit->key.'='.($limit->value ?? 'unlimited'))
                ->implode(',');

            return implode('|', [
                'plan', $plan->code, $plan->name_fa, $plan->tagline_fa ?? '', $plan->interval,
                (string) $plan->price, (string) $plan->trial_days, (string) $plan->position,
                $plan->is_public ? 'public' : 'private', $limits,
            ]);
        })->all();

    $modules = Module::query()->orderBy('code')->get()
        ->map(fn (Module $module): string => implode('|', [
            'module', $module->code, $module->name_fa, (string) $module->position,
            $module->is_core ? 'core' : 'optional',
            $module->is_enabled ? 'on' : 'off',
        ]))->all();

    return array_values([...$plans, ...$modules]);
}
