<?php

declare(strict_types=1);

use App\Modules\Settings\Http\Controllers\MessagingSettingsController;
use App\Modules\Settings\Http\Controllers\PrintSettingsController;
use App\Modules\Settings\Http\Controllers\SettingsIndexController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Settings — web routes
|--------------------------------------------------------------------------
|
| Loaded with the `web` middleware group by the module service provider.
|
| Deliberately NOT behind `module:settings`, for the same reason billing is not (see
| routes/web.php): the plan kill-switch must never be able to lock a shop out of the
| screen that lets it manage its own account. A shop that cannot reach «تنظیمات» cannot
| reach two-factor, cannot see who has access, and cannot pay — which is the classic
| lockout bug wearing a different hat.
|
| Most of the destinations this hub links to live in Identity, Inventory and Platform and
| keep their own gates. The two below are the module's own: the shop settings that a
| dozen `TenantShopSettings` accessors have read since Phase 1 and that nothing could
| write, so «تنظیمات چاپ» meant opening psql.
|
| Both are guarded by `ShopSettingsPolicy` rather than by middleware — the read and the
| write want different permissions, which a route group cannot express.
|
*/

Route::middleware(['tenant', 'auth', 'tenant.user'])->group(function (): void {
    Route::get('/settings', SettingsIndexController::class)->name('settings.index');

    Route::get('/settings/print', [PrintSettingsController::class, 'edit'])->name('settings.print.edit');
    Route::put('/settings/print', [PrintSettingsController::class, 'update'])->name('settings.print.update');

    Route::get('/settings/messaging', [MessagingSettingsController::class, 'edit'])->name('settings.messaging.edit');
    Route::put('/settings/messaging', [MessagingSettingsController::class, 'update'])->name('settings.messaging.update');
});
