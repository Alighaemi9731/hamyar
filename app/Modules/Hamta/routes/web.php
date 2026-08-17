<?php

declare(strict_types=1);

use App\Modules\Hamta\Http\Controllers\HamtaController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| HAMTA — web routes
|--------------------------------------------------------------------------
|
| No `module:hamta` gate. HAMTA is not a sellable module — it is a legal obligation
| attached to every used handset an Iranian phone shop touches, and a plan that "does not
| include HAMTA" would be a plan that quietly stops warning a shop about untransferred
| devices. It rides on the Inventory permissions instead (see the controller).
|
*/

Route::middleware(['tenant', 'auth', 'tenant.user'])
    ->prefix('hamta')
    ->name('hamta.')
    ->group(function (): void {
        Route::get('/', [HamtaController::class, 'index'])->name('pending');

        // Before `/{unit}`, or "guide" is parsed as a unit id.
        Route::get('/guide', [HamtaController::class, 'guide'])->name('guide');

        Route::get('/{unit}', [HamtaController::class, 'show'])
            ->whereNumber('unit')->name('show');

        Route::post('/{unit}/checklist', [HamtaController::class, 'checklist'])
            ->whereNumber('unit')->name('checklist');

        Route::post('/{unit}/transfer', [HamtaController::class, 'record'])
            ->whereNumber('unit')->name('transfer');
    });
