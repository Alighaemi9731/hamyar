<?php

declare(strict_types=1);

use App\Modules\Sales\Http\Controllers\InvoicePrintController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Sales — web routes
|--------------------------------------------------------------------------
|
| Every route lives on a TENANT hostname: `tenant` resolves the shop and pins the
| context, so the lookups below are confined to it without any controller filtering.
| `module:sales` gates the route on the plan as well as the nav (golden rule 7).
|
*/

Route::middleware(['tenant', 'auth', 'tenant.user', 'module:sales'])
    ->prefix('sales')
    ->name('sales.')
    ->group(function (): void {
        // `{paper}` is constrained here as well as validated in the controller: an
        // unconstrained segment would route every typo into a 500 rather than a 404.
        Route::get('/invoices/{invoice}/print/{paper}', [InvoicePrintController::class, 'show'])
            ->whereNumber('invoice')
            ->whereIn('paper', ['thermal80', 'a5', 'a4'])
            ->name('invoices.print');
    });
