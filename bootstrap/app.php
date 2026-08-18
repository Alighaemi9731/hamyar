<?php

declare(strict_types=1);

use App\Http\Middleware\EnsureModuleEnabled;
use App\Http\Middleware\EnsureUserBelongsToTenant;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\ResolveTenant;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Routing\Middleware\SubstituteBindings;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Module routes are registered by each module's service provider, which
        // applies the `web` / `api` groups itself — see App\Support\Modules.
        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        /*
        | Global, not in the `web` group, and the difference is a real hole rather
        | than a preference.
        |
        | Group middleware runs only once a route has matched. A request for a path
        | with no route never enters `web` at all, so a 404 — an HTML page, served to
        | a browser, rendered from a Blade template — came back with no policy, no
        | nosniff and no frame-ancestors. Same for anything the exception handler
        | renders before routing.
        |
        | Found by a test that asked for a page that does not exist, which is the only
        | reason it was found: every screen in the product looked correctly protected.
        */
        $middleware->append(SecurityHeaders::class);

        $middleware->alias([
            // Resolves the shop from the hostname and pins it for the request.
            // Applied per route group rather than globally: central routes
            // (onboarding, billing, the platform panel) legitimately have no tenant.
            'tenant' => ResolveTenant::class,
            // Runs after `auth`: rejects a session belonging to another shop.
            'tenant.user' => EnsureUserBelongsToTenant::class,
            // Plan gating: `->middleware('module:repairs')`. Golden rule 7 — the nav
            // hides disabled modules, but THIS is what actually enforces it.
            'module' => EnsureModuleEnabled::class,
        ]);

        // Explicit middleware ordering. Two of these placements are load-bearing and
        // both produced real, hard-to-spot bugs when left to the default order:
        //
        // 1. ResolveTenant BEFORE Authenticate. The `web` auth guard resolves the
        //    session's user id through the tenant-scoped provider. With no context the
        //    global scope applies `1 = 0`, the user is never found, and `/dashboard`
        //    redirects to `/login` — which, having no Authenticate of its own, DOES
        //    resolve the user and redirects straight back. An infinite redirect loop
        //    that no `actingAs()` test can catch, because actingAs injects the user
        //    into the guard and skips the provider entirely.
        //
        // 2. ResolveTenant BEFORE SubstituteBindings. Route-model binding queries the
        //    model too, so without a context every `{user}`-style tenant route 404s.
        //
        // EnsureUserBelongsToTenant sits immediately AFTER Authenticate: it has to see
        // a resolved user to compare tenants, and is useless anywhere earlier.
        $middleware->priority([
            Illuminate\Foundation\Http\Middleware\HandlePrecognitiveRequests::class,
            Illuminate\Cookie\Middleware\EncryptCookies::class,
            Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
            Illuminate\Session\Middleware\StartSession::class,
            Illuminate\View\Middleware\ShareErrorsFromSession::class,
            Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
            ResolveTenant::class,
            Illuminate\Auth\Middleware\Authenticate::class,
            EnsureUserBelongsToTenant::class,
            // After the tenant is known, before anything that reads plan features.
            EnsureModuleEnabled::class,
            Illuminate\Routing\Middleware\ThrottleRequests::class,
            Illuminate\Routing\Middleware\ThrottleRequestsWithRedis::class,
            Illuminate\Contracts\Session\Middleware\AuthenticatesSessions::class,
            SubstituteBindings::class,
            Illuminate\Auth\Middleware\Authorize::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        /*
        | Never flash a secret back into the session.
        |
        | On a validation failure Laravel redirects with `->withInput(Arr::except(
        | $request->input(), $dontFlash))`, and the framework default covers only the
        | password fields. Everything else — including a customer's device unlock code —
        | is written to the session store verbatim.
        |
        | That matters here more than in most apps. `SESSION_DRIVER` is `database` and
        | `SESSION_ENCRYPT` is false, so the flashed value lands in `sessions.payload` as
        | plaintext and survives into the next request as `_flash.old`. A device passcode
        | is encrypted at rest in `repair_tickets` precisely so a database dump, a read
        | replica or a backup on somebody's laptop shows nothing — and a single failed
        | intake (a photo one megabyte too large is enough) would put it in the clear in
        | the very same database, one table over.
        |
        | Found by an adversarial review of Phase 6. The passcode tests covered the
        | model, the API, the logs and the reveal endpoint; none of them posted a form
        | that failed validation.
        |
        | The list is deliberately broader than "the one field that bit us": anything
        | named like a secret gets the same treatment, because the next one will be added
        | by somebody who has never read this comment.
        */
        $exceptions->dontFlash([
            'current_password',
            'password',
            'password_confirmation',
            // Repairs — the customer's unlock code or pattern.
            'device_passcode',
            // Identity — TOTP enrolment and recovery.
            'two_factor_secret',
            'two_factor_recovery_codes',
            'code',
            'otp',
            'token',
            // Payments — a card or account number typed into a reference field.
            'card_number',
            'iban',
            'account_number',
        ]);
    })->create();
