<?php

declare(strict_types=1);

use App\Modules\Identity\Models\User;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Platform\Services\TenantProvisioner;
use App\Support\Tenancy\TenantContext;

/**
 * Navigation through a REAL login, with no `actingAs()`.
 *
 * This file exists because of a bug the rest of the suite structurally could not
 * catch. `actingAs()` injects a user straight into the guard, so it never exercises
 * the session → user-provider lookup. That lookup goes through the tenant-scoped
 * model, and `Authenticate` was ordered BEFORE `ResolveTenant`: with no context the
 * global scope applied `1 = 0`, the user was never found, `/dashboard` bounced to
 * `/login`, and `/login` — which has no Authenticate of its own — resolved the user
 * fine and bounced straight back. An infinite redirect loop, invisible to every
 * actingAs test, and only visible in a browser.
 *
 * Anything asserting middleware ORDER belongs here rather than in a test that
 * short-circuits authentication.
 *
 * ## Since ADR 0017 there are two shortcuts to avoid here, not one
 *
 * Shops no longer have their own hostname: everybody signs in at `app.<apex>` — hence
 * `appUrl()`, which takes no tenant — and the tenant is written into the session at
 * login from the authenticated user's own record. `Tests\TestCase::actingAs()` now
 * seeds that session key itself, because otherwise a thousand authenticated tests
 * would arrive with a user and no tenant and be bounced to `/login`.
 *
 * That is the right call there and it widens the gap this file exists to cover: an
 * `actingAs` test now skips the login flow *and* is handed the state that flow would
 * have established. Both halves of what a browser does are stubbed. Here neither is —
 * the form is posted, the redirect is followed, and the session has to carry the
 * tenant on its own.
 *
 * The ordering lesson above survives the move unchanged. `ResolveTenant` reads a
 * different source than it used to, but it must still run before `Authenticate` for
 * precisely the reason described: the user provider queries a tenant-scoped model, so
 * a request that authenticates first has no context to authenticate in.
 */
beforeEach(function (): void {
    $this->tenant = Tenant::factory()->withDomain()->create();
    $this->url = appUrl();

    app(TenantProvisioner::class)->seedRoles($this->tenant);

    app(TenantContext::class)->runFor($this->tenant, function (): void {
        $owner = User::factory()->create(['mobile' => '09121234567']);
        $owner->assignRole('Owner');
    });
});

/**
 * Log in the way a browser does: post the form, follow the redirect.
 */
function logInThroughTheForm(string $url): void
{
    test()->withSession(securityCodeSession())->post($url.'/login', [
        ...securityCodeAnswer(),
        'mobile' => '09121234567',
        'password' => 'password',
    ])->assertRedirect(route('dashboard'));
}

it('reaches the dashboard after a real form login', function (): void {
    logInThroughTheForm($this->url);

    // The assertion that would have caught the loop: a plain GET, resolving the user
    // from the session through the tenant-scoped provider.
    $this->get($this->url.'/dashboard')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Reporting::Dashboard/Index'));
});

it('does not bounce between login and dashboard', function (): void {
    logInThroughTheForm($this->url);

    // `/login` must now redirect AWAY (guest sees an authenticated user) and
    // `/dashboard` must render. If both redirect, that is the loop.
    $this->get($this->url.'/login')->assertRedirect();
    $this->get($this->url.'/dashboard')->assertOk();
});

it('reaches every authenticated settings screen after a real login', function (string $path): void {
    logInThroughTheForm($this->url);

    $this->get($this->url.$path)->assertOk();
})->with([
    '/settings',
    '/settings/users',
    '/settings/sessions',
    '/settings/two-factor',
    '/settings/activity',
]);

/*
| Every destination the sidebar offers, read from the sidebar itself.
|
| Two nav items shipped pointing at routes that do not exist. «تنظیمات» aimed at
| `/settings`, whose module routes file held nothing but a comment block, and «اقساط» at
| `/installments`, where the module registers `/installments/collections` and
| `/installments/plans/{plan}` and nothing at the root. Both 404'd for every user on every
| page, for as long as they have been in the list.
|
| Nothing could have caught that from PHP, because the list lives in TypeScript — so this
| reads the TypeScript. Parsing a source file in a test is ugly and it is still the right
| trade: the alternative is a second copy of the nav in PHP, which is a thing to keep in
| sync rather than a thing that checks. The regex only has to survive an array of string
| literals, and if it ever matches nothing the count assertion fails loudly rather than
| passing on an empty set.
|
| A redirect counts as answering. Some destinations bounce a shop that has no branches
| yet, and this test is about "the link goes somewhere", not about what is on the page.
*/
it('offers no sidebar link that 404s', function (): void {
    $source = file_get_contents(base_path('resources/js/lib/navigation.ts'));

    expect($source)->toBeString();

    preg_match_all("/href:\s*'([^']+)'/", (string) $source, $matches);

    $hrefs = array_values(array_unique($matches[1]));

    // The list is 18 items today. A floor rather than an equality so adding a nav item
    // does not fail this test — but a regex that silently stops matching does.
    expect($hrefs)->toHaveCount(count($hrefs))
        ->and(count($hrefs))->toBeGreaterThanOrEqual(15, 'The nav parse matched almost nothing; the regex has drifted from navigation.ts.');

    logInThroughTheForm($this->url);

    foreach ($hrefs as $href) {
        $status = $this->get($this->url.$href)->status();

        expect($status)->toBeIn(
            [200, 302],
            "The sidebar links to {$href}, which answered {$status}."
        );
    }
});

it('resolves a route-model-bound tenant route after a real login', function (): void {
    // The other half of the ordering bug: SubstituteBindings queries the scoped model
    // too, so a `{user}` route 404s when binding runs before the context is pinned.
    logInThroughTheForm($this->url);

    /** @var User $other */
    $other = app(TenantContext::class)->runFor(
        $this->tenant,
        fn (): User => User::factory()->create()
    );

    $this->put($this->url.'/settings/users/'.$other->id.'/roles', ['roles' => ['Cashier']])
        ->assertRedirect()
        ->assertSessionHasNoErrors();
});

it('ships the database session driver, which the sessions screen depends on', function (): void {
    // Asserted against .env.example — the file every deployment copies — rather than
    // the runtime value, which phpunit.xml deliberately overrides to `array` for speed.
    // Flipping this back to redis would leave the sessions screen permanently empty
    // with no error anywhere: the redis driver cannot enumerate a user's sessions.
    $env = (string) file_get_contents(base_path('.env.example'));

    expect($env)->toContain('SESSION_DRIVER=database');

    logInThroughTheForm($this->url);

    $this->get($this->url.'/settings/sessions')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('settings/sessions'));
});
