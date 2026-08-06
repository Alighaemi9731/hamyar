<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Repairs — web routes
|--------------------------------------------------------------------------
|
| Loaded with the `web` middleware group by the module service provider.
|
| Tenant screens belong inside a group carrying the tenant + auth middleware and
| `module:repairs` so the plan gates the route as well as the nav (golden rule 7):
|
|   Route::middleware(['tenant', 'auth', 'module:repairs'])
|       ->prefix('repairs')
|       ->name('repairs.')
|       ->group(function (): void {
|           // …
|       });
|
*/
