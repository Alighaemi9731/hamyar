<?php

declare(strict_types=1);

use App\Modules\Repairs\Http\Controllers\PasscodeController;
use App\Modules\Repairs\Http\Controllers\TicketController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Repairs — web routes
|--------------------------------------------------------------------------
|
| Every route lives on a TENANT hostname: `tenant` resolves the shop and pins the
| context, so the lookups below are confined to it without controller filtering.
| `module:repairs` gates the route on the plan as well as the nav (golden rule 7).
|
*/

Route::middleware(['tenant', 'auth', 'tenant.user', 'module:repairs'])
    ->prefix('repairs')
    ->name('repairs.')
    ->group(function (): void {
        Route::get('/', [TicketController::class, 'index'])->name('tickets.index');

        // Fixed segments before `{ticket}`, or `intake` binds as an id and 404s.
        Route::get('/intake', [TicketController::class, 'create'])->name('tickets.create');
        Route::post('/intake', [TicketController::class, 'store'])->name('tickets.store');

        Route::get('/tickets/{ticket}', [TicketController::class, 'show'])
            ->whereNumber('ticket')
            ->name('tickets.show');

        Route::post('/tickets/{ticket}/transition', [TicketController::class, 'transition'])
            ->whereNumber('ticket')
            ->name('tickets.transition');

        /*
        | The device passcode.
        |
        | Its own endpoint on purpose: the value is never shipped with the page, so the
        | only way it reaches a browser is somebody deliberately asking for it — and
        | every ask is audited before it is answered. Throttled because a permission
        | that has been granted too widely should still not be a bulk-export tool.
        */
        Route::get('/tickets/{ticket}/passcode', [PasscodeController::class, 'show'])
            ->whereNumber('ticket')
            ->middleware('throttle:20,1')
            ->name('tickets.passcode');
    });
