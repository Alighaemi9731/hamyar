<?php

declare(strict_types=1);

use App\Modules\Installments\Http\Controllers\InstallmentPlanController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Installments — web routes
|--------------------------------------------------------------------------
|
| Every route lives on a TENANT hostname: `tenant` resolves the shop and pins the
| context, so the lookups below are confined to it without any controller filtering.
| `module:installments` gates the route on the plan as well as the nav (golden rule 7).
|
| The wizard routes hang off an INVOICE rather than off a plan, because that is where a
| shop starts: they have just sold a phone and the customer wants to pay over six months.
| The plan does not exist until the form is submitted.
|
*/

Route::middleware(['tenant', 'auth', 'tenant.user', 'module:installments'])
    ->prefix('installments')
    ->name('installments.')
    ->group(function (): void {
        Route::get('/invoices/{invoice}/plan/create', [InstallmentPlanController::class, 'create'])
            ->whereNumber('invoice')
            ->name('plans.create');

        // Fires as the four fields are edited. Throttled loosely: a wizard is filled in
        // once per sale, not a hundred times a day like the POS scan box.
        Route::get('/invoices/{invoice}/plan/preview', [InstallmentPlanController::class, 'preview'])
            ->whereNumber('invoice')
            ->middleware('throttle:120,1')
            ->name('plans.preview');

        Route::post('/invoices/{invoice}/plan', [InstallmentPlanController::class, 'store'])
            ->whereNumber('invoice')
            ->name('plans.store');

        Route::get('/plans/{plan}', [InstallmentPlanController::class, 'show'])
            ->whereNumber('plan')
            ->name('plans.show');

        Route::get('/plans/{plan}/print', [InstallmentPlanController::class, 'print'])
            ->whereNumber('plan')
            ->name('plans.print');
    });
