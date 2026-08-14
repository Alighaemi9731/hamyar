<?php

declare(strict_types=1);

use App\Modules\Messaging\Http\Controllers\MessageController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Messaging — web routes
|--------------------------------------------------------------------------
*/

Route::middleware(['tenant', 'auth', 'tenant.user', 'module:messaging'])
    ->prefix('messaging')
    ->name('messaging.')
    ->group(function (): void {
        Route::get('/', [MessageController::class, 'index'])->name('index');
    });
