<?php

declare(strict_types=1);

use App\Modules\Purchasing\Http\Controllers\PurchaseInvoiceController;
use App\Modules\Purchasing\Http\Controllers\PurchaseReturnController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Purchasing — web routes
|--------------------------------------------------------------------------
|
| Every route lives on a TENANT hostname: `tenant` resolves the shop and pins the
| context, so the lookups below are confined to it without any controller filtering.
| `module:purchasing` gates the route on the plan as well as the nav (golden rule 7).
|
*/

Route::middleware(['tenant', 'auth', 'tenant.user', 'module:purchasing'])
    ->prefix('purchasing')
    ->name('purchasing.')
    ->group(function (): void {
        Route::get('/', [PurchaseInvoiceController::class, 'index'])->name('invoices.index');
        Route::post('/invoices', [PurchaseInvoiceController::class, 'store'])->name('invoices.store');

        // Before `/invoices/{invoice}`, or `variants` binds as an invoice id and 404s.
        Route::get('/variants', [PurchaseInvoiceController::class, 'variants'])
            ->middleware('throttle:120,1')->name('variants');

        Route::get('/invoices/{invoice}', [PurchaseInvoiceController::class, 'edit'])
            ->whereNumber('invoice')->name('invoices.edit');
        Route::get('/invoices/{invoice}/grn', [PurchaseInvoiceController::class, 'grn'])
            ->whereNumber('invoice')->name('invoices.grn');

        Route::post('/invoices/{invoice}/imeis/parse', [PurchaseInvoiceController::class, 'parseImeis'])
            ->whereNumber('invoice')->middleware('throttle:60,1')->name('invoices.imeis.parse');
        Route::post('/invoices/{invoice}/imeis', [PurchaseInvoiceController::class, 'storeUnits'])
            ->whereNumber('invoice')->name('invoices.imeis.store');

        Route::post('/invoices/{invoice}/lines', [PurchaseInvoiceController::class, 'storeLine'])
            ->whereNumber('invoice')->name('invoices.lines.store');
        Route::post('/invoices/{invoice}/landed-costs', [PurchaseInvoiceController::class, 'storeLandedCost'])
            ->whereNumber('invoice')->name('invoices.landed.store');
        Route::delete('/invoices/{invoice}/lines/{kind}/{line}', [PurchaseInvoiceController::class, 'destroyLine'])
            ->whereNumber('invoice')->whereNumber('line')
            ->whereIn('kind', ['standard', 'unit', 'landed'])
            ->name('invoices.lines.destroy');

        Route::post('/invoices/{invoice}/receive', [PurchaseInvoiceController::class, 'receive'])
            ->whereNumber('invoice')->name('invoices.receive');

        Route::post('/invoices/{invoice}/returns', [PurchaseReturnController::class, 'store'])
            ->whereNumber('invoice')->name('returns.store');
    });
