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
 */
beforeEach(function (): void {
    $this->tenant = Tenant::factory()->withDomain()->create();
    $this->url = tenantUrl($this->tenant);

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
    test()->post($url.'/login', [
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
        ->assertInertia(fn ($page) => $page->component('dashboard'));
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
    '/settings/users',
    '/settings/sessions',
    '/settings/two-factor',
    '/settings/activity',
]);

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
