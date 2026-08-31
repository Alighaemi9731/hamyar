<?php

declare(strict_types=1);

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
| The destinations this hub links to live in Identity, Inventory and Platform and keep
| their own gates; this file owns only the door.
|
*/

Route::middleware(['tenant', 'auth', 'tenant.user'])->group(function (): void {
    Route::get('/settings', SettingsIndexController::class)->name('settings.index');
});
