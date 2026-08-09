<?php

declare(strict_types=1);

use App\Modules\Inventory\Http\Controllers\StockController;
use App\Modules\Inventory\Http\Controllers\StockCountController;
use App\Modules\Inventory\Http\Controllers\TransferController;
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
        Route::get('/', [StockController::class, 'index'])->name('stock.index');
        Route::get('/low-stock', [StockController::class, 'lowStock'])->name('stock.low');

        // Throttled for the same reason as the party lookup: it is a keystroke
        // endpoint, and it answers "which IMEIs does this shop hold".
        //
        // Registered BEFORE `units/{unit}`, or `search` binds as a unit id and 404s.
        Route::get('/units/search', [UnitController::class, 'search'])
            ->middleware('throttle:120,1')
            ->name('units.search');

        Route::get('/units', [UnitController::class, 'index'])->name('units.index');
        Route::get('/units/{unit}', [UnitController::class, 'show'])
            ->whereNumber('unit')->name('units.show');

        /* --------------------------------------------------------- transfers -- */

        Route::get('/transfers', [TransferController::class, 'index'])->name('transfers.index');
        Route::post('/transfers', [TransferController::class, 'store'])->name('transfers.store');
        Route::get('/transfers/{transfer}', [TransferController::class, 'show'])
            ->whereNumber('transfer')->name('transfers.show');
        Route::post('/transfers/{transfer}/lines', [TransferController::class, 'storeLine'])
            ->whereNumber('transfer')->name('transfers.lines.store');
        Route::delete('/transfers/{transfer}/lines/{item}', [TransferController::class, 'destroyLine'])
            ->whereNumber('transfer')->whereNumber('item')->name('transfers.lines.destroy');
        Route::post('/transfers/{transfer}/dispatch', [TransferController::class, 'dispatch'])
            ->whereNumber('transfer')->name('transfers.dispatch');
        Route::post('/transfers/{transfer}/receive', [TransferController::class, 'receive'])
            ->whereNumber('transfer')->name('transfers.receive');

        /* ------------------------------------------------------------ counts -- */

        Route::get('/counts', [StockCountController::class, 'index'])->name('counts.index');
        Route::post('/counts', [StockCountController::class, 'store'])->name('counts.store');
        Route::get('/counts/{count}', [StockCountController::class, 'show'])
            ->whereNumber('count')->name('counts.show');
        Route::post('/counts/{count}/lines', [StockCountController::class, 'storeLine'])
            ->whereNumber('count')->name('counts.lines.store');
        Route::post('/counts/{count}/fill', [StockCountController::class, 'fill'])
            ->whereNumber('count')->name('counts.fill');
        Route::put('/counts/{count}/counted', [StockCountController::class, 'count'])
            ->whereNumber('count')->name('counts.counted');
        Route::post('/counts/{count}/apply', [StockCountController::class, 'apply'])
            ->whereNumber('count')->name('counts.apply');
    });
