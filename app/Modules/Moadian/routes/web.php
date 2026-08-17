<?php

declare(strict_types=1);

use App\Modules\Moadian\Http\Controllers\MoadianController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Moadian — web routes
|--------------------------------------------------------------------------
|
| `module:moadian` gates the route on the plan, as golden rule 7 requires. The module is
| add-on-able and OFF for every plan at launch (ADR 0011), so in practice these routes are
| unreachable until a shop buys it — and even then `moadian.enabled` must be true before
| anything is submitted. Two switches, deliberately.
|
*/

Route::middleware(['tenant', 'auth', 'tenant.user', 'module:moadian'])
    ->prefix('moadian')
    ->name('moadian.')
    ->group(function (): void {
        Route::get('/', [MoadianController::class, 'index'])->name('index');

        Route::post('/{submission}/resend', [MoadianController::class, 'resend'])
            ->whereNumber('submission')->name('resend');
    });
