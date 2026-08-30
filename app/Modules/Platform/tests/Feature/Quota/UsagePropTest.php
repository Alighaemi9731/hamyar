<?php

declare(strict_types=1);

use App\Modules\Identity\Models\User;
use App\Modules\Platform\Models\Plan;
use App\Modules\Platform\Models\Subscription;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Platform\Services\Quota\LimitResolver;
use App\Modules\Platform\Services\SubscriptionResolver;
use App\Modules\Platform\Services\TenantProvisioner;
use App\Support\Tenancy\TenantContext;

/**
 * The `usage` shared prop — what every staff page carries about where this shop stands.
 *
 * ## It runs on every authenticated request, which decides how it must fail
 *
 * The meters are a convenience: a bar in the sidebar, a banner when something is nearly
 * gone. The *guarantee* is `QuotaGuard::consume()`, which runs somewhere else entirely.
 * So a fault here must degrade to no meters, never to no page — a shop whose catalogue is
 * misconfigured must still be able to sell. `HandleInertiaRequests::usage()` catches and
 * `report()`s for exactly that reason, and the last test holds it to it.
 *
 * ## The four levels are a sentence, not a gradient
 *
 * `ok` → `warning` → `reached` → `blocked` each mean something different to a shopkeeper,
 * and the middle two are the pair that gets confused: **`reached` is a full credit that has
 * not yet stopped anybody**, and `blocked` is one that has. Colouring a full-but-harmless
 * meter red trains people to ignore red.
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
});

afterEach(fn () => app(TenantContext::class)->forget());

/**
 * The `usage` prop off a real staff page.
 *
 * @return array<string, mixed>
 */
function usageProp(): array
{
    /** @var User $user */
    $user = test()->user;
    /** @var string $url */
    $url = test()->url;

    $captured = [];

    test()->actingAs($user)->get($url.'/dashboard')
        ->assertOk()
        ->assertInertia(function ($page) use (&$captured): void {
            /** @var array<string, mixed> $usage */
            $usage = propsOf($page)['usage'] ?? [];

            $captured = $usage;
        });

    return $captured;
}

/**
 * One meter out of the prop, by key.
 *
 * @return array<string, mixed>|null
 */
function meterFor(string $key): ?array
{
    /** @var list<array<string, mixed>> $meters */
    $meters = usageProp()['meters'] ?? [];

    foreach ($meters as $meter) {
        if (($meter['key'] ?? null) === $key) {
            return $meter;
        }
    }

    return null;
}

/**
 * One field off the `plan` block of the prop, narrowed so Larastan can see it.
 */
function planField(string $key): mixed
{
    /** @var array<string, mixed> $plan */
    $plan = usageProp()['plan'] ?? [];

    return $plan[$key] ?? null;
}

it('carries a meter for every registered metric', function (): void {
    $usage = usageProp();

    /** @var list<array<string, mixed>> $meters */
    $meters = $usage['meters'] ?? [];

    expect($meters)->not->toBeEmpty()
        ->and(planField('code'))->toBe('pro')
        ->and(planField('lapsed'))->toBeFalse();

    // The shape the sidebar and the banner both read. A missing key here is a component
    // rendering `undefined` rather than a number, which looks like a bug in the meter
    // rather than in the payload.
    expect($meters[0])->toHaveKeys([
        'key', 'label', 'unit', 'module', 'used', 'limit', 'window', 'resets_at', 'level',
    ]);
});

it('shows nothing to a shop that has used nothing', function (): void {
    $meter = meterFor('sales.invoices');

    expect($meter['used'] ?? null)->toBe(0)
        ->and($meter['level'] ?? null)->toBe('ok')
        ->and(usageProp()['attention'] ?? null)->toBe([]);
});

it('warns before the credit is gone rather than after', function (): void {
    capQuota($this->tenant, 'sales.invoices', 10);
    spendQuota($this->tenant, 'sales.invoices', 8);

    // 80% is the default `hamyar.quota.warning_ratio`. Warning at the wall would be
    // useless — the point is to give a shop time to decide before a customer is waiting.
    expect(meterFor('sales.invoices')['level'] ?? null)->toBe('warning')
        ->and(usageProp()['attention'] ?? null)->toContain('sales.invoices');
});

it('tells a full credit apart from one that has actually stopped somebody', function (): void {
    capQuota($this->tenant, 'sales.invoices', 2);
    spendQuota($this->tenant, 'sales.invoices', 2);

    // Full, and nobody has been refused yet: the shop may simply have finished its month.
    // `reached` is amber, `blocked` is red, and the difference is whether the ceiling has
    // cost this shop an action it tried to take.
    expect(meterFor('sales.invoices')['level'] ?? null)->toBe('reached');
});

it('turns red only once the ceiling has refused something', function (): void {
    capQuota($this->tenant, 'sales.invoices', 0);

    /*
    | Refused through a real request, and that detail is the test.
    |
    | `blocked_at` is stamped by the exception *renderer*, not by `consume()` — so a
    | refusal caught in a service, or one staged by calling the guard directly, correctly
    | leaves the meter amber. Red means "this ceiling has cost a shopkeeper an action they
    | tried to take", which is a fact only the HTTP layer is in a position to know.
    |
    | A throwaway route carrying the middleware is the shortest honest way to produce one
    | from inside a Platform test without dragging in Sales' fixtures.
    */
    Illuminate\Support\Facades\Route::middleware(['web', 'auth', 'tenant', 'quota:sales.invoices'])
        ->post('/__blocked_probe', fn (): string => 'through');

    $this->actingAs($this->user)->post($this->url.'/__blocked_probe')
        ->assertSessionHasErrors('quota');

    expect(meterFor('sales.invoices')['level'] ?? null)->toBe('blocked');
});

it('counts an unlimited credit instead of hiding it', function (): void {
    subscribe($this->tenant, 'enterprise');
    app(SubscriptionResolver::class)->forget();
    app(LimitResolver::class)->forget();

    spendQuota($this->tenant, 'sales.invoices', 3);

    $meter = meterFor('sales.invoices') ?? [];

    // Null limit, real count. A shop on «نامحدود» still wants to know how much it is
    // doing — and the number is what makes the plan feel worth its price.
    //
    // Asserted with `array_key_exists` rather than `??`, because null IS the expected
    // value here and `?? 'missing'` cannot tell "unlimited" from "no such meter".
    expect($meter)->not->toBeEmpty()
        ->and(array_key_exists('limit', $meter))->toBeTrue()
        ->and($meter['limit'])->toBeNull()
        ->and($meter['used'] ?? null)->toBe(3)
        ->and($meter['level'] ?? null)->toBe('ok');
});

it('names a lapsed subscription so the banner can offer to renew it', function (): void {
    app(TenantContext::class)->runAsPlatform(fn () => Subscription::query()
        ->where('tenant_id', $this->tenant->getKey())
        ->update([
            'status' => Subscription::STATUS_CANCELED,
            'current_period_end' => now()->subMonth(),
            'grace_ends_at' => now()->subWeek(),
        ]));

    app(SubscriptionResolver::class)->forget();
    app(LimitResolver::class)->forget();

    // Lapsed, not locked out — the shop falls back to the free rung's credits and keeps
    // working (Gate 6). The prop says so, and the page still renders.
    expect(planField('lapsed'))->toBeTrue();
});

it('renders the page even when the catalogue cannot be resolved', function (): void {
    // The fallback plan is what every shop without a usable subscription depends on.
    // Point it at a plan that does not exist: `LimitResolver` throws, and the question is
    // whether that takes the dashboard down with it.
    config()->set('hamyar.quota.fallback_plan', 'no-such-plan');

    app(TenantContext::class)->runAsPlatform(fn () => Subscription::query()
        ->where('tenant_id', $this->tenant->getKey())->delete());

    app(SubscriptionResolver::class)->forget();
    app(LimitResolver::class)->forget();

    // No meters, but a working shop. This runs on every authenticated request, so a
    // misconfiguration here must cost the sidebar bars and nothing else — a shopkeeper
    // with a customer at the counter cannot be shown a white page because a plan row is
    // missing.
    $this->actingAs($this->user)->get($this->url.'/dashboard')
        ->assertOk()
        ->assertInertia(function ($page): void {
            expect(propsOf($page)['usage'] ?? ['not-set'])->toBe([]);
        });
});

it('never shows one shop another shop numbers', function (): void {
    spendQuota($this->tenant, 'sales.invoices', 4);

    $other = Tenant::factory()->withDomain()->create();
    subscribe($other, 'pro');
    app(TenantProvisioner::class)->seedRoles($other);

    $neighbour = app(TenantContext::class)->runFor($other, function (): User {
        $user = User::factory()->create();
        $user->assignRole('Owner');

        return $user;
    });

    app(SubscriptionResolver::class)->forget();
    app(LimitResolver::class)->forget();

    $invoices = null;

    $this->actingAs($neighbour)->get(appUrl().'/dashboard')
        ->assertOk()
        ->assertInertia(function ($page) use (&$invoices): void {
            /** @var array<string, mixed> $usage */
            $usage = propsOf($page)['usage'] ?? [];

            /** @var list<array<string, mixed>> $meters */
            $meters = $usage['meters'] ?? [];

            foreach ($meters as $meter) {
                if (($meter['key'] ?? null) === 'sales.invoices') {
                    $invoices = $meter;
                }
            }
        });

    /*
    | `usage_counters` is platform-owned: it carries RLS but no `BelongsToTenant`, so
    | nothing adds a tenant filter automatically and the snapshot runs inside
    | `runAsPlatform()`. An unscoped query there would put one shop's trading volume on
    | another shop's sidebar — which is why `bin/check-quota-scoping` exists, and why this
    | assertion is here as well as in the gate.
    */
    expect($invoices['used'] ?? null)->toBe(0);
})->group('isolation');

it('turns red for a shop refused on a credit it never had', function (): void {
    /*
    | The case the old implementation could not see, and the one that matters most
    | commercially.
    |
    | `blocked_at` used to be stamped with an UPDATE on `usage_counters`. A shop refused on
    | its FIRST attempt has no counter row for that UPDATE to match, so nothing was
    | stamped — and the metric most often capped at zero is `messaging.sms` on the free
    | rung, which is every shop that has never paid us anything.
    |
    | So the shops hitting a wall they cannot pass were exactly the shops the panel's
    | blocked-shops widget could not show, and that widget is the whole conversion
    | conversation. Read from `usage_events` now, which is written whether or not a counter
    | exists.
    */
    capQuota($this->tenant, 'messaging.sms', 0);

    expect(quotaRowExists($this->tenant, 'messaging.sms'))->toBeFalse();

    Illuminate\Support\Facades\Route::middleware(['web', 'auth', 'tenant', 'quota:messaging.sms'])
        ->post('/__zero_probe', fn (): string => 'through');

    $this->actingAs($this->user)->post($this->url.'/__zero_probe')
        ->assertSessionHasErrors('quota');

    expect(meterFor('messaging.sms')['level'] ?? null)->toBe('blocked')
        // Still no counter row: being refused is not a spend, and inventing a zero row to
        // hang a flag on would blur the one distinction the rest of this suite relies on.
        ->and(quotaRowExists($this->tenant, 'messaging.sms'))->toBeFalse();
});

it('stops showing red once the month has turned', function (): void {
    capQuota($this->tenant, 'sales.invoices', 0);

    Illuminate\Support\Facades\Route::middleware(['web', 'auth', 'tenant', 'quota:sales.invoices'])
        ->post('/__stale_probe', fn (): string => 'through');

    $this->actingAs($this->user)->post($this->url.'/__stale_probe')->assertSessionHasErrors('quota');

    expect(meterFor('sales.invoices')['level'] ?? null)->toBe('blocked');

    // Next Jalali month. The block was real and is now history; a colour that meant "has
    // ever hit a limit" is one nobody would act on twice.
    $this->travelTo(now()->addDays(40));

    expect(meterFor('sales.invoices')['level'] ?? null)->toBe('reached');
});
