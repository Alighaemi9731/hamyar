<?php

declare(strict_types=1);

use App\Modules\Sales\Http\Controllers\DailyCloseController;
use App\Modules\Sales\Http\Controllers\InvoiceController;
use App\Modules\Sales\Http\Controllers\InvoicePrintController;
use App\Modules\Sales\Http\Controllers\PosController;
use App\Modules\Sales\Http\Controllers\PublicInvoiceController;
use App\Modules\Sales\Http\Controllers\QuoteController;
use App\Modules\Sales\Http\Controllers\SalesReturnController;
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
        Route::get('/', [InvoiceController::class, 'index'])->name('invoices.index');

        /* ---------------------------------------------------------------- till -- */

        /*
        | Fixed segments before `{invoice}`, or `pos` binds as an invoice id and 404s.
        */

        Route::get('/pos', [PosController::class, 'create'])->name('pos.create');

        // Throttled generously rather than tightly: this fires once per scanned line and
        // a busy Friday at two tills is a legitimate few hundred an hour. The limit is
        // here to stop a stuck key walking the catalogue, not to pace a salesperson.
        Route::get('/pos/scan', [PosController::class, 'scan'])
            ->middleware('throttle:600,1')
            ->name('pos.scan');

        Route::post('/pos', [PosController::class, 'store'])->name('pos.store');

        // Reopening a parked basket. A draft only — `create` 404s on anything issued.
        Route::get('/pos/{invoice}', [PosController::class, 'create'])
            ->whereNumber('invoice')
            ->name('pos.resume');

        // Fixed segment before `{invoice}`, like the till.
        Route::get('/close', [DailyCloseController::class, 'show'])->name('close');

        /* ------------------------------------------------------------- quote -- */

        Route::get('/quotes', [QuoteController::class, 'index'])->name('quotes.index');

        Route::post('/quotes/{invoice}/convert', [QuoteController::class, 'convert'])
            ->whereNumber('invoice')
            ->name('quotes.convert');

        /* ------------------------------------------------------------ invoice -- */

        Route::get('/invoices/{invoice}', [InvoiceController::class, 'show'])
            ->whereNumber('invoice')
            ->name('invoices.show');

        Route::post('/invoices/{invoice}/finalise', [InvoiceController::class, 'finalise'])
            ->whereNumber('invoice')
            ->name('invoices.finalise');

        Route::post('/invoices/{invoice}/void', [InvoiceController::class, 'void'])
            ->whereNumber('invoice')
            ->name('invoices.void');

        Route::delete('/invoices/{invoice}', [InvoiceController::class, 'destroy'])
            ->whereNumber('invoice')
            ->name('invoices.destroy');

        /* ------------------------------------------------------------ return -- */

        Route::get('/invoices/{invoice}/returns/create', [SalesReturnController::class, 'create'])
            ->whereNumber('invoice')
            ->name('returns.create');

        Route::post('/invoices/{invoice}/returns', [SalesReturnController::class, 'store'])
            ->whereNumber('invoice')
            ->name('returns.store');

        // `{paper}` is constrained here as well as validated in the controller: an
        // unconstrained segment would route every typo into a 500 rather than a 404.
        Route::get('/invoices/{invoice}/print/{paper}', [InvoicePrintController::class, 'show'])
            ->whereNumber('invoice')
            ->whereIn('paper', ['thermal80', 'a5', 'a4'])
            ->name('invoices.print');
    });

/*
|--------------------------------------------------------------------------
| Sales — the public invoice view
|--------------------------------------------------------------------------
|
| Outside the `auth` group on purpose: this is what a customer's phone opens when they
| scan the QR on their receipt, and they have no account here.
|
| `tenant` still applies, so the shop is resolved from the hostname and RLS confines the
| lookup to it — a signed link minted for one shop cannot address another's invoice even
| if the ids happen to line up.
|
| `signed` is the whole access control. Without it the path is `/i/{id}`, which anybody
| can walk. Rate-limited as well, because a signature can be brute-forced in theory and
| a public endpoint on a shop's own domain should not be a free amplifier.
|
| It is deliberately NOT behind `module:sales`: a shop that lets its subscription lapse
| has still issued paper that is in customers' pockets, and a receipt that 403s months
| later makes the customer think the shop has vanished.
*/

Route::middleware(['tenant', 'signed', 'throttle:60,1'])
    ->get('/i/{invoice}', [PublicInvoiceController::class, 'show'])
    ->whereNumber('invoice')
    ->name('sales.invoices.public');
