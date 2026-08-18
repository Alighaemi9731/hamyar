<?php

declare(strict_types=1);

use App\Modules\Catalog\Http\Controllers\CategoryController;
use App\Modules\Catalog\Http\Controllers\LabelController;
use App\Modules\Catalog\Http\Controllers\PriceController;
use App\Modules\Catalog\Http\Controllers\ProductController;
use App\Modules\Catalog\Http\Controllers\ProductImportController;
use App\Modules\Catalog\Http\Controllers\VariantController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Catalog — web routes
|--------------------------------------------------------------------------
|
| Every route lives on a TENANT hostname: `tenant` resolves the shop and pins the
| context, so the lookups below are confined to it without any controller filtering.
| `module:catalog` gates the route on the plan as well as the nav (golden rule 7).
|
*/

Route::middleware(['tenant', 'auth', 'tenant.user', 'module:catalog'])
    ->prefix('catalog')
    ->name('catalog.')
    ->group(function (): void {
        Route::get('/', [ProductController::class, 'index'])->name('products.index');

        Route::get('/categories', [CategoryController::class, 'index'])->name('categories.index');
        Route::post('/categories', [CategoryController::class, 'store'])->name('categories.store');
        Route::put('/categories/{category}', [CategoryController::class, 'update'])
            ->whereNumber('category')->name('categories.update');
        Route::delete('/categories/{category}', [CategoryController::class, 'destroy'])
            ->whereNumber('category')->name('categories.destroy');

        Route::get('/labels', [LabelController::class, 'index'])->name('labels.index');
        Route::get('/labels/search', [LabelController::class, 'search'])
            ->middleware('throttle:120,1')->name('labels.search');

        Route::get('/prices', [PriceController::class, 'index'])->name('prices.index');
        Route::post('/prices/preview', [PriceController::class, 'preview'])->name('prices.preview');
        Route::post('/prices/apply', [PriceController::class, 'apply'])->name('prices.apply');
        Route::put('/prices/{variant}', [PriceController::class, 'update'])
            ->whereNumber('variant')->name('prices.update');

        /* --------------------------------------------------------- import -- */
        /*
        | Before `/products/{product}`: a fixed segment must bind ahead of the
        | numeric parameter or `/products/import` resolves as product #import.
        */
        Route::get('/import', [ProductImportController::class, 'create'])->name('import.create');
        Route::get('/import/template', [ProductImportController::class, 'template'])
            ->middleware('throttle:20,1')->name('import.template');
        Route::post('/import/analyse', [ProductImportController::class, 'analyse'])
            ->middleware('throttle:20,1')->name('import.analyse');
        Route::post('/import/dry-run', [ProductImportController::class, 'dryRun'])
            ->middleware('throttle:20,1')->name('import.dry-run');
        Route::post('/import', [ProductImportController::class, 'store'])
            ->middleware('throttle:10,1')->name('import.store');

        Route::get('/products/create', [ProductController::class, 'create'])->name('products.create');
        Route::post('/products', [ProductController::class, 'store'])->name('products.store');
        Route::get('/products/{product}', [ProductController::class, 'edit'])
            ->whereNumber('product')->name('products.edit');
        Route::put('/products/{product}', [ProductController::class, 'update'])
            ->whereNumber('product')->name('products.update');
        Route::put('/products/{product}/variants', [ProductController::class, 'regenerate'])
            ->whereNumber('product')->name('products.variants');
        Route::delete('/products/{product}', [ProductController::class, 'destroy'])
            ->whereNumber('product')->name('products.destroy');

        Route::put('/variants/{variant}', [VariantController::class, 'update'])
            ->whereNumber('variant')->name('variants.update');
    });
