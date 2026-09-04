<?php

declare(strict_types=1);

use App\Modules\Identity\Models\User;
use App\Modules\Platform\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

/**
 * One login form, for every shop.
 *
 * [ADR 0017](../../../../../docs/adr/0017-single-host-app.md) removed per-shop
 * hostnames, so `appUrl()` takes no tenant: the form is posted to the same address by
 * everybody and the tenant is what authenticating *produces*, read from the matched
 * user's own record and written into the session. Nothing in this file may put a shop
 * in the URL — there is nowhere left to put it.
 */
beforeEach(function (): void {
    $this->tenant = Tenant::factory()->withDomain()->create();
    $this->url = appUrl();

    $this->user = app(TenantContext::class)->runFor(
        $this->tenant,
        fn () => User::factory()->create(['mobile' => '09121234567'])
    );
});

it('logs a user in with the right credentials', function (): void {
    $this->withSession(securityCodeSession())->post($this->url.'/login', [
        ...securityCodeAnswer(),
        'mobile' => '09121234567',
        'password' => 'password',
    ])->assertRedirect(route('dashboard'));

    expect(auth()->check())->toBeTrue();
});

it('accepts Persian digits in the mobile field', function (): void {
    $this->withSession(securityCodeSession())->post($this->url.'/login', [
        ...securityCodeAnswer(),
        'mobile' => '۰۹۱۲۱۲۳۴۵۶۷',
        'password' => 'password',
    ])->assertRedirect(route('dashboard'));
});

it('rejects a wrong password', function (): void {
    $this->withSession(securityCodeSession())->post($this->url.'/login', [
        ...securityCodeAnswer(),
        'mobile' => '09121234567',
        'password' => 'not-the-password',
    ])->assertSessionHasErrors('mobile');

    expect(auth()->check())->toBeFalse();
});

it('gives the same message for an unknown user as for a wrong password', function (): void {
    // Distinguishing them would let anyone enumerate which staff work at a shop.
    $unknown = $this->withSession(securityCodeSession())->post($this->url.'/login', [
        ...securityCodeAnswer(),
        'mobile' => '09120000000',
        'password' => 'whatever',
    ]);

    $wrongPassword = $this->withSession(securityCodeSession())->post($this->url.'/login', [
        ...securityCodeAnswer(),
        'mobile' => '09121234567',
        'password' => 'wrong',
    ]);

    expect($unknown->getSession()->get('errors')?->first('mobile'))
        ->toBe($wrongPassword->getSession()->get('errors')?->first('mobile'));
});

it('refuses a deactivated account', function (): void {
    app(TenantContext::class)->runFor(
        $this->tenant,
        fn () => $this->user->forceFill(['is_active' => false])->save()
    );

    $this->withSession(securityCodeSession())->post($this->url.'/login', [
        ...securityCodeAnswer(),
        'mobile' => '09121234567',
        'password' => 'password',
    ])->assertSessionHasErrors('mobile');

    expect(auth()->check())->toBeFalse();
});

it('records the login timestamp', function (): void {
    $this->withSession(securityCodeSession())->post($this->url.'/login', ['mobile' => '09121234567', 'password' => 'password', ...securityCodeAnswer()]);

    $fresh = app(TenantContext::class)->runFor($this->tenant, fn () => $this->user->fresh());

    expect($fresh?->last_login_at)->not->toBeNull();
});

it('throttles repeated failures', function (): void {
    foreach (range(1, 5) as $ignored) {
        $this->withSession(securityCodeSession())->post($this->url.'/login', ['mobile' => '09121234567', 'password' => 'wrong', ...securityCodeAnswer()]);
    }

    $response = $this->withSession(securityCodeSession())->post($this->url.'/login', ['mobile' => '09121234567', 'password' => 'wrong', ...securityCodeAnswer()]);

    expect($response->getSession()->get('errors')?->first('mobile'))
        ->toContain('تلاش‌های ناموفق زیاد');
});

/*
| This replaces `it('scopes the throttle to the tenant, so one shop cannot lock out
| another')`, which asserted that a shop under attack could not lock the same mobile
| number out of a *different* shop.
|
| That guarantee has not been weakened — its premise stopped existing. ADR 0017 made
| `users.mobile` globally unique (a partial index over live rows), so one number is one
| account at one shop and there is no second shop to lock out. The old test's setup —
| a second `User` with `09121234567` in another tenant — is now a constraint violation
| before it reaches a single assertion.
|
| So the thing worth proving here is the constraint itself, because it is what the
| single login form rests on: `AccountLookup` resolves a number across every tenant and
| takes the first row, which is only sound while a number cannot match two.
*/
it('refuses a second account on the same mobile number, whichever shop asks', function (): void {
    $other = Tenant::factory()->withDomain()->create();

    /*
    | The failing insert is wrapped in `DB::transaction()` so Postgres has a SAVEPOINT
    | to unwind to. Without it the 23505 aborts RefreshDatabase's own transaction and
    | every statement after this line dies with 25P02 (CLAUDE.md, third occurrence).
    |
    | And the catch — Pest's `toThrow` — is OUTSIDE the wrapper, which is the half that
    | is easy to get wrong: a closure that swallows its own exception never triggers the
    | savepoint rollback at all.
    */
    expect(fn (): User => app(TenantContext::class)->runFor(
        $other,
        fn (): User => DB::transaction(
            fn (): User => User::factory()->create(['mobile' => '09121234567'])
        ),
    ))->toThrow(QueryException::class);

    // The number still signs in the account that claimed it first — and this request
    // could not answer at all on a connection the violation had poisoned, so it doubles
    // as proof the savepoint above did its job.
    $this->withSession(securityCodeSession())->post($this->url.'/login', [
        ...securityCodeAnswer(),
        'mobile' => '09121234567',
        'password' => 'password',
    ])->assertRedirect(route('dashboard'));
});

it('clears the throttle on a successful login', function (): void {
    /*
    | The tenant segment is `central`, and that is the real value rather than a stand-in
    | for one we could not compute.
    |
    | `LoginRequest::throttleKey()` reads `TenantContext::id() ?? 'central'`. Since
    | ADR 0017 nothing is pinned while somebody is typing their number in — the tenant is
    | the RESULT of authenticating, not context the request arrives with — and `/login`
    | sits outside the `tenant` middleware group for exactly that reason. The controller
    | calls `clearRateLimit()` before it pins anything, so both halves use one key.
    |
    | One key per number+IP across the whole platform is the correct shape now that a
    | number identifies exactly one account.
    */
    $key = 'login|central|09121234567|127.0.0.1';

    $this->withSession(securityCodeSession())->post($this->url.'/login', ['mobile' => '09121234567', 'password' => 'wrong', ...securityCodeAnswer()]);

    // Asserted BEFORE the success, deliberately: the final expectation is satisfied just
    // as well by a key nothing was ever recorded under, which is how this test stayed
    // green while the key it named had drifted away from the one the application uses.
    expect(RateLimiter::attempts($key))->toBe(1);

    $this->withSession(securityCodeSession())->post($this->url.'/login', ['mobile' => '09121234567', 'password' => 'password', ...securityCodeAnswer()]);

    expect(RateLimiter::attempts($key))->toBe(0);
});

it('logs out', function (): void {
    $this->actingAs($this->user)
        ->post($this->url.'/logout')
        ->assertRedirect(route('login'));

    expect(auth()->check())->toBeFalse();
});
