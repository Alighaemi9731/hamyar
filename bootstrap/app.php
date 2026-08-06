<?php

declare(strict_types=1);

use App\Http\Middleware\EnsureUserBelongsToTenant;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\ResolveTenant;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;

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
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
