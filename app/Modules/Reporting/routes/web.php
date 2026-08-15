<?php

declare(strict_types=1);

use App\Modules\Reporting\Http\Controllers\ProfitReportController;
use App\Modules\Reporting\Http\Controllers\ReportIndexController;
use App\Modules\Reporting\Http\Controllers\SalesReportController;
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
    });
