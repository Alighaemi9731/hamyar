<?php

declare(strict_types=1);

use App\Http\Controllers\HealthController;
use App\Modules\Identity\Http\Controllers\LoginController;
use App\Modules\Platform\Http\Controllers\BillingController;
use App\Modules\Platform\Http\Controllers\ImpersonationController;
use App\Modules\Platform\Http\Controllers\LandingController;
use App\Modules\Platform\Http\Controllers\OnboardingController;
use App\Modules\Reporting\Http\Controllers\DashboardController;
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

/*
|--------------------------------------------------------------------------
| Health — every host, no tenant, no session
|--------------------------------------------------------------------------
|
| Registered before both groups below and deliberately NOT domain-constrained, because
| its three callers arrive at three different hostnames: the uptime probe hits the
| apex, `bin/deploy` hits the new container's own address before nginx knows about it,
| and a human on the box hits localhost.
|
| Outside the `tenant` middleware for the same reason. That group 404s a hostname
| belonging to no shop — correct for a shop screen, and for a health check it would
| mean the monitor reports the platform down whenever it is pointed somewhere the
| `domains` table has not heard of.
|
| Laravel's own `/up` stays where it is (bootstrap/app.php) and answers a different
| question: it boots the framework and touches nothing, which is the cheap liveness
| signal. This one costs a round trip to Postgres and Redis and reports what it found.
|
*/
Route::get('/health', HealthController::class)->name('health');

Route::domain(config()->string('app.domain'))->group(function (): void {
    /*
    | The public landing, and the two legal pages it links to.
    |
    | Blade, not Inertia — deliberately, and it is the one place in this application
    | where that is true. These three pages are read by people who have never signed in
    | and by crawlers that do not run JavaScript, so their content has to be in the HTML
    | the server sends rather than assembled by React afterwards. They also carry their
    | own dark theme and their own Vite entry (ADR 0016); nothing here touches the
    | in-app design system.
    */
    Route::get('/', LandingController::class)->name('welcome');
    Route::view('/terms', 'legal.terms')->name('legal.terms');
    Route::view('/privacy', 'legal.privacy')->name('legal.privacy');

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
    /*
    | The shop's own address, and the reason it needs a route at all.
    |
    | Registration redirects to `/login` on the new subdomain, so the FIRST visit always
    | worked. Every visit after that starts where a shopkeeper naturally starts — typing
    | the shop's address, opening a bookmark, or handing it to a new employee — and `/`
    | had no route on a tenant host. That is not a tidy 404: with no `resources/views/
    | errors/`, it is the framework's bare `<html lang="en">Not Found`, in English and
    | LTR, on a Persian product, to somebody who has just signed up and is trying to get
    | in. Reported from production by the first person to register a shop.
    |
    | Deliberately outside both `guest` and `auth`. Signed in, this lands on the
    | dashboard; signed out, `/dashboard`'s own guard forwards to `/login`. One rule
    | covers both, and neither needs to know about the other.
    |
    | The apex `/` is registered above inside `Route::domain(...)`, so it still wins for
    | the central site; an unknown hostname still reaches `tenant` middleware here and
    | still 404s, which is the behaviour the isolation tests pin.
    */
    Route::redirect('/', '/dashboard')->name('tenant.home');

    Route::middleware('guest')->group(function (): void {
        Route::get('/login', [LoginController::class, 'create'])->name('login');
        Route::post('/login', [LoginController::class, 'store'])->name('login.store');
    });

    Route::middleware(['auth', 'tenant.user'])->group(function (): void {
        Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');

        /*
        | Deliberately not behind `module:reporting`. Every shop on every plan has a
        | front page; what varies is how many cards are on it. See DashboardController.
        */
        Route::get('/dashboard', DashboardController::class)->name('dashboard');

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

    /*
    | Impersonation hand-off. The signature IS the authorisation — nobody is logged in
    | when this runs. Minted on this hostname by ImpersonationService, valid two minutes,
    | and already audited into the shop's own activity log before the link was issued.
    */
    Route::get('/impersonate/{user}', [ImpersonationController::class, 'start'])
        ->middleware(['signed', 'throttle:10,1'])
        ->whereNumber('user')
        ->name('impersonate.start');
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
