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
| Central routes — app.hamyar.ir
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

});

/*
|--------------------------------------------------------------------------
| The application — app.<apex>, one address for every shop
|--------------------------------------------------------------------------
|
| ADR 0017. Shops used to get a hostname each and the tenant was read from it; now
| everybody signs in here and the tenant comes from the session, established at login
| from the authenticated user's own record.
|
| Sign-up lives here too rather than beside the landing, so the whole auth flow is one
| origin. That is not tidiness: a cross-origin redirect out of a form POST is blocked by
| `form-action 'self'`, which broke sign-up twice (see OnboardingController::store()).
| Same-origin removes the shape of that bug rather than working around it again.
*/
Route::domain('app.'.config()->string('app.domain'))->group(function (): void {
    Route::middleware('guest')->group(function (): void {
        Route::get('/register', [OnboardingController::class, 'create'])->name('register');
        Route::post('/register', [OnboardingController::class, 'store'])
            ->middleware('throttle:6,1')
            ->name('register.store');

        Route::get('/login', [LoginController::class, 'create'])->name('login');
        Route::post('/login', [LoginController::class, 'store'])->name('login.store');
    });

    // Signed in, this is the dashboard; signed out, ResolveTenant sends it to /login.
    Route::redirect('/', '/dashboard')->name('app.home');

    /*
    |----------------------------------------------------------------------
    | Arrivals with no session
    |----------------------------------------------------------------------
    |
    | Both routes below are reached by somebody who is NOT signed in, and both used to
    | sit inside the `tenant` group. That group now reads the tenant from the session
    | (ADR 0017) and redirects to /login when there is none — so it turned a signature
    | check into a 302 and a settled payment into a lost one, both *before* the
    | request reached its controller. Each therefore establishes its own tenant, from a
    | server-side record rather than from the request, and each says how below.
    */

    /*
    | The gateway's return URL. Outside `auth` on purpose: a customer may come back in a
    | different browser context, and refusing the callback over an expired session would
    | strand a paid invoice. Verification authorises itself — an authority we never
    | issued is rejected, and one we did can only settle once. The shop is named by the
    | `payment_attempts` row that authority already identifies.
    */
    Route::get('/billing/callback', [BillingController::class, 'callback'])
        ->middleware('throttle:30,1')
        ->name('billing.callback');

    /*
    | Impersonation hand-off. The signature IS the authorisation — nobody is logged in
    | when this runs, which is exactly why it cannot be behind `tenant`: ResolveTenant
    | would 302 to /login before `signed` ever ran, and an unsigned link would look like
    | a redirect rather than the 403 it is. Minted on this host by ImpersonationService,
    | valid two minutes, and already audited into the shop's own activity log before the
    | link was issued.
    */
    Route::get('/impersonate/{user}', [ImpersonationController::class, 'start'])
        ->middleware(['signed', 'throttle:10,1'])
        ->whereNumber('user')
        ->name('impersonate.start');
});

/*
|--------------------------------------------------------------------------
| Tenant routes — the signed-in application
|--------------------------------------------------------------------------
|
| `tenant` reads the tenant from the SESSION and pins the context (ADR 0017); a request
| carrying none is sent to /login rather than falling back to anything.
|
| Everything here therefore assumes a session. Anything reached WITHOUT one — the
| gateway callback, the impersonation hand-off, password reset, an invitation — belongs
| in the app-host group above, where it establishes its own tenant.
|
| Module routes (Sales, Repairs, …) are registered by each module's service provider
| and carry their own middleware — see App\Support\Modules\ModuleServiceProvider.
|
*/

Route::middleware('tenant')->group(function (): void {
    /*
    | No `/` route here any more.
    |
    | It existed because a shop had its own address and somebody typing it landed on a
    | bare framework 404 — reported from production by the first person to register.
    | With one address (ADR 0017) that root belongs to the app host and is registered
    | above; this group is now only the authenticated application.
    */

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

    // The og:image as a page, captured by `bin/shots og` into resources/landing/og/og.png.
    // Same guard as /design: never registered in production.
    Route::get('/design/og', fn () => view('og.landing'))->name('design.og');

    // Gate 16.2: three landing directions as real markup, one route each. Disposable —
    // the chosen one is rebuilt properly in 16.3 and these come out with it.
    Route::get('/design/landing/{direction}', function (string $direction) {
        abort_unless(in_array($direction, ['a', 'b', 'c'], true), 404);

        return view("design.landing.{$direction}", ['plans' => App\Modules\Platform\Models\Plan::query()->where('is_public', true)->with('limits')->orderBy('position')->get()]);
    })->name('design.landing');
}
