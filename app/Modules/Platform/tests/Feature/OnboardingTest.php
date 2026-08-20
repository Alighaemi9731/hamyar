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

    // Sent to their own subdomain to log in — we never authenticate across the
    // hostname boundary, because the session cookie is scoped to the tenant domain.
    $response->assertRedirect('http://'.Domain::hostnameFor('iranian-mobile').'/login');
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
| Found on staging, by filling the real form on the real domain.
|
| The test above posts a PLAIN request, so `Inertia::location()` degrades to an ordinary
| 302 and it passes — and it passed just as happily when the controller returned
| `redirect()->away()`, which nobody could actually complete. The browser submits this
| form with axios and follows redirects as XHR; a shop lives on its own hostname, so the
| destination is a different origin and our own `connect-src 'self'` blocks the fetch.
|
| The shop was provisioned either way. What differed was whether the person who asked for
| it ever found out — the failure left them on an unchanged form with a console error, and
| pressing the button again told them the address was taken, by their own shop.
|
| So this test sends the header that makes the request Inertia, which is the only
| condition under which the fault exists.
*/
it('sends an Inertia submission to the new shop with a full page visit, not an XHR redirect', function (): void {
    $response = $this->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => ''])
        ->post(centralUrl('/register'), onboardingPayload());

    // 409 + X-Inertia-Location is what the client turns into `window.location = …`.
    // A 302 here would be followed by axios as a cross-origin fetch and blocked by CSP.
    $response->assertStatus(409);
    $response->assertHeader('X-Inertia-Location', 'http://'.Domain::hostnameFor('iranian-mobile').'/login');

    expect(Tenant::query()->where('slug', 'iranian-mobile')->exists())->toBeTrue();
});
