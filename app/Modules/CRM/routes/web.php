<?php

declare(strict_types=1);

use App\Modules\CRM\Http\Controllers\FollowUpController;
use App\Modules\CRM\Http\Controllers\LoyaltyController;
use App\Modules\CRM\Http\Controllers\PartyController;
use App\Modules\CRM\Http\Controllers\PartyImportController;
use App\Modules\CRM\Http\Controllers\PartyNoteController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| CRM — web routes
|--------------------------------------------------------------------------
|
| Every route lives on a TENANT hostname: `tenant` resolves the shop and pins the
| context, so the lookups below are confined to it without any controller filtering.
| `module:crm` gates the route on the plan as well as the nav (golden rule 7).
|
*/

Route::middleware(['tenant', 'auth', 'tenant.user', 'module:crm'])
    ->prefix('crm')
    ->name('crm.')
    ->group(function (): void {
        Route::get('/', [PartyController::class, 'index'])->name('parties.index');

        /*
        | Fixed segments before `{party}`, or `search`, `create` and `import` bind as
        | party ids and 404.
        */

        // Throttled: it runs on every keystroke of a debounced picker, and an
        // unthrottled search box is a cheap way to walk a shop's customer list.
        Route::get('/parties/search', [PartyController::class, 'search'])
            ->middleware('throttle:120,1')
            ->name('parties.search');

        Route::get('/parties/create', [PartyController::class, 'create'])->name('parties.create');
        Route::post('/parties', [PartyController::class, 'store'])->name('parties.store');

        Route::get('/follow-ups', [FollowUpController::class, 'index'])->name('follow-ups.index');
        Route::get('/follow-ups/assignees', [FollowUpController::class, 'assignees'])
            ->middleware('throttle:60,1')->name('follow-ups.assignees');
        Route::put('/follow-ups/{followUp}', [FollowUpController::class, 'toggle'])
            ->whereNumber('followUp')->name('follow-ups.toggle');
        Route::delete('/follow-ups/{followUp}', [FollowUpController::class, 'destroy'])
            ->whereNumber('followUp')->name('follow-ups.destroy');

        /* ------------------------------------------------------------- import -- */

        Route::get('/import', [PartyImportController::class, 'create'])->name('import.create');
        Route::post('/import/analyse', [PartyImportController::class, 'analyse'])
            ->middleware('throttle:20,1')->name('import.analyse');
        Route::post('/import/dry-run', [PartyImportController::class, 'dryRun'])
            ->middleware('throttle:20,1')->name('import.dry-run');
        Route::post('/import', [PartyImportController::class, 'store'])
            ->middleware('throttle:10,1')->name('import.store');

        /* -------------------------------------------------------------- party -- */

        Route::get('/parties/{party}', [PartyController::class, 'show'])
            ->whereNumber('party')->name('parties.show');
        Route::get('/parties/{party}/edit', [PartyController::class, 'edit'])
            ->whereNumber('party')->name('parties.edit');
        Route::put('/parties/{party}', [PartyController::class, 'update'])
            ->whereNumber('party')->name('parties.update');

        Route::post('/parties/{party}/notes', [PartyNoteController::class, 'store'])
            ->whereNumber('party')->name('parties.notes.store');
        Route::post('/parties/{party}/follow-ups', [FollowUpController::class, 'store'])
            ->whereNumber('party')->name('parties.follow-ups.store');
        Route::post('/parties/{party}/loyalty', [LoyaltyController::class, 'store'])
            ->whereNumber('party')->name('parties.loyalty.store');
    });
