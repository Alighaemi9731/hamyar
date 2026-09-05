<?php

declare(strict_types=1);

use App\Http\Controllers\HealthController;
use App\Modules\Identity\Http\Controllers\LoginController;
use App\Modules\Platform\Http\Controllers\BillingController;
use App\Modules\Platform\Http\Controllers\ImpersonationController;
use App\Modules\Platform\Http\Controllers\LandingController;
use App\Modules\Platform\Http\Controllers\OnboardingController;
use App\Modules\Reporting\Http\Controllers\DashboardController;
use App\Modules\Reporting\Http\Controllers\SetupChecklistController;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
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

    /*
    | What a crawler is allowed to read, and where the three pages are.
    |
    | ## Routes, not files in `public/`
    |
    | `public/robots.txt` was a static file saying `Disallow:` — everything, including
    | `/p/{token}`, the price-list links a shop forwards to one customer. Those tokens are
    | unguessable, which is not the same as unindexable: a crawler that meets one in a
    | referrer or a pasted message will fetch it, and the page has no `noindex` of its
    | own reason to. The same is true of `/i/` and `/t/`.
    |
    | Made routes so both files come from `config('app.domain')` and `route()`. A sitemap
    | with a hostname baked in is a sitemap that is wrong on every environment but the one
    | it was written on, and `bin/check-apex-domain` refuses the literal anyway.
    |
    | ## Only three URLs, and that is the whole site
    |
    | Everything else this application serves is either behind sign-in, tenant-scoped, or
    | a token nobody should be handed. A sitemap listing three pages is not an oversight;
    | it is an accurate description of the public surface.
    |
    | ## They keep the `web` group, session and all
    |
    | Both are stateless and neither needs a session, and dropping `StartSession` from
    | them was tried: it 500s. `HandleInertiaRequests` is appended to this group and reads
    | the session unconditionally, so removing the store from under it breaks the request
    | rather than making it cheaper. The cost is one session per crawl, and unpicking a
    | group every other route depends on is not worth paying to avoid it.
    */
    Route::get('/robots.txt', function (): Response {
        $lines = [
            'User-agent: *',
            // The design gallery and the three landing comps: real markup, no `noindex`
            // of their own, and indexing them would put four versions of this page in
            // one result set.
            'Disallow: /design',
            // Forwarded price lists, shared invoices and repair tickets. Each is a
            // capability URL — holding it is the permission — so it must never become a
            // public address.
            'Disallow: /p/',
            'Disallow: /i/',
            'Disallow: /t/',
            '',
            'Sitemap: '.route('sitemap'),
        ];

        return response(implode("\n", $lines)."\n", 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    })->name('robots');

    Route::get('/sitemap.xml', function (): Response {
        $urls = [
            ['loc' => route('welcome'), 'priority' => '1.0', 'changefreq' => 'weekly'],
            ['loc' => route('legal.terms'), 'priority' => '0.3', 'changefreq' => 'yearly'],
            ['loc' => route('legal.privacy'), 'priority' => '0.3', 'changefreq' => 'yearly'],
        ];

        return response()
            ->view('sitemap', ['urls' => $urls])
            ->header('Content-Type', 'application/xml; charset=UTF-8');
    })->name('sitemap');
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
        // The refresh control beside the «کد امنیتی» field. Throttled: it writes a
        // session value, so an unthrottled loop is a cheap way to churn session storage.
        Route::get('/login/security-code', [LoginController::class, 'securityCode'])
            ->middleware('throttle:30,1')->name('login.security-code');
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
        // «بعداً» on the first morning's checklist; the dashboard owns the card, so it
        // owns the dismissal. See SetupChecklistController.
        Route::post('/dashboard/setup/dismiss', SetupChecklistController::class)->name('dashboard.setup.dismiss');

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

    /*
    | How `bin/shots` gets past the «کد امنیتی», and why it is not an auth bypass.
    |
    | The screenshot pipeline drives a real Chromium at the real sign-in page. Adding the
    | security code broke it silently: `capture.mjs` fills the mobile and the password,
    | posts, and validation fails on a field whose answer is a *picture*. Nothing reported
    | it — the script just timed out waiting to leave `/login`, and the weekly `shots.yml`
    | run had been failing the same way. The landing's promise that its captures are real
    | is enforced by `LandingShotsTest`, and it was being kept by a pipeline that could no
    | longer run at all.
    |
    | **This route hands back the caller's OWN session's code, and nothing else.** It is
    | not a way in: the pipeline still posts the real form, through the real controller,
    | with the real mobile and password, and every check in `LoginController::store()`
    | still runs. What it removes is the anti-automation measure, which is precisely and
    | only what a screenshot robot needs removed.
    |
    | That shape was chosen over the obvious alternative — a route that calls
    | `Auth::login()` directly — because `LoginController::store()` states an invariant in
    | its own comments: *nothing outside this flow may ever write `tenant_id` into the
    | session*. A second door would have had to copy the tenant pinning, the `isUsable()`
    | check and the 2FA branch, and a copy of an auth flow is a copy that drifts.
    |
    | The guard is registration: in production this route does not exist. It needs no
    | signature on top, because the only thing it discloses is the answer to a picture the
    | same caller is already being shown.
    */
    Route::domain('app.'.config()->string('app.domain'))->group(function (): void {
        Route::get('/shots/security-code', function (Request $request): Response {
            $code = $request->session()->get(App\Support\SecurityCode::SESSION_KEY);

            return response(is_string($code) ? $code : '', 200, ['Content-Type' => 'text/plain']);
        })->name('shots.security-code');
    });
}
