<?php

declare(strict_types=1);

use App\Modules\Catalog\Http\Controllers\CategoryController;
use App\Modules\Catalog\Http\Controllers\PriceController;
use App\Modules\Catalog\Http\Controllers\ProductController;
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

        Route::get('/prices', [PriceController::class, 'index'])->name('prices.index');
        Route::post('/prices/preview', [PriceController::class, 'preview'])->name('prices.preview');
        Route::post('/prices/apply', [PriceController::class, 'apply'])->name('prices.apply');
        Route::put('/prices/{variant}', [PriceController::class, 'update'])
            ->whereNumber('variant')->name('prices.update');

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
