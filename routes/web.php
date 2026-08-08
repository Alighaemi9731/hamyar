<?php

declare(strict_types=1);

use App\Modules\Identity\Http\Controllers\LoginController;
use App\Modules\Platform\Http\Controllers\BillingController;
use App\Modules\Platform\Http\Controllers\OnboardingController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

/*
|--------------------------------------------------------------------------
| Central routes — app.mobishop.ir
|--------------------------------------------------------------------------
|
| No tenant context. RLS denies every tenant table here by default, which is the
| correct posture: onboarding and billing have no business reading shop data.
|
| Registered FIRST and constrained by domain, so they win over the tenant group
| below for the same paths.
|
*/

Route::domain(config()->string('app.domain'))->group(function (): void {
    Route::get('/', fn () => Inertia::render('welcome'))->name('welcome');

    Route::middleware('guest')->group(function (): void {
        Route::get('/register', [OnboardingController::class, 'create'])->name('register');
        Route::post('/register', [OnboardingController::class, 'store'])
            ->middleware('throttle:6,1')
            ->name('register.store');

        // Live availability check for the wizard's subdomain step. Throttled: it is an
        // unauthenticated endpoint that reveals which shop names are taken.
        Route::post('/register/check-subdomain', [OnboardingController::class, 'checkSubdomain'])
            ->middleware('throttle:30,1')
            ->name('register.check-subdomain');
    });
});

/*
|--------------------------------------------------------------------------
| Tenant routes — <shop>.mobishop.ir
|--------------------------------------------------------------------------
|
| `tenant` resolves the hostname and pins the context; an unknown host 404s here
| rather than falling back to anything.
|
| Module routes (Sales, Repairs, …) are registered by each module's service provider
| and carry their own middleware — see App\Support\Modules\ModuleServiceProvider.
|
*/

Route::middleware('tenant')->group(function (): void {
    Route::middleware('guest')->group(function (): void {
        Route::get('/login', [LoginController::class, 'create'])->name('login');
        Route::post('/login', [LoginController::class, 'store'])->name('login.store');
    });

    Route::middleware(['auth', 'tenant.user'])->group(function (): void {
        Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');

        Route::get('/dashboard', fn () => Inertia::render('dashboard'))->name('dashboard');

        /*
        | Billing. Not behind `module:platform` — a shop whose subscription has lapsed
        | must still be able to reach the page that lets it pay. Gating the checkout
        | behind an active subscription is the classic lockout bug.
        */
        Route::get('/billing', [BillingController::class, 'index'])->name('billing.index');
        Route::post('/billing/subscribe/{plan}', [BillingController::class, 'subscribe'])
            ->middleware('throttle:10,1')
            ->name('billing.subscribe');
        Route::get('/billing/invoices/{invoice}', [BillingController::class, 'receipt'])
            ->whereNumber('invoice')
            ->name('billing.receipt');
    });

    /*
    | The gateway's return URL. Outside `auth` on purpose: a customer may come back in a
    | different browser context, and refusing the callback over an expired session would
    | strand a paid invoice. Verification authorises itself — an authority we never
    | issued is rejected, and one we did can only settle once.
    */
    Route::get('/billing/callback', [BillingController::class, 'callback'])
        ->middleware('throttle:30,1')
        ->name('billing.callback');
});

/*
|--------------------------------------------------------------------------
| Design gallery — local/testing only
|--------------------------------------------------------------------------
|
| A component gallery, not a product surface: it renders with no tenant so it stays
| reachable on the central domain. Never registered in production — shipping it would
| put an un-audited page on every tenant subdomain.
|
*/

if (app()->environment('local', 'testing')) {
    Route::get('/design', fn () => Inertia::render('design/index'))->name('design');
}
