<?php

declare(strict_types=1);

use App\Modules\Treasury\Http\Controllers\TreasuryController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Treasury — web routes
|--------------------------------------------------------------------------
|
| Gated on the plan as well as the nav (golden rule 7): a tenant without the treasury
| module gets a 404 from the middleware rather than a hidden menu item and a working URL.
*/

Route::middleware(['tenant', 'auth', 'tenant.user', 'module:treasury'])
    ->prefix('treasury')
    ->name('treasury.')
    ->group(function (): void {
        Route::get('/', [TreasuryController::class, 'index'])->name('index');

        Route::get('/close', [TreasuryController::class, 'close'])->name('close');

        Route::get('/accounts/{account}', [TreasuryController::class, 'statement'])
            ->whereNumber('account')
            ->name('accounts.statement');

        Route::post('/transfers', [TreasuryController::class, 'transfer'])->name('transfers.store');

        Route::post('/accounts/{account}/reconcile', [TreasuryController::class, 'reconcile'])
            ->whereNumber('account')
            ->name('accounts.reconcile');
    });
