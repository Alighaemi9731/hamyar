<?php

declare(strict_types=1);

use App\Modules\Identity\Http\Controllers\ActivityLogController;
use App\Modules\Identity\Http\Controllers\InvitationController;
use App\Modules\Identity\Http\Controllers\PasswordResetController;
use App\Modules\Identity\Http\Controllers\SessionController;
use App\Modules\Identity\Http\Controllers\TwoFactorController;
use App\Modules\Identity\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Identity — web routes
|--------------------------------------------------------------------------
|
| Every route here lives on a TENANT hostname: the `tenant` middleware resolves the
| shop and pins the context, so each lookup below is automatically confined to it.
| That is why none of these controllers filter by tenant themselves.
|
*/

Route::middleware('tenant')->group(function (): void {

    /* ---------------------------------------------------------------- guest -- */

    Route::middleware('guest')->group(function (): void {
        // Throttled: unauthenticated, and each one reveals something if hammered.
        Route::get('/forgot-password', [PasswordResetController::class, 'create'])->name('password.request');
        Route::post('/forgot-password', [PasswordResetController::class, 'store'])
            ->middleware('throttle:6,1')->name('password.email');

        Route::get('/reset-password', [PasswordResetController::class, 'edit'])->name('password.edit');
        Route::post('/reset-password', [PasswordResetController::class, 'update'])
            ->middleware('throttle:6,1')->name('password.update');

        Route::get('/two-factor/challenge', [TwoFactorController::class, 'challenge'])->name('two-factor.challenge');
        Route::post('/two-factor/challenge', [TwoFactorController::class, 'verify'])
            ->middleware('throttle:10,1')->name('two-factor.verify');

        Route::get('/invitations/accept', [InvitationController::class, 'accept'])->name('invitations.accept');
        Route::post('/invitations/accept', [InvitationController::class, 'store'])
            ->middleware('throttle:10,1')->name('invitations.store');
    });

    /* ------------------------------------------------------------ signed in -- */

    Route::middleware(['auth', 'tenant.user'])->group(function (): void {
        Route::get('/settings/two-factor', [TwoFactorController::class, 'show'])->name('two-factor.show');
        Route::post('/settings/two-factor', [TwoFactorController::class, 'store'])->name('two-factor.store');
        Route::post('/settings/two-factor/confirm', [TwoFactorController::class, 'confirm'])->name('two-factor.confirm');
        Route::delete('/settings/two-factor', [TwoFactorController::class, 'destroy'])->name('two-factor.destroy');

        Route::get('/settings/sessions', [SessionController::class, 'index'])->name('sessions.index');
        Route::delete('/settings/sessions', [SessionController::class, 'destroyOthers'])->name('sessions.destroy-others');
        Route::delete('/settings/sessions/{session}', [SessionController::class, 'destroy'])->name('sessions.destroy');

        Route::get('/settings/users', [UserController::class, 'index'])->name('users.index');
        Route::post('/settings/users/invite', [UserController::class, 'invite'])->name('users.invite');
        Route::delete('/settings/invitations/{invitation}', [UserController::class, 'revokeInvitation'])->name('invitations.revoke');
        Route::put('/settings/users/{user}/roles', [UserController::class, 'updateRoles'])->name('users.roles');
        Route::put('/settings/users/{user}/active', [UserController::class, 'toggleActive'])->name('users.active');

        Route::get('/settings/activity', [ActivityLogController::class, 'index'])->name('activity.index');
    });
});
