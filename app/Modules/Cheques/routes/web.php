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

        Route::post('/{cheque}/transition', [ChequeController::class, 'transition'])
            ->whereNumber('cheque')
            ->name('transition');
    });
