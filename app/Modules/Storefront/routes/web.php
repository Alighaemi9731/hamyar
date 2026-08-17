<?php

declare(strict_types=1);

use App\Modules\Storefront\Http\Controllers\PriceListController;
use App\Modules\Storefront\Http\Controllers\PublicShopController;
use App\Modules\Storefront\Http\Controllers\StorefrontAdminController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Storefront — web routes
|--------------------------------------------------------------------------
|
| Two audiences, and they share no middleware.
|
| The PUBLIC routes have no auth and no `module:storefront` gate. A price list already in a
| colleague's WhatsApp must keep working for the days it was minted for, even if the shop's
| subscription lapses in between — the same argument the public invoice route makes. What
| bounds them instead is the token itself, the expiry, and rate limiting.
|
| The ADMIN routes are ordinary tenant routes behind the module gate.
|
*/

Route::middleware(['tenant'])->group(function (): void {
    Route::get('/shop', [PublicShopController::class, 'show'])->name('storefront.shop');

    /*
    | Rate limits, and they are the security control the spec asks for by name.
    |
    | `unlock` is the tight one — a password on a public URL is otherwise free to
    | brute-force. Keyed per IP by Laravel's default, which is the best available for an
    | endpoint with no identity behind it.
    */
    Route::get('/p/{token}', [PriceListController::class, 'show'])
        ->middleware('throttle:60,1')
        ->name('storefront.price-list');

    Route::post('/p/{token}/unlock', [PriceListController::class, 'unlock'])
        ->middleware('throttle:10,1')
        ->name('storefront.price-list.unlock');

    Route::get('/p/{token}/print', [PriceListController::class, 'download'])
        ->middleware('throttle:30,1')
        ->name('storefront.price-list.print');
});

Route::middleware(['tenant', 'auth', 'tenant.user', 'module:storefront'])
    ->prefix('storefront')
    ->name('storefront.')
    ->group(function (): void {
        Route::get('/', [StorefrontAdminController::class, 'index'])->name('index');
        Route::put('/settings', [StorefrontAdminController::class, 'update'])->name('settings');

        Route::post('/links', [StorefrontAdminController::class, 'store'])->name('links.store');
        Route::delete('/links/{link}', [StorefrontAdminController::class, 'revoke'])
            ->whereNumber('link')->name('links.revoke');
    });
