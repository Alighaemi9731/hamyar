<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Messaging — web routes
|--------------------------------------------------------------------------
|
| Loaded with the `web` middleware group by the module service provider.
|
| Tenant screens belong inside a group carrying the tenant + auth middleware and
| `module:messaging` so the plan gates the route as well as the nav (golden rule 7):
|
|   Route::middleware(['tenant', 'auth', 'module:messaging'])
|       ->prefix('messaging')
|       ->name('messaging.')
|       ->group(function (): void {
|           // …
|       });
|
*/
