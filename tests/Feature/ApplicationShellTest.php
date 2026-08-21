<?php

declare(strict_types=1);

use App\Modules\Identity\Models\User;
use App\Modules\Platform\Models\Tenant;

/**
 * Phase 0 smoke tests: the shell boots, renders RTL Persian, and the dev-only design
 * gallery is genuinely dev-only.
 */
it('responds to the health check', function (): void {
    $this->get('/up')->assertOk();
});

it('renders the central landing page', function (): void {
    /*
    | Blade, not Inertia, and asserted as Blade (ADR 0016). This page is read by people
    | who have never signed in and by crawlers that do not run JavaScript, so its content
    | has to be in the HTML the server sends. `assertInertia()` here could only ever fail
    | — or, worse, be "fixed" by turning the landing back into a React page.
    */
    $this->get(centralUrl('/'))
        ->assertOk()
        ->assertViewIs('landing');
});

it('renders the onboarding wizard on the app domain', function (): void {
    /*
    | Two moves, both deliberate. ADR 0017 put sign-up on `app.<apex>` beside the login
    | form it redirects to, so the whole auth flow is one origin — a cross-origin redirect
    | out of a form POST is blocked by `form-action 'self'`, which broke sign-up twice.
    | ADR 0016 made the page Blade so it matches the public design language.
    */
    $this->get(appUrl('/register'))
        ->assertOk()
        ->assertViewIs('auth.register');
});

it('does not serve tenant login on the central domain', function (): void {
    // Since ADR 0017 `/login` is registered only inside the `app.<apex>` group. The apex
    // serves the landing, the two legal pages and the platform panel, so asking it for a
    // login form must 404 rather than render one somewhere nobody signs in.
    $this->get(centralUrl('/login'))->assertNotFound();
});

it('serves the document as RTL Persian', function (): void {
    $response = $this->get(centralUrl('/'));

    $response->assertOk();
    // Golden rule 9 starts at the document element: every logical utility below it
    // depends on this being right.
    expect($response->getContent())
        ->toContain('dir="rtl"')
        ->toContain('lang="fa"');
});

it('shares the props every page is typed against', function (): void {
    /*
    | Asserted on the dashboard, not on the landing page.
    |
    | The shared-prop contract belongs to an Inertia page, and since ADR 0016 the landing
    | is Blade — it carries no props at all. Pointed at it, this test could only fail; the
    | contract itself is intact, it just has to be asserted where pages actually consume
    | it. The dashboard is the first screen a shop lands on and the shell every module
    | page inherits.
    */
    $tenant = Tenant::factory()->withDomain()->create();

    $user = inTenantContext($tenant, fn (): User => User::factory()->create());

    $this->actingAs($user)
        ->get(appUrl('/dashboard'))
        ->assertOk()
        ->assertInertia(
            fn ($page) => $page
                ->has('auth.user')
                ->has('tenant')
                ->has('features')
                ->has('flash')
                ->where('location', '/dashboard')
        );
});

it('exposes the design gallery in local and testing environments', function (): void {
    $this->get(centralUrl('/design'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('design/index'));
});

it('stores timestamps in UTC while displaying Tehran time', function (): void {
    expect(config('app.timezone'))->toBe('UTC');
    expect(config('app.display_timezone'))->toBe('Asia/Tehran');
});
