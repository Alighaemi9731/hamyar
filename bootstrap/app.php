<?php

declare(strict_types=1);

use App\Http\Middleware\EnsureUserBelongsToTenant;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\ResolveTenant;
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

        $middleware->alias([
            // Resolves the shop from the hostname and pins it for the request.
            // Applied per route group rather than globally: central routes
            // (onboarding, billing, the platform panel) legitimately have no tenant.
            'tenant' => ResolveTenant::class,
            // Runs after `auth`: rejects a session belonging to another shop.
            'tenant.user' => EnsureUserBelongsToTenant::class,
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
            Illuminate\Routing\Middleware\ThrottleRequests::class,
            Illuminate\Routing\Middleware\ThrottleRequestsWithRedis::class,
            Illuminate\Contracts\Session\Middleware\AuthenticatesSessions::class,
            SubstituteBindings::class,
            Illuminate\Auth\Middleware\Authorize::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
