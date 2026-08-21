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
| Two groups since [ADR 0017](../../../../docs/adr/0017-single-host-app.md), and the
| line between them is "does the session already know which shop this is?".
|
| It used to be one group behind `tenant`, because every route lived on the shop's own
| hostname and the middleware read the tenant off it. With one address for every shop
| that middleware reads the SESSION instead — and redirects to /login when it finds
| nothing. For password reset and for opening an invitation, "the session carries no
| tenant yet" is not an edge case, it is the defining state of the flow, so leaving them
| there bounced every one of them to the page they exist to get somebody back to.
|
| Each of the two recovers the shop from the credential the visitor is already holding,
| and neither takes it from anything the visitor can author:
|
|   · password reset — from the mobile number, via AccountLookup::tenantForMobile(),
|     entered with TenantContext::runFor() around the token work;
|   · invitations — from the token itself, via `tenant.public`, which pins the shop that
|     issued it and clears the context in a `finally`.
|
*/

Route::domain('app.'.config()->string('app.domain'))->middleware('guest')->group(function (): void {

    /* ------------------------------------------------------- password reset -- */

    // Throttled: unauthenticated, and each one reveals something if hammered.
    Route::get('/forgot-password', [PasswordResetController::class, 'create'])->name('password.request');
    Route::post('/forgot-password', [PasswordResetController::class, 'store'])
        ->middleware('throttle:6,1')->name('password.email');

    Route::get('/reset-password', [PasswordResetController::class, 'edit'])->name('password.edit');
    Route::post('/reset-password', [PasswordResetController::class, 'update'])
        ->middleware('throttle:6,1')->name('password.update');

    /* ---------------------------------------------------------- invitations -- */

    /*
    | The token moved out of the query string and into the PATH.
    |
    | That is not cosmetic: `tenant.public` reads a route PARAMETER, so a token in
    | `?token=` cannot pin anything. `invitations.token_hash` is globally unique by
    | design and listed as such in TenancyCheckCommand::GLOBALLY_UNIQUE_BY_DESIGN —
    | that category exists for exactly this shape, a credential that has to resolve
    | before any tenant is known.
    |
    | An unknown token therefore 404s in the middleware rather than reaching the
    | controller. A token that resolves but is expired, revoked or already used still
    | reaches it, and gets the "invalid or expired" message — the distinction the
    | controller can safely make, because by then the holder has proved they have a
    | real token.
    */
    Route::get('/invitations/accept/{token}', [InvitationController::class, 'accept'])
        ->middleware(['tenant.public:invitation', 'throttle:10,1'])
        ->name('invitations.accept');
    Route::post('/invitations/accept/{token}', [InvitationController::class, 'store'])
        ->middleware(['tenant.public:invitation', 'throttle:10,1'])
        ->name('invitations.store');
});

/*
|--------------------------------------------------------------------------
| With a shop already pinned in the session
|--------------------------------------------------------------------------
|
| The 2FA challenge belongs here rather than above, and it is worth saying why it is
| not the same case as the two flows on the app host. LoginController::store() writes
| `tenant_id` into the session BEFORE redirecting to the challenge — the password is
| proven, only the second factor is outstanding — so by the time this route is reached
| the shop is known. Reaching it with no session at all is a genuinely stale link, and
| `tenant` sending it to /login is the right answer.
|
*/

Route::middleware('tenant')->group(function (): void {

    /* ---------------------------------------------------------------- guest -- */

    Route::middleware('guest')->group(function (): void {
        Route::get('/two-factor/challenge', [TwoFactorController::class, 'challenge'])->name('two-factor.challenge');
        Route::post('/two-factor/challenge', [TwoFactorController::class, 'verify'])
            ->middleware('throttle:10,1')->name('two-factor.verify');
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
