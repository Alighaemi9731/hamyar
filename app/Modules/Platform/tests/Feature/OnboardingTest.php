<?php

declare(strict_types=1);

use App\Modules\Identity\Models\User;
use App\Modules\Platform\Models\Domain;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Platform\Services\TenantProvisioner;
use App\Support\Tenancy\TenantContext;

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function onboardingPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'موبایل ایرانیان',
        'subdomain' => 'iranian-mobile',
        'owner_name' => 'رضا محمدی',
        'owner_mobile' => '09121234567',
        'owner_email' => 'reza@example.test',
        'password' => 'Correct-Horse-9',
        'password_confirmation' => 'Correct-Horse-9',
        'accept_terms' => true,
    ], $overrides);
}

it('provisions a working shop from the wizard', function (): void {
    $response = $this->post(appUrl('/register'), onboardingPayload());

    $tenant = Tenant::query()->where('slug', 'iranian-mobile')->firstOrFail();

    expect($tenant->name)->toBe('موبایل ایرانیان');
    expect($tenant->status)->toBe(Tenant::STATUS_TRIALING);
    expect($tenant->trial_ends_at)->not->toBeNull(); // @phpstan-ignore-line

    // The `domains` row is still written, and still asserted on. ADR 0017 retires it as
    // the RESOLUTION mechanism — nothing reads a hostname to find a tenant any more —
    // but keeps it as the record of the shop's slug (ADR 0017, Consequences). A
    // provisioning run that skipped it would leave that record missing and nothing else
    // in this suite would notice.
    expect(Domain::query()->where('hostname', Domain::hostnameFor('iranian-mobile'))->exists())->toBeTrue();

    // Straight to the shared login page, on the same origin, with a message waiting
    // there. The interstitial that used to hand over a per-shop address went with the
    // per-shop addresses. We still never authenticate the new owner automatically; they
    // sign in themselves, which proves the credentials they just chose work.
    $response->assertRedirect(appUrl('/login'))->assertSessionHas('status');

    expect(session('status'))->not->toBeNull();
});

it('gives the owner the Owner role with every permission', function (): void {
    $this->post(appUrl('/register'), onboardingPayload());

    $tenant = Tenant::query()->where('slug', 'iranian-mobile')->firstOrFail();

    app(TenantContext::class)->runFor($tenant, function (): void {
        /** @var User $owner */
        $owner = User::query()->firstOrFail();

        expect($owner->hasRole('Owner'))->toBeTrue();
        expect($owner->can('sales.create'))->toBeTrue();
        expect($owner->can('billing.manage'))->toBeTrue();
    });
});

it('seeds all seven roles for the new tenant', function (): void {
    $this->post(appUrl('/register'), onboardingPayload());

    $tenant = Tenant::query()->where('slug', 'iranian-mobile')->firstOrFail();

    $roles = app(TenantContext::class)->runFor(
        $tenant,
        fn (): array => Spatie\Permission\Models\Role::query()->pluck('name')->all()
    );

    expect($roles)->toHaveCount(7)
        ->toContain('Owner', 'Manager', 'Cashier', 'Salesperson', 'Technician', 'Accountant', 'Warehousekeeper');
});

it('does not let a Salesperson see costs or profit', function (): void {
    // The boundary the role split exists for: staff turnover is high and margins are
    // the most commercially sensitive thing in the shop.
    $this->post(appUrl('/register'), onboardingPayload());

    $tenant = Tenant::query()->where('slug', 'iranian-mobile')->firstOrFail();

    app(TenantContext::class)->runFor($tenant, function (): void {
        $seller = User::factory()->create();
        $seller->assignRole('Salesperson');

        expect($seller->can('sales.create'))->toBeTrue();
        expect($seller->can('inventory.view_cost'))->toBeFalse();
        expect($seller->can('sales.view_profit'))->toBeFalse();
        expect($seller->can('repairs.reveal_passcode'))->toBeFalse();
    });
});

it('rejects a reserved subdomain', function (string $subdomain): void {
    $this->post(appUrl('/register'), onboardingPayload(['subdomain' => $subdomain]))
        ->assertSessionHasErrors('subdomain');

    expect(Tenant::query()->count())->toBe(0);
})->with(['www', 'admin', 'api', 'support', 'billing', 'hamyar']);

it('rejects a subdomain that is already taken', function (): void {
    $this->post(appUrl('/register'), onboardingPayload());

    $this->post(appUrl('/register'), onboardingPayload([
        'owner_mobile' => '09129999999',
        'owner_email' => 'other@example.test',
    ]))->assertSessionHasErrors('subdomain');

    expect(Tenant::query()->count())->toBe(1);
});

it('rejects malformed subdomains', function (string $subdomain): void {
    $this->post(appUrl('/register'), onboardingPayload(['subdomain' => $subdomain]))
        ->assertSessionHasErrors('subdomain');
})->with([
    'ab',            // too short
    '-leading',      // starts with a hyphen
    'trailing-',     // ends with a hyphen
    'has--double',   // punycode-lookalike prefix
]);

it('normalises Persian digits in the mobile number', function (): void {
    // Iranian keyboards emit Persian digits constantly; the same number typed either
    // way must behave identically.
    $this->post(appUrl('/register'), onboardingPayload([
        'owner_mobile' => '۰۹۱۲۱۲۳۴۵۶۷',
    ]));

    $tenant = Tenant::query()->where('slug', 'iranian-mobile')->firstOrFail();

    $mobile = app(TenantContext::class)->runFor(
        $tenant,
        fn (): ?string => User::query()->firstOrFail()->mobile
    );

    expect($mobile)->toBe('09121234567');
});

// The availability endpoint went with the field: ADR 0017 removed the address chooser
// from the form, so `/register/check-subdomain` is no longer routed anywhere.

it('rolls the whole thing back if any step fails', function (): void {
    // Provisioning is one transaction. A tenant row whose domain insert failed would
    // be a shop nobody can reach; a domain whose roles failed would be a customer
    // locked out of the product they just signed up for.
    $provisioner = app(TenantProvisioner::class);

    $payload = [
        'name' => 'First',
        'subdomain' => 'contested',
        'owner_name' => 'اول',
        'owner_mobile' => '09120000000',
        'owner_email' => null,
        'password' => 'secret-secret-1',
    ];

    $provisioner->provision($payload);

    expect(Tenant::query()->count())->toBe(1);

    // Same subdomain again, straight at the service so validation cannot intercept.
    // The unique index on domains.hostname fires *after* the tenant row is inserted,
    // which is precisely the half-finished state the transaction has to undo.
    expect(fn () => $provisioner->provision([...$payload, 'name' => 'Second', 'owner_mobile' => '09121111111']))
        ->toThrow(Illuminate\Database\QueryException::class);

    expect(Tenant::query()->count())->toBe(1);
    expect(Tenant::query()->where('name', 'Second')->exists())->toBeFalse();
});

/*
| Found on staging TWICE, both times by filling the real form on the real domain, and
| both times the same shape: the POST succeeds, the shop is created, and the person who
| asked for it is left on an unchanged form with a console error they will never open.
|
| Round one: `redirect()->away()`, followed by Inertia's axios as a cross-origin XHR and
| blocked by `connect-src 'self'`. Fixed with `Inertia::location()`.
|
| Round two: the page was rebuilt in Blade, so the form became an ordinary POST,
| `Inertia::location()` degraded to the plain 302 it promises for non-Inertia requests,
| and Chrome blocked that against `form-action 'self'` — which it evaluates against
| redirect TARGETS while naming the original action in the message.
|
| The durable lesson is narrower than "CSP is fiddly", and it is what this test pins:
| **no redirect out of a form POST can reach another origin.** Only a link can. So the
| redirect must stay on this host, whatever the mechanism above it happens to be.
|
| Asserting "the response is a 302 to somewhere" would pass for the cross-origin version
| that broke twice. The assertion has to be about the HOST.
|
| Round three (ADR 0017) removed the boundary rather than the symptom: the form and its
| destination are both on `app.<apex>` now, so the host this pins is the app host. The
| test stays, because "there is nowhere to cross to" is a property of today's routing
| table, not a law — the moment somebody points this redirect at the apex or at a shop
| address again, the bug is back and this is what says so.
*/
it('keeps the post-registration redirect on this origin, so no CSP directive can block it', function (): void {
    $response = $this->post(appUrl('/register'), onboardingPayload());

    $response->assertRedirect(appUrl('/login'));

    $target = $response->headers->get('Location');

    expect(parse_url((string) $target, PHP_URL_HOST))
        ->toBe('app.'.config()->string('app.domain'))
        ->and(Tenant::query()->where('slug', 'iranian-mobile')->exists())->toBeTrue();
});

/*
| What replaced the hand-over page.
|
| Two tests used to live here: one asserting `/register/done` named the new shop and
| printed its `<slug>.<apex>/login` address, and one asserting that page refused to
| render without a registration behind it — because a `?shop=` parameter would have let
| anybody produce "your shop is ready, sign in here" pointing at a hostname of their
| choosing. Both guarantees were about an address hand-over, and ADR 0017 deleted the
| addresses, the hand-over and the page.
|
| The new true thing is narrower and is what this pins: registration lands on the ONE
| login page every shop uses, it names no shop and hands over no address, and the only
| thing carried across is a flash message — which the page reads from the session and
| from nowhere else, so no URL can make it say anything.
*/
it('lands the new owner on the shared login page with the message waiting there', function (): void {
    $this->post(appUrl('/register'), onboardingPayload())
        ->assertRedirect(appUrl('/login'));

    $flashed = session('status');
    $status = is_string($flashed) ? $flashed : '';

    expect($status)->not->toBe('');

    $this->get(appUrl('/login'))
        ->assertOk()
        ->assertSee($status, false)
        ->assertDontSee('موبایل ایرانیان', false)
        ->assertDontSee(Domain::hostnameFor('iranian-mobile'), false);

    // Flash and nothing else: the second visit is a plain login page again.
    $this->get(appUrl('/login'))
        ->assertOk()
        ->assertDontSee($status, false);
});

/*
| The sign-up form stopped asking for an address.
|
| ADR 0017 retires per-shop hostnames, so the field is gone from the page and the slug
| is generated. These pin the generated path, because every test above submits a
| subdomain explicitly and would keep passing if the derivation were removed tomorrow —
| the form would then post nothing, validation would reject it, and no test would say so.
*/

it('provisions a shop when the form sends no subdomain at all', function (): void {
    $payload = onboardingPayload();
    unset($payload['subdomain']);

    $this->post(appUrl('/register'), $payload)->assertRedirect();

    $tenant = Tenant::query()->latest('id')->firstOrFail();

    expect($tenant->slug)->toStartWith('shop-')
        ->and($tenant->slug)->toMatch('/^shop-[a-z0-9]{6}$/');
});

it('gives two shops registered without a subdomain different addresses', function (): void {
    $first = onboardingPayload(['owner_mobile' => '09120000001']);
    $second = onboardingPayload(['owner_mobile' => '09120000002']);
    unset($first['subdomain'], $second['subdomain']);

    $this->post(appUrl('/register'), $first)->assertRedirect();
    $this->post(appUrl('/register'), $second)->assertRedirect();

    $slugs = Tenant::query()
        ->where('slug', 'like', 'shop-%')
        ->pluck('slug');

    expect($slugs)->toHaveCount(2)
        ->and($slugs->unique())->toHaveCount(2);
});

it('still honours a subdomain when one is supplied', function (): void {
    $this->post(appUrl('/register'), onboardingPayload(['subdomain' => 'chosen-name']))
        ->assertRedirect();

    expect(Tenant::query()->latest('id')->firstOrFail()->slug)
        ->toBe('chosen-name');
});
