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

/*
| The public window now carries the shop's slug in the path.
|
| It used to be `/shop` on the shop's own hostname, which is what said whose window it
| was. ADR 0017 removed per-shop hostnames, so a bare `/shop` would have no way to know
| which shop to render — it would have had to pick one, and there is no correct pick.
|
| `storefront_settings.slug` was already globally unique, because a public identifier is
| exactly the thing two shops must not both claim.
*/
Route::middleware(['tenant.public:storefront,slug'])
    ->get('/shop/{slug}', [PublicShopController::class, 'show'])
    ->name('storefront.shop');

Route::group([], function (): void {

    /*
    | Rate limits, and they are the security control the spec asks for by name.
    |
    | `unlock` is the tight one — a password on a public URL is otherwise free to
    | brute-force. Keyed per IP by Laravel's default, which is the best available for an
    | endpoint with no identity behind it.
    */
    Route::get('/p/{token}', [PriceListController::class, 'show'])
        ->middleware(['tenant.public:price-list', 'throttle:60,1'])
        ->name('storefront.price-list');

    Route::post('/p/{token}/unlock', [PriceListController::class, 'unlock'])
        ->middleware(['tenant.public:price-list', 'throttle:10,1'])
        ->name('storefront.price-list.unlock');

    Route::get('/p/{token}/print', [PriceListController::class, 'download'])
        ->middleware(['tenant.public:price-list', 'throttle:30,1'])
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
