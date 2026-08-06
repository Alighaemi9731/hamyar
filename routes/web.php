<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

/*
|--------------------------------------------------------------------------
| Application routes
|--------------------------------------------------------------------------
|
| Module routes live in app/Modules/<Name>/routes/ and are registered by each
| module's service provider. Only app-level shell routes belong here.
|
| Phase 1 splits this into a central group (app.localhost — onboarding, billing,
| platform) and a tenant group (<shop>.app.localhost) resolved by subdomain.
|
*/

Route::get('/', fn () => redirect()->route('dashboard'))->name('home');

Route::get('/dashboard', fn () => Inertia::render('dashboard'))->name('dashboard');

// Placeholder until Identity lands the real auth flow in Phase 1.4.
Route::get('/login', fn () => Inertia::render('auth/login'))->name('login');

/*
|--------------------------------------------------------------------------
| Design gallery — local/testing only
|--------------------------------------------------------------------------
|
| Every base and domain component with its state matrix. The workflow rule
| (mobishop-ui skill): a component lands here BEFORE it is used in a feature page.
|
| Deliberately not registered in production: it is a development tool, and shipping
| it would expose an un-audited surface on every tenant subdomain.
|
*/
if (app()->environment('local', 'testing')) {
    Route::get('/design', fn () => Inertia::render('design/index'))->name('design');
}
