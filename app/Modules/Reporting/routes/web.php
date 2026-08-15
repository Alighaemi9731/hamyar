<?php

declare(strict_types=1);

use App\Modules\Reporting\Http\Controllers\FinancialReportController;
use App\Modules\Reporting\Http\Controllers\InventoryReportController;
use App\Modules\Reporting\Http\Controllers\OperationsReportController;
use App\Modules\Reporting\Http\Controllers\ProfitReportController;
use App\Modules\Reporting\Http\Controllers\RepairReportController;
use App\Modules\Reporting\Http\Controllers\ReportIndexController;
use App\Modules\Reporting\Http\Controllers\SalesReportController;
use App\Modules\Reporting\Http\Controllers\SavedFilterController;
use App\Modules\Reporting\Http\Controllers\TaxReportController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Reporting — web routes
|--------------------------------------------------------------------------
|
| The dashboard is NOT here. It lives in routes/web.php outside `module:reporting`,
| because every shop on every plan has a front page — see DashboardController.
|
*/

Route::middleware(['tenant', 'auth', 'tenant.user', 'module:reporting'])
    ->prefix('reporting')
    ->name('reporting.')
    ->group(function (): void {
        Route::get('/', ReportIndexController::class)->name('index');

        Route::get('/sales', [SalesReportController::class, 'index'])->name('sales');

        // Throttled: an export runs the report and then builds a workbook, which is the
        // most expensive thing in this module. A held-down refresh key should not be
        // able to queue thirty of them.
        Route::get('/sales/export', [SalesReportController::class, 'export'])
            ->middleware('throttle:20,1')
            ->name('sales.export');

        Route::get('/profit', [ProfitReportController::class, 'index'])->name('profit');

        Route::get('/profit/export', [ProfitReportController::class, 'export'])
            ->middleware('throttle:20,1')
            ->name('profit.export');

        Route::get('/technicians', [RepairReportController::class, 'index'])->name('technicians');

        Route::get('/technicians/export', [RepairReportController::class, 'export'])
            ->middleware('throttle:20,1')
            ->name('technicians.export');

        Route::get('/inventory', [InventoryReportController::class, 'index'])->name('inventory');

        Route::get('/inventory/export', [InventoryReportController::class, 'export'])
            ->middleware('throttle:20,1')
            ->name('inventory.export');

        Route::get('/financial', [FinancialReportController::class, 'index'])->name('financial');

        Route::get('/financial/export', [FinancialReportController::class, 'export'])
            ->middleware('throttle:20,1')
            ->name('financial.export');

        Route::get('/tax', [TaxReportController::class, 'index'])->name('tax');

        Route::get('/tax/export', [TaxReportController::class, 'export'])
            ->middleware('throttle:20,1')
            ->name('tax.export');

        Route::get('/operations', [OperationsReportController::class, 'index'])->name('operations');

        Route::get('/operations/export', [OperationsReportController::class, 'export'])
            ->middleware('throttle:20,1')
            ->name('operations.export');

        /*
        | Saved presets are per user and per report, so they are not gated by the report's
        | own permission: the payload is a filter, not a figure. What a preset can do is
        | bounded by the screen it opens — a viewer without `installments.view` who somehow
        | acquired a preset for the instalment book still gets a 403 from the screen.
        */
        Route::post('/presets', [SavedFilterController::class, 'store'])->name('presets.store');

        Route::delete('/presets/{savedFilter}', [SavedFilterController::class, 'destroy'])
            ->name('presets.destroy');
    });
