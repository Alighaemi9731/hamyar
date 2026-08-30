<?php

declare(strict_types=1);

use App\Modules\Cheques\Http\Controllers\ChequeController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Cheques — web routes
|--------------------------------------------------------------------------
*/

Route::middleware(['tenant', 'auth', 'tenant.user', 'module:cheques'])
    ->prefix('cheques')
    ->name('cheques.')
    ->group(function (): void {
        Route::get('/', [ChequeController::class, 'index'])->name('index');

        // The entry point. Metered on `cheques.cheques` inside `RegisterCheque`, not by the
        // `quota:` middleware — the credit has to be spent in the same transaction that
        // writes the row and posts its opening ledger entry (golden rule 7).
        Route::post('/', [ChequeController::class, 'store'])->name('store');

        Route::post('/{cheque}/transition', [ChequeController::class, 'transition'])
            ->whereNumber('cheque')
            ->name('transition');
    });
