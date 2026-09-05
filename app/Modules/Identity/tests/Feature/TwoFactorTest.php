<?php

declare(strict_types=1);

use App\Modules\Identity\Http\Controllers\TwoFactorController;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\TwoFactorService;
use App\Modules\Platform\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use PragmaRX\Google2FA\Google2FA;

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->withDomain()->create();
    $this->url = appUrl();
    $this->service = app(TwoFactorService::class);

    $this->user = app(TenantContext::class)->runFor(
        $this->tenant,
        fn () => User::factory()->create(['mobile' => '09121234567'])
    );
});

function currentCode(string $secret): string
{
    return app(Google2FA::class)->getCurrentOtp($secret);
}

it('does not enable 2FA until a code confirms the authenticator holds the secret', function (): void {
    app(TenantContext::class)->runFor($this->tenant, function (): void {
        $this->service->begin($this->user);

        // The most common way users lock themselves out is a QR that never saved.
        expect($this->user->fresh()?->hasTwoFactorEnabled())->toBeFalse();
    });
});

it('enables 2FA and issues recovery codes on a correct code', function (): void {
    app(TenantContext::class)->runFor($this->tenant, function (): void {
        ['secret' => $secret] = $this->service->begin($this->user);

        $codes = $this->service->confirm($this->user, currentCode($secret));

        expect($codes)->toBeArray()->toHaveCount(8);
        expect($this->user->fresh()?->hasTwoFactorEnabled())->toBeTrue();
    });
});

it('rejects a wrong code', function (): void {
    app(TenantContext::class)->runFor($this->tenant, function (): void {
        $this->service->begin($this->user);

        expect($this->service->confirm($this->user, '000000'))->toBeNull();
        expect($this->user->fresh()?->hasTwoFactorEnabled())->toBeFalse();
    });
});

it('accepts Persian digits in the code', function (): void {
    app(TenantContext::class)->runFor($this->tenant, function (): void {
        ['secret' => $secret] = $this->service->begin($this->user);

        $persian = App\Support\Digits::toPersian(currentCode($secret));

        expect($this->service->verify($this->user, $persian))->toBeTrue();
    });
});

it('never exposes the secret or recovery codes in JSON', function (): void {
    app(TenantContext::class)->runFor($this->tenant, function (): void {
        ['secret' => $secret] = $this->service->begin($this->user);
        $this->service->confirm($this->user, currentCode($secret));

        $json = $this->user->fresh()?->toJson() ?? '';

        expect($json)->not->toContain('two_factor_secret');
        expect($json)->not->toContain($secret);
    });
});

it('consumes a recovery code exactly once', function (): void {
    app(TenantContext::class)->runFor($this->tenant, function (): void {
        ['secret' => $secret] = $this->service->begin($this->user);
        $codes = $this->service->confirm($this->user, currentCode($secret)) ?? [];

        $code = $codes[0];

        // A reusable recovery code is just a second password.
        expect($this->service->consumeRecoveryCode($this->user, $code))->toBeTrue();
        expect($this->service->consumeRecoveryCode($this->user, $code))->toBeFalse();
    });
});

it('stops at the challenge instead of logging in when 2FA is on', function (): void {
    $secret = app(TenantContext::class)->runFor($this->tenant, function (): string {
        ['secret' => $secret] = $this->service->begin($this->user);
        $this->service->confirm($this->user, currentCode($secret));

        return $secret;
    });

    $this->withSession(securityCodeSession())->post($this->url.'/login', ['mobile' => '09121234567', 'password' => 'password', ...securityCodeAnswer()])
        ->assertRedirect(route('two-factor.challenge'));

    // The password is proven; the second factor is not. Nothing is authenticated yet.
    expect(auth()->check())->toBeFalse();
    expect(session()->has(TwoFactorController::PENDING_SESSION_KEY))->toBeTrue();

    $this->post($this->url.'/two-factor/challenge', ['code' => currentCode($secret)])
        ->assertRedirect(route('dashboard'));

    expect(auth()->check())->toBeTrue();
});

it('refuses the challenge with a wrong code', function (): void {
    app(TenantContext::class)->runFor($this->tenant, function (): void {
        ['secret' => $secret] = $this->service->begin($this->user);
        $this->service->confirm($this->user, currentCode($secret));
    });

    $this->withSession(securityCodeSession())->post($this->url.'/login', ['mobile' => '09121234567', 'password' => 'password', ...securityCodeAnswer()]);

    $this->post($this->url.'/two-factor/challenge', ['code' => '000000'])
        ->assertSessionHasErrors('code');

    expect(auth()->check())->toBeFalse();
});

it('redirects to login when there is no pending challenge', function (): void {
    $this->get($this->url.'/two-factor/challenge')->assertRedirect(route('login'));
});

it('serves the challenge as Blade on the auth skin', function (): void {
    /*
    | Blade since 16.3, and asserted as Blade. This screen sits in the MIDDLE of signing
    | in — the password is proven, the second factor is not — so it is reached straight off
    | the Blade sign-in page and has to look like it. `TwoFactorController::show()`, the
    | enrolment screen in settings, is a different case and stays React.
    */
    app(TenantContext::class)->runFor($this->tenant, function (): void {
        ['secret' => $secret] = $this->service->begin($this->user);
        $this->service->confirm($this->user, currentCode($secret));
    });

    $this->withSession(securityCodeSession())->post($this->url.'/login', ['mobile' => '09121234567', 'password' => 'password', ...securityCodeAnswer()]);

    $this->get($this->url.'/two-factor/challenge')
        ->assertOk()
        ->assertViewIs('auth.two-factor-challenge');
});

it('accepts a code typed on a Persian keyboard', function (): void {
    /*
    | Golden rule / design-system rule 4: anything a person typed is normalised to Latin
    | digits before it is compared. An Iranian phone with the keyboard in Persian produces
    | «۱۲۳۴۵۶», and this is the one screen where being unable to type your own code locks
    | you out of your own shop.
    */
    $secret = app(TenantContext::class)->runFor($this->tenant, function (): string {
        ['secret' => $secret] = $this->service->begin($this->user);
        $this->service->confirm($this->user, currentCode($secret));

        return $secret;
    });

    $this->withSession(securityCodeSession())->post($this->url.'/login', ['mobile' => '09121234567', 'password' => 'password', ...securityCodeAnswer()]);

    $this->post($this->url.'/two-factor/challenge', ['code' => App\Support\Digits::toPersian(currentCode($secret))])
        ->assertRedirect(route('dashboard'));

    expect(auth()->check())->toBeTrue();
});

it('accepts a recovery code typed on a Persian keyboard', function (): void {
    /*
    | The half `TwoFactorService::verify()` was NOT covering, and the reason
    | `TwoFactorController::verify()` normalises rather than leaving it to the service.
    |
    | Recovery codes are `Str::random(5).'-'.Str::random(5)` — alphanumeric, so most of
    | them contain digits — and `consumeRecoveryCode()` compares with `hash_equals` after
    | nothing but a `trim()`. Somebody reading one off a printout with their keyboard still
    | in Persian was failing a constant-time compare against a code they had typed
    | correctly, on the screen they reach precisely because they have lost their phone.
    */
    $codes = app(TenantContext::class)->runFor($this->tenant, function (): array {
        ['secret' => $secret] = $this->service->begin($this->user);

        return (array) $this->service->confirm($this->user, currentCode($secret));
    });

    $this->withSession(securityCodeSession())->post($this->url.'/login', ['mobile' => '09121234567', 'password' => 'password', ...securityCodeAnswer()]);

    $this->post($this->url.'/two-factor/challenge', [
        'recovery_code' => App\Support\Digits::toPersian((string) $codes[0]),
    ])->assertRedirect(route('dashboard'));

    expect(auth()->check())->toBeTrue();
});
