<?php

declare(strict_types=1);

use App\Modules\Identity\Models\Invitation;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\TwoFactorService;
use App\Modules\Platform\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use PragmaRX\Google2FA\Google2FA;

/*
|--------------------------------------------------------------------------
| Every one of the six auth forms shows the errors it is refused with
|--------------------------------------------------------------------------
|
| ## The rule, and the failure it is written from
|
| From CLAUDE.md: *every form renders every key of the error bag, not just the ones you
| thought to place. A submit that silently does nothing is how an operator concludes the
| software is broken.* `bin/check-form-errors` gates that on the React side by insisting a
| submitting component also renders `<FormErrors>`; the roadmap named this file because
| **no test anywhere stated the invariant itself** — only the shape that usually implies it.
|
| The six auth screens are now Blade (ADR 0021), which puts them out of that gate's reach
| entirely: it scans `resources/js` and `app/Modules` for a React component. Their whole
| error rendering is eight lines of `$errors->all()` in `auth/layout.blade.php`. Delete
| those lines and every check in this repository still passes, while all six forms go
| silent — you press «ورود» and the page comes back looking untouched.
|
| ## Why the assertion is on the RENDERED page
|
| `assertSessionHasErrors()` says the server refused. It says nothing about whether anybody
| is ever told, and the bug this file exists for lives exactly in that gap: the refusal is
| flashed correctly, the redirect is correct, and the page that receives it draws no error
| anywhere. So each test below reads the message the server actually produced out of the
| bag, follows the redirect, and asserts that string is on the screen. Nothing here
| hardcodes Persian — a copy edit must not be able to fail these, and a copy edit must not
| be able to make them pass either.
|
| ## The key chosen for each form
|
| The gate's docblock frames the risk as "a key nobody placed". On these Blade pages that
| is *every* key: not one of the six places a message beside a field — `@error` appears
| only to set `aria-invalid` — so the layout's region is the whole of their error
| rendering. Each test below still picks the failure that shows it most sharply, preferring
| a key with no input at all on the page, and says which it is and why.
*/

/** The message the server actually produced, read back out of the flashed bag. */
function refusalFor(string $key): string
{
    /** @var ViewErrorBag|null $bag */
    $bag = session('errors');

    return $bag === null ? '' : (string) $bag->first($key);
}

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->withDomain()->create();
    $this->url = appUrl();
});

/* ------------------------------------------------------------------ login -- */

it('shows the sign-in form the security-code refusal', function (): void {
    /*
    | `security_code` is added in `LoginRequest::withValidator()` — an error appended by
    | hand after the rules have already passed, which is the exact shape the form-errors
    | gate was written about: a key no rule declares and therefore no field was placed for.
    | It is also the most common real refusal on this page, because the drawing is hard on
    | purpose.
    */
    $this->get($this->url.'/login')->assertOk();

    $this->withSession(securityCodeSession())
        ->post($this->url.'/login', [
            'mobile' => '09121234567',
            'password' => 'password',
            'security_code' => 'WRONG',
        ])
        ->assertRedirect($this->url.'/login')
        ->assertSessionHasErrors('security_code');

    $message = refusalFor('security_code');
    expect($message)->not->toBe('');

    $this->get($this->url.'/login')->assertOk()->assertSee($message, false);
});

/* --------------------------------------------------------------- register -- */

it('shows the sign-up form the unaccepted-terms refusal', function (): void {
    /*
    | `accept_terms` is a checkbox, and an unticked checkbox is simply absent from the POST
    | — so this is the failure an ordinary person hits by scrolling past one line. Its
    | message has nowhere to go on the page: the control is a label wrapping two links,
    | with no region beside it and none possible without crowding the terms it links to.
    */
    $this->get($this->url.'/register')->assertOk();

    $this->post($this->url.'/register', [
        'name' => 'موبایل ایرانیان',
        'owner_name' => 'رضا محمدی',
        'owner_mobile' => '09121234567',
        'owner_email' => 'reza@example.test',
        'password' => 'Correct-Horse-9',
        'password_confirmation' => 'Correct-Horse-9',
        // accept_terms omitted, exactly as the browser omits an unticked box.
    ])
        ->assertRedirect($this->url.'/register')
        ->assertSessionHasErrors('accept_terms');

    $message = refusalFor('accept_terms');
    expect($message)->not->toBe('');

    $this->get($this->url.'/register')->assertOk()->assertSee($message, false);
});

/* -------------------------------------------------------- forgot password -- */

it('shows the forgot-password form its refusal', function (): void {
    /*
    | One field and one key, so `identifier` is the only refusal this form can receive —
    | and it has no message slot either. Worth pinning precisely because the screen looks
    | too simple to get wrong: an empty submit that redraws an empty form is
    | indistinguishable from a dead button.
    */
    $this->get($this->url.'/forgot-password')->assertOk();

    $this->post($this->url.'/forgot-password', ['identifier' => ''])
        ->assertRedirect($this->url.'/forgot-password')
        ->assertSessionHasErrors('identifier');

    $message = refusalFor('identifier');
    expect($message)->not->toBe('');

    $this->get($this->url.'/forgot-password')->assertOk()->assertSee($message, false);
});

/* --------------------------------------------------------- reset password -- */

it('shows the reset form the dead-link refusal', function (): void {
    /*
    | `token` has NO visible field on this page — a hidden input is not a message slot —
    | and the refusal it carries is the one that matters most on the screen: the link has
    | expired or was already used. Without the region, somebody whose reset link has gone
    | stale types a new password, presses the button, and is returned an identical form
    | with no indication that anything happened, forever.
    */
    $page = $this->url.'/reset-password?token=expired-or-forged&identifier=09121234567';

    $this->get($page)->assertOk();

    $response = $this->post($this->url.'/reset-password', [
        'token' => 'expired-or-forged',
        'identifier' => '09121234567',
        'password' => 'brand-new-secret-1',
        'password_confirmation' => 'brand-new-secret-1',
    ])->assertSessionHasErrors('token');

    /*
    | Back to the form with BOTH parameters intact — matched on the pairs, not on the whole
    | string. `back()` rebuilds the query through the URL generator, which emits the pairs
    | in alphabetical order rather than the order the emailed link carried them in, and an
    | exact comparison pins the ordering instead of the property worth having.
    |
    | The property worth having is that neither parameter is dropped on the way back: an
    | `identifier` lost here lands the person on a form that accepts a new password and
    | does nothing on submit, which is the bug the view's own comment describes.
    */
    $location = (string) $response->baseResponse->headers->get('Location');

    expect($location)
        ->toStartWith($this->url.'/reset-password?')
        ->toContain('token=expired-or-forged')
        ->toContain('identifier=09121234567');

    $message = refusalFor('token');
    expect($message)->not->toBe('');

    $this->get($page)->assertOk()->assertSee($message, false);
});

/* ---------------------------------------------------- two-factor challenge -- */

it('shows the challenge the recovery-code refusal', function (): void {
    /*
    | `recovery_code` is a real key on this endpoint — `required_without:code` — and once
    | `landing.js` has switched the challenge to the authenticator field, the recovery
    | input is `hidden`. So its refusal names a control that is not on the screen, which is
    | the worst version of this bug rather than a hypothetical one: the React page that
    | preceded this rendered `recovery_code`'s message as `code`'s, under the wrong field.
    */
    $user = app(TenantContext::class)->runFor(
        $this->tenant,
        fn () => User::factory()->create(['mobile' => '09121234567'])
    );

    app(TenantContext::class)->runFor($this->tenant, function () use ($user): void {
        $service = app(TwoFactorService::class);
        ['secret' => $secret] = $service->begin($user);
        $service->confirm($user, app(Google2FA::class)->getCurrentOtp($secret));
    });

    $this->withSession(securityCodeSession())->post($this->url.'/login', [
        'mobile' => '09121234567',
        'password' => 'password',
        ...securityCodeAnswer(),
    ])->assertRedirect(route('two-factor.challenge'));

    $this->get($this->url.'/two-factor/challenge')->assertOk();

    // Neither key filled: `required_without` refuses both, and one of the two names a
    // field the person cannot see.
    $this->post($this->url.'/two-factor/challenge', ['code' => '', 'recovery_code' => ''])
        ->assertSessionHasErrors('recovery_code');

    $message = refusalFor('recovery_code');
    expect($message)->not->toBe('');

    $this->get($this->url.'/two-factor/challenge')->assertOk()->assertSee($message, false);
});

/* ------------------------------------------------------------- invitation -- */

it('shows the invitation form its refusal', function (): void {
    /*
    | The page's one fieldless key is `token`, and it cannot be reached in a state that
    | also renders the page: a token good enough to get past `tenant.public:invitation` but
    | no longer pending fails the GET too, and the person is sent to /login. So the case
    | pinned here is the general one — this form places no message beside either password
    | field, so the region is the only thing that renders anything at all.
    */
    ['token' => $token, 'hash' => $hash] = Invitation::mintToken();

    app(TenantContext::class)->runFor($this->tenant, fn () => Invitation::query()->create([
        'name' => 'همکار تازه',
        'mobile' => '09122220000',
        'role' => 'Technician',
        'token_hash' => $hash,
        'expires_at' => now()->addDays(7),
    ]));

    $page = $this->url.'/invitations/accept/'.$token;

    $this->get($page)->assertOk();

    $this->post($page, [
        'password' => 'joining-secret-1',
        'password_confirmation' => 'a-different-secret-2',
    ])
        ->assertRedirect($page)
        ->assertSessionHasErrors('password');

    $message = refusalFor('password');
    expect($message)->not->toBe('');

    $this->get($page)->assertOk()->assertSee($message, false);
});

/* --------------------------------------------------------------- the seam -- */

it('renders the whole bag, not the keys somebody remembered', function (): void {
    /*
    | The property the six tests above each demonstrate once, stated directly and on a key
    | that exists nowhere in the application: `PasswordResetService` could start throwing
    | `ValidationException::withMessages(['sms' => …])` tomorrow and the form would show it
    | without anybody touching the view.
    |
    | This is what `auth/layout.blade.php` promises by iterating `$errors->all()` instead of
    | naming fields, and it is the line that would be quietly lost if somebody "tidied" the
    | layout into per-field messages.
    */
    $bag = (new ViewErrorBag)->put(
        'default',
        new MessageBag(['a_key_no_field_exists_for' => 'پیام آزمایشی'])
    );

    $this->withSession(['errors' => $bag])
        ->get($this->url.'/login')
        ->assertOk()
        ->assertSee('پیام آزمایشی', false);
});

/* ------------------------------------------------------------- the layout -- */

it('keeps one error region for every auth screen to share', function (): void {
    /*
    | Six pages, one region. This is the assertion that fails if a page is added on a frame
    | of its own — the drift `docs/design-system.md` calls out, and the reason the four
    | screens 16.3 moved were worth moving at all.
    */
    $layout = (string) file_get_contents(resource_path('views/auth/layout.blade.php'));

    expect($layout)->toContain('$errors->all()');

    foreach ([
        'login',
        'register',
        'forgot-password',
        'reset-password',
        'two-factor-challenge',
        'accept-invitation',
    ] as $view) {
        expect((string) file_get_contents(resource_path("views/auth/{$view}.blade.php")))
            ->toContain("@extends('auth.layout')");
    }
});
