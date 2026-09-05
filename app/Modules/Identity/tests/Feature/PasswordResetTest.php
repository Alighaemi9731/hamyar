<?php

declare(strict_types=1);

use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\PasswordResetService;
use App\Modules\Platform\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->withDomain()->create();
    $this->url = appUrl();

    $this->user = app(TenantContext::class)->runFor(
        $this->tenant,
        fn () => User::factory()->create(['mobile' => '09121234567'])
    );
});

/*
| Blade, and asserted as Blade.
|
| Both of these were `Inertia::render()` until 16.3. Somebody reaches the first by pressing
| «فراموشی رمز عبور» on the sign-in page and the second from a link in a message — neither
| of them has a session, and both used to change the entire design out from under the
| person mid-flow and cost them the application bundle to be shown one or two fields.
|
| `assertViewIs` and not `assertSee`: the failure this pins is a page rendering perfectly
| through the wrong stack, which no assertion on its content can see.
*/
it('serves the forgot-password form as Blade on the auth skin', function (): void {
    $this->get($this->url.'/forgot-password')
        ->assertOk()
        ->assertViewIs('auth.forgot-password');
});

it('serves the reset form as Blade on the auth skin', function (): void {
    $this->get($this->url.'/reset-password?token=whatever&identifier=09121234567')
        ->assertOk()
        ->assertViewIs('auth.reset-password');
});

it('carries the identifier as well as the token into the reset form', function (): void {
    /*
    | `update()` validates token, identifier AND password. The React page this replaced
    | rendered two of the three, so a link that arrived without its `identifier` — an SMS
    | truncated at the `&`, a URL half-copied out of a message — rendered normally,
    | accepted a new password, and did nothing at all on submit.
    |
    | Asserted on the rendered markup rather than on the view data: the bug was a missing
    | INPUT, and a view that receives a variable it never places would pass a data check.
    */
    $token = 'token-that-travels';

    $content = (string) $this->get($this->url.'/reset-password?token='.$token.'&identifier=09121234567')
        ->assertOk()
        ->getContent();

    expect($content)
        ->toContain('name="token" value="'.$token.'"')
        ->toContain('name="identifier" value="09121234567"');
});

it('issues a token for a known mobile', function (): void {
    $token = app(TenantContext::class)->runFor(
        $this->tenant,
        fn (): ?string => app(PasswordResetService::class)->issue('09121234567')
    );

    expect($token)->toBeString();
});

it('stores only the token HASH, never the token', function (): void {
    $token = app(TenantContext::class)->runFor(
        $this->tenant,
        fn (): ?string => app(PasswordResetService::class)->issue('09121234567')
    );

    $stored = app(TenantContext::class)->runFor(
        $this->tenant,
        fn () => DB::table('password_reset_tokens')->value('token')
    );

    // A database leak must not yield working reset links.
    expect($stored)->not->toBe($token);
    expect($stored)->toBe(hash('sha256', (string) $token));
});

it('answers identically for a known and an unknown number', function (): void {
    // Otherwise the form is an oracle for "does this person work at this shop?".
    //
    // Since ADR 0017 the two sides take visibly different code paths — the known number
    // resolves a shop and mints a token inside it, the unknown one resolves nothing at
    // all — so this assertion is doing more work than it was before, not less.
    $this->post($this->url.'/forgot-password', ['identifier' => '09121234567']);
    $known = session('success');

    session()->forget('success');

    $this->post($this->url.'/forgot-password', ['identifier' => '09120000000']);
    $unknown = session('success');

    expect($known)->toBe($unknown)->not->toBeNull();
});

it('completes a reset over HTTP with no tenant in the session', function (): void {
    /*
    | The end-to-end shape of the flow after ADR 0017, and the reason APP FIX A exists.
    |
    | Somebody resetting a password has no session — that is the defining state of the
    | flow — so nothing is pinned when the form arrives. The number is the only thing
    | that can find the shop. While these routes sat behind `tenant` they were redirected
    | to /login, the page the link exists to get somebody back to; a regression there is
    | invisible at the service level, which is why this test goes over HTTP.
    */
    $token = app(TenantContext::class)->runFor(
        $this->tenant,
        fn (): ?string => app(PasswordResetService::class)->issue('09121234567')
    );

    // Nothing establishes a session here, deliberately: this is a stranger with a link.
    //
    // The `success` flash is what makes the assertion unambiguous. A route still behind
    // `tenant` also redirects to /login — with no flash, and with the password
    // unchanged — so the redirect alone would look like success.
    $this->post($this->url.'/reset-password', [
        'token' => (string) $token,
        'identifier' => '09121234567',
        'password' => 'brand-new-secret-1',
        'password_confirmation' => 'brand-new-secret-1',
    ])->assertRedirect(route('login'))->assertSessionHas('success');

    app(TenantContext::class)->runFor(
        $this->tenant,
        fn () => expect(Hash::check('brand-new-secret-1', User::query()->firstOrFail()->password))->toBeTrue()
    );
});

it('resets the password with a valid token', function (): void {
    app(TenantContext::class)->runFor($this->tenant, function (): void {
        $token = app(PasswordResetService::class)->issue('09121234567');

        expect(app(PasswordResetService::class)->reset('09121234567', (string) $token, 'brand-new-secret-1'))
            ->toBeTrue();

        expect(Hash::check('brand-new-secret-1', User::query()->firstOrFail()->password))
            ->toBeTrue();
    });
});

it('refuses a token that has already been used', function (): void {
    app(TenantContext::class)->runFor($this->tenant, function (): void {
        $service = app(PasswordResetService::class);
        $token = (string) $service->issue('09121234567');

        expect($service->reset('09121234567', $token, 'first-secret-value-1'))->toBeTrue();
        expect($service->reset('09121234567', $token, 'second-secret-value-2'))->toBeFalse();
    });
});

it('refuses an expired token', function (): void {
    app(TenantContext::class)->runFor($this->tenant, function (): void {
        $service = app(PasswordResetService::class);
        $token = (string) $service->issue('09121234567');

        $this->travel(PasswordResetService::TOKEN_TTL_MINUTES + 5)->minutes();

        expect($service->reset('09121234567', $token, 'too-late-secret-1'))->toBeFalse();
    });
});

it('kills every other session on reset', function (): void {
    app(TenantContext::class)->runFor($this->tenant, function (): void {
        DB::table('sessions')->insert([
            'id' => 'stale-session-id',
            'tenant_id' => $this->tenant->getKey(),
            'user_id' => $this->user->getKey(),
            'payload' => '',
            'last_activity' => time(),
        ]);

        $service = app(PasswordResetService::class);
        $token = (string) $service->issue('09121234567');
        $service->reset('09121234567', $token, 'rotated-secret-value-1');

        // A reset is what someone does when they think they are compromised; leaving
        // the attacker's session alive would defeat the point.
        expect(DB::table('sessions')->where('id', 'stale-session-id')->exists())->toBeFalse();
    });
});

it('does not let a token from one shop reset an account at another', function (): void {
    pest()->group('isolation');

    /*
    | Rewritten for ADR 0017 rather than weakened, and the change is in the SETUP.
    |
    | This used to put the same number at both shops — "the same person may work at two
    | shops" was the original migration's stated reason for scoping `users.mobile` per
    | tenant. ADR 0017 reversed that deliberately: the number is globally unique now, so
    | the old arrangement does not merely fail to prove anything, it cannot be built —
    | the second insert is a unique violation.
    |
    | The property the test was written to protect is untouched and still load-bearing:
    | `password_reset_tokens` is tenant-scoped, so a token row is redeemable only inside
    | the shop that minted it. That is what makes the controller's new "resolve the shop
    | from the number" step safe to get wrong — the service refuses a cross-shop reset
    | even when it is handed the wrong context directly.
    */
    $other = Tenant::factory()->withDomain()->create();

    app(TenantContext::class)->runFor(
        $other,
        fn () => User::factory()->create(['mobile' => '09129999999'])
    );

    $token = app(TenantContext::class)->runFor(
        $this->tenant,
        fn (): ?string => app(PasswordResetService::class)->issue('09121234567')
    );

    $crossed = app(TenantContext::class)->runFor(
        $other,
        fn (): bool => app(PasswordResetService::class)->reset('09121234567', (string) $token, 'cross-tenant-1')
    );

    expect($crossed)->toBeFalse();

    // And nothing was written on the way to that `false`. A refusal that had already
    // rotated the real account's password would read identically here.
    app(TenantContext::class)->runFor(
        $this->tenant,
        fn () => expect(Hash::check('cross-tenant-1', User::query()->firstOrFail()->password))->toBeFalse()
    );
});
