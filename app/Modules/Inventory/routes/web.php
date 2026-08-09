<?php

declare(strict_types=1);

use App\Modules\Inventory\Http\Controllers\UnitController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Inventory — web routes
|--------------------------------------------------------------------------
|
| Every route lives on a TENANT hostname: `tenant` resolves the shop and pins the
| context, so the lookups below are confined to it without any controller filtering.
| `module:inventory` gates the route on the plan as well as the nav (golden rule 7).
|
*/

Route::middleware(['tenant', 'auth', 'tenant.user', 'module:inventory'])
    ->prefix('inventory')
    ->name('inventory.')
    ->group(function (): void {
        // Throttled for the same reason as the party lookup: it is a keystroke
        // endpoint, and it answers "which IMEIs does this shop hold".
        Route::get('/units/search', [UnitController::class, 'search'])
            ->middleware('throttle:120,1')
            ->name('units.search');
    });
