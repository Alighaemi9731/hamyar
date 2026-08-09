<?php

declare(strict_types=1);

use App\Modules\CRM\Http\Controllers\PartyController;
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
| Party CRUD screens are Phase 4. What is here is the lookup the `<PartyPicker/>`
| component talks to, which several Phase 3 and Phase 5 forms need first.
|
*/

Route::middleware(['tenant', 'auth', 'tenant.user', 'module:crm'])
    ->prefix('crm')
    ->name('crm.')
    ->group(function (): void {
        // Throttled: it runs on every keystroke of a debounced picker, and an
        // unthrottled search box is a cheap way to walk a shop's customer list.
        Route::get('/parties/search', [PartyController::class, 'search'])
            ->middleware('throttle:120,1')
            ->name('parties.search');
    });
