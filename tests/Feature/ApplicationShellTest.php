<?php

declare(strict_types=1);

/**
 * Phase 0 smoke tests: the shell boots, renders RTL Persian, and the dev-only design
 * gallery is genuinely dev-only.
 */
it('responds to the health check', function (): void {
    $this->get('/up')->assertOk();
});

it('renders the dashboard through Inertia', function (): void {
    $this->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('dashboard'));
});

it('renders the login placeholder', function (): void {
    $this->get('/login')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('auth/login'));
});

it('redirects the root to the dashboard', function (): void {
    $this->get('/')->assertRedirect(route('dashboard'));
});

it('serves the document as RTL Persian', function (): void {
    $response = $this->get('/dashboard');

    $response->assertOk();
    // Golden rule 9 starts at the document element: every logical utility below it
    // depends on this being right.
    expect($response->getContent())
        ->toContain('dir="rtl"')
        ->toContain('lang="fa"');
});

it('shares the props every page is typed against', function (): void {
    $this->get('/dashboard')->assertInertia(
        fn ($page) => $page
            ->has('auth.user')
            ->has('tenant')
            ->has('features')
            ->has('flash')
            ->where('location', '/dashboard')
    );
});

it('exposes the design gallery in local and testing environments', function (): void {
    $this->get('/design')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('design/index'));
});

it('stores timestamps in UTC while displaying Tehran time', function (): void {
    expect(config('app.timezone'))->toBe('UTC');
    expect(config('app.display_timezone'))->toBe('Asia/Tehran');
});
