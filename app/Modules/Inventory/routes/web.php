<?php

declare(strict_types=1);

use App\Modules\Inventory\Http\Controllers\BranchController;
use App\Modules\Inventory\Http\Controllers\BranchSwitchController;
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

/*
|--------------------------------------------------------------------------
| Branches — NOT behind `module:inventory`
|--------------------------------------------------------------------------
|
| Branches are the shop's structure, not an inventory feature. The switcher renders in
| the app chrome on every screen in the product, so gating it on a module would leave a
| shop on a plan without Inventory unable to change which branch it is looking at —
| while Sales, Repairs and Treasury went on filtering by a branch it could not select.
|
| Gated on `settings.*` instead, which is what a shop's structure actually is: Owner and
| Manager hold it, a Warehousekeeper does not.
|
*/

Route::middleware(['tenant', 'auth', 'tenant.user'])->group(function (): void {
    Route::get('/branches', [BranchController::class, 'index'])->name('branches.index');
    Route::post('/branches', [BranchController::class, 'store'])->name('branches.store');
    Route::put('/branches/{branch}', [BranchController::class, 'update'])
        ->whereNumber('branch')->name('branches.update');
    Route::put('/branches/{branch}/users', [BranchController::class, 'assign'])
        ->whereNumber('branch')->name('branches.users');

    // The switcher itself. Throttled: it writes a session key and redirects, so a held
    // key would otherwise be a cheap way to make the app re-render on every keypress.
    Route::post('/branch/switch', BranchSwitchController::class)
        ->middleware('throttle:60,1')
        ->name('branch.switch');
});
