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
    $response = $this->post(centralUrl('/register'), onboardingPayload());

    $tenant = Tenant::query()->where('slug', 'iranian-mobile')->firstOrFail();

    expect($tenant->name)->toBe('موبایل ایرانیان');
    expect($tenant->status)->toBe(Tenant::STATUS_TRIALING);
    expect($tenant->trial_ends_at)->not->toBeNull(); // @phpstan-ignore-line

    // The hostname is what the middleware actually resolves on, so assert on it
    // rather than on the slug.
    expect(Domain::query()->where('hostname', Domain::hostnameFor('iranian-mobile'))->exists())->toBeTrue();

    // The redirect stays on this origin and the shop's address is handed over on the
    // next page — a cross-origin redirect out of a form POST is blocked by
    // `form-action 'self'`, which is the bug this flow has shipped twice. We still never
    // authenticate across the hostname boundary; the person signs in themselves.
    $response->assertRedirect(centralUrl('/register/done'));
});

it('gives the owner the Owner role with every permission', function (): void {
    $this->post(centralUrl('/register'), onboardingPayload());

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
    $this->post(centralUrl('/register'), onboardingPayload());

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
    $this->post(centralUrl('/register'), onboardingPayload());

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
    $this->post(centralUrl('/register'), onboardingPayload(['subdomain' => $subdomain]))
        ->assertSessionHasErrors('subdomain');

    expect(Tenant::query()->count())->toBe(0);
})->with(['www', 'admin', 'api', 'support', 'billing', 'mobishop']);

it('rejects a subdomain that is already taken', function (): void {
    $this->post(centralUrl('/register'), onboardingPayload());

    $this->post(centralUrl('/register'), onboardingPayload([
        'owner_mobile' => '09129999999',
        'owner_email' => 'other@example.test',
    ]))->assertSessionHasErrors('subdomain');

    expect(Tenant::query()->count())->toBe(1);
});

it('rejects malformed subdomains', function (string $subdomain): void {
    $this->post(centralUrl('/register'), onboardingPayload(['subdomain' => $subdomain]))
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
    $this->post(centralUrl('/register'), onboardingPayload([
        'owner_mobile' => '۰۹۱۲۱۲۳۴۵۶۷',
    ]));

    $tenant = Tenant::query()->where('slug', 'iranian-mobile')->firstOrFail();

    $mobile = app(TenantContext::class)->runFor(
        $tenant,
        fn (): ?string => User::query()->firstOrFail()->mobile
    );

    expect($mobile)->toBe('09121234567');
});

it('reports subdomain availability', function (): void {
    $this->postJson(centralUrl('/register/check-subdomain'), ['subdomain' => 'brand-new'])
        ->assertOk()
        ->assertJson(['ok' => true]);

    $this->postJson(centralUrl('/register/check-subdomain'), ['subdomain' => 'admin'])
        ->assertOk()
        ->assertJson(['ok' => false]);
});

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
*/
it('keeps the post-registration redirect on this origin, so no CSP directive can block it', function (): void {
    $response = $this->post(centralUrl('/register'), onboardingPayload());

    $response->assertRedirect(centralUrl('/register/done'));

    $target = $response->headers->get('Location');

    expect(parse_url((string) $target, PHP_URL_HOST))
        ->toBe(config()->string('app.domain'))
        ->and(Tenant::query()->where('slug', 'iranian-mobile')->exists())->toBeTrue();
});

it('hands the new shop\'s address over on the next page', function (): void {
    $this->post(centralUrl('/register'), onboardingPayload())
        ->assertRedirect(centralUrl('/register/done'));

    $this->get(centralUrl('/register/done'))
        ->assertOk()
        ->assertSee('موبایل ایرانیان', false)
        ->assertSee(Domain::hostnameFor('iranian-mobile').'/login', false);
});

/*
| The hand-over page reads the destination from the session and from nowhere else.
|
| A `?shop=` parameter would let anybody render "your shop is ready, click here to sign
| in" pointing at a hostname they chose — phishing, on our own domain, in our own
| wrapper. Reached directly with no flash, it must go back to the form.
*/
it('refuses to render the hand-over page without a registration behind it', function (): void {
    $this->get(centralUrl('/register/done'))->assertRedirect(centralUrl('/register'));
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

    $this->post(centralUrl('/register'), $payload)->assertRedirect();

    $tenant = Tenant::query()->latest('id')->firstOrFail();

    expect($tenant->slug)->toStartWith('shop-')
        ->and($tenant->slug)->toMatch('/^shop-[a-z0-9]{6}$/');
});

it('gives two shops registered without a subdomain different addresses', function (): void {
    $first = onboardingPayload(['owner_mobile' => '09120000001']);
    $second = onboardingPayload(['owner_mobile' => '09120000002']);
    unset($first['subdomain'], $second['subdomain']);

    $this->post(centralUrl('/register'), $first)->assertRedirect();
    $this->post(centralUrl('/register'), $second)->assertRedirect();

    $slugs = Tenant::query()
        ->where('slug', 'like', 'shop-%')
        ->pluck('slug');

    expect($slugs)->toHaveCount(2)
        ->and($slugs->unique())->toHaveCount(2);
});

it('still honours a subdomain when one is supplied', function (): void {
    $this->post(centralUrl('/register'), onboardingPayload(['subdomain' => 'chosen-name']))
        ->assertRedirect();

    expect(Tenant::query()->latest('id')->firstOrFail()->slug)
        ->toBe('chosen-name');
});
