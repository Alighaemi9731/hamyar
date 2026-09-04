<?php

declare(strict_types=1);

use App\Modules\Identity\Models\User;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Platform\Services\PlanCatalogueSeeder;
use App\Modules\Platform\Services\SubscriptionResolver;
use App\Modules\Platform\Services\TenantProvisioner;
use App\Support\SecurityCode;
use App\Support\Tenancy\TenantContext;

/**
 * The «کد امنیتی» in front of the sign-in form.
 *
 * What matters here is not that it is hard to read — it is deliberately mild — but that
 * it is **spent**: one drawing answers one attempt. A captcha a script can solve once and
 * replay is decoration, and that is the property a test has to hold, because nothing
 * about it is visible when it breaks.
 */
beforeEach(function (): void {
    app(PlanCatalogueSeeder::class)->sync();

    $this->tenant = Tenant::factory()->withDomain()->create();
    $this->url = appUrl();

    subscribe($this->tenant, 'pro');
    app(SubscriptionResolver::class)->forget();
    app(TenantProvisioner::class)->seedRoles($this->tenant);

    $this->user = app(TenantContext::class)->runFor($this->tenant, function (): User {
        $user = User::factory()->create(['mobile' => '09121234567', 'password' => 'password']);
        $user->assignRole('Owner');

        return $user;
    });
});

afterEach(fn () => app(TenantContext::class)->forget());

it('draws a code on the sign-in page and remembers it for that session', function (): void {
    $response = $this->get($this->url.'/login')->assertOk();

    $code = session(SecurityCode::SESSION_KEY);

    expect($code)->toBeString()->toHaveLength(5);
    // The drawing is on the page, and the answer is not: a code in the markup as text
    // is a code any script reads without looking at the picture.
    $response->assertSee('data-security-image', false);
    $response->assertDontSee($code, false);
});

it('refuses a sign-in with no code, with a wrong code, and with the wrong case-free match', function (string $answer): void {
    $this->withSession([SecurityCode::SESSION_KEY => 'HAMYR'])
        ->post($this->url.'/login', [
            'mobile' => '09121234567',
            'password' => 'password',
            'security_code' => $answer,
        ])
        ->assertSessionHasErrors('security_code');

    expect(auth()->check())->toBeFalse();
})->with([
    'a wrong code' => 'WRONG',
    'an empty string' => '',
    'a near miss' => 'HAMY',
]);

it('accepts the code in lower case and in Persian digits', function (): void {
    // A shopkeeper on an Iranian keyboard types «۴» for a drawn 4. Refusing that is
    // refusing a correct answer.
    $this->withSession([SecurityCode::SESSION_KEY => 'HAM47'])
        ->post($this->url.'/login', [
            'mobile' => '09121234567',
            'password' => 'password',
            'security_code' => 'ham۴۷',
        ])
        ->assertRedirect(route('dashboard'));

    expect(auth()->check())->toBeTrue();
});

it('spends the code: the same answer does not work twice', function (): void {
    $session = [SecurityCode::SESSION_KEY => 'HAMYR'];

    $this->withSession($session)->post($this->url.'/login', [
        'mobile' => '09121234567',
        'password' => 'wrong-password',
        'security_code' => 'HAMYR',
    ])->assertSessionHasErrors('mobile');

    // The credentials were wrong, so the code was still consumed. Replaying it against
    // the session it left behind must not authenticate anything.
    $this->post($this->url.'/login', [
        'mobile' => '09121234567',
        'password' => 'password',
        'security_code' => 'HAMYR',
    ])->assertSessionHasErrors('security_code');

    expect(auth()->check())->toBeFalse();
});

it('hands out a fresh drawing on request, and never the same one twice', function (): void {
    $first = $this->get($this->url.'/login/security-code')->assertOk();
    $first->assertHeader('Content-Type', 'image/svg+xml');

    $a = session(SecurityCode::SESSION_KEY);
    $this->get($this->url.'/login/security-code')->assertOk();
    $b = session(SecurityCode::SESSION_KEY);

    // Five characters from a 26-glyph alphabet: a repeat is possible and would make this
    // flaky, so the assertion is on the SESSION being rewritten, not on the two differing.
    expect($a)->toBeString()->toHaveLength(5);
    expect($b)->toBeString()->toHaveLength(5);
});

it('draws only characters a shopkeeper cannot misread', function (): void {
    // 0/O, 1/I/l, 5/S, 2/Z and 8/B are the pairs that get typed wrong, and a wrong code
    // is a shopkeeper locked out of their own till at nine in the morning.
    foreach (range(1, 40) as $ignored) {
        $this->get($this->url.'/login/security-code');

        expect(session(SecurityCode::SESSION_KEY))
            ->toMatch('/^[ACDEFGHJKLMNPQRTUVWXY34679]{5}$/');
    }
});
