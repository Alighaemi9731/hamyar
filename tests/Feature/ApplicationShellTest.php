<?php

declare(strict_types=1);

/**
 * Phase 0 smoke tests: the shell boots, renders RTL Persian, and the dev-only design
 * gallery is genuinely dev-only.
 */
it('responds to the health check', function (): void {
    $this->get('/up')->assertOk();
});

it('renders the central landing page', function (): void {
    $this->get('http://app.localhost/')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('welcome'));
});

it('renders the onboarding wizard on the central domain', function (): void {
    $this->get('http://app.localhost/register')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('auth/register'));
});

it('does not serve tenant login on the central domain', function (): void {
    // /login lives behind the `tenant` middleware; on the central host there is no
    // tenant to resolve, so it must 404 rather than render a shop-less login form.
    $this->get('http://app.localhost/login')->assertNotFound();
});

it('serves the document as RTL Persian', function (): void {
    $response = $this->get('http://app.localhost/');

    $response->assertOk();
    // Golden rule 9 starts at the document element: every logical utility below it
    // depends on this being right.
    expect($response->getContent())
        ->toContain('dir="rtl"')
        ->toContain('lang="fa"');
});

it('shares the props every page is typed against', function (): void {
    $this->get('http://app.localhost/')->assertInertia(
        fn ($page) => $page
            ->has('auth.user')
            ->has('tenant')
            ->has('features')
            ->has('flash')
            ->where('location', '/')
    );
});

it('exposes the design gallery in local and testing environments', function (): void {
    $this->get('http://app.localhost/design')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('design/index'));
});

it('stores timestamps in UTC while displaying Tehran time', function (): void {
    expect(config('app.timezone'))->toBe('UTC');
    expect(config('app.display_timezone'))->toBe('Asia/Tehran');
});
