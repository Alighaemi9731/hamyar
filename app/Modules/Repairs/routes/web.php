<?php

declare(strict_types=1);

use App\Modules\Repairs\Http\Controllers\PasscodeController;
use App\Modules\Repairs\Http\Controllers\PublicApprovalController;
use App\Modules\Repairs\Http\Controllers\PublicTrackingController;
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

        // Fixed segments before `{ticket}`, or these bind as an id and 404.
        Route::get('/board', [TicketController::class, 'board'])->name('tickets.board');
        Route::get('/workload', [TicketController::class, 'workload'])->name('tickets.workload');

        Route::get('/intake', [TicketController::class, 'create'])->name('tickets.create');
        Route::post('/intake', [TicketController::class, 'store'])->name('tickets.store');

        Route::get('/tickets/{ticket}', [TicketController::class, 'show'])
            ->whereNumber('ticket')
            ->name('tickets.show');

        Route::get('/tickets/{ticket}/receipt', [TicketController::class, 'receipt'])
            ->whereNumber('ticket')
            ->name('tickets.receipt');

        Route::get('/tickets/{ticket}/deliver', [TicketController::class, 'deliveryForm'])
            ->whereNumber('ticket')
            ->name('tickets.deliver');

        Route::post('/tickets/{ticket}/deliver', [TicketController::class, 'deliver'])
            ->whereNumber('ticket')
            ->name('tickets.deliver.store');

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

/*
|--------------------------------------------------------------------------
| Repairs — the public tracking page
|--------------------------------------------------------------------------
|
| Outside `auth` on purpose: this is a customer's phone opening the QR from a repair
| receipt, and they have no account here.
|
| The 48-character token IS the access control. There is no id in the path, so unlike
| the public invoice page there is no pair of responses to compare and nothing to
| enumerate — every wrong token is an identical 404.
|
| Rate-limited hard. A repair status reveals that a named person's device is out of
| their hands, so a script guessing tokens should exhaust its budget long before it
| exhausts anything else. 30/minute is generous for a human refreshing a page and
| useless for a search.
|
| Deliberately NOT behind `module:repairs`: a shop whose subscription lapses has still
| given out paper that is in customers' pockets, and a tracking page that 403s makes the
| customer think the shop has vanished with their phone.
*/

Route::middleware(['tenant', 'throttle:30,1'])
    ->get('/t/{token}', [PublicTrackingController::class, 'show'])
    ->where('token', '[A-Za-z0-9]{48}')
    ->name('repairs.tracking');

/*
|--------------------------------------------------------------------------
| Repairs — the public approval page
|--------------------------------------------------------------------------
|
| The same shape as tracking, and worth more: this token authorises spending somebody
| else's money. So it is minted per request rather than printed on the receipt, texted
| to the number on the ticket, and cleared the moment it is used.
|
| Throttled harder than tracking (10/minute). Tracking is a page a customer refreshes;
| this is a page they visit once.
*/

Route::middleware(['tenant', 'throttle:10,1'])
    ->prefix('a')
    ->name('repairs.approval.')
    ->where(['token' => '[A-Za-z0-9]{48}'])
    ->group(function (): void {
        Route::get('/{token}', [PublicApprovalController::class, 'show'])->name('show');
        Route::post('/{token}/approve', [PublicApprovalController::class, 'approve'])->name('approve');
        Route::post('/{token}/decline', [PublicApprovalController::class, 'decline'])->name('decline');
    });
