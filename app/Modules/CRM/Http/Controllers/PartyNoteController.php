<?php

declare(strict_types=1);

namespace App\Modules\CRM\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\CRM\Models\Party;
use App\Modules\CRM\Models\PartyNote;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Dated notes on a party.
 *
 * Create-only, deliberately: these rows are append-only (see {@see PartyNote}), so
 * there is no update or delete endpoint to write. Getting a note wrong is fixed by
 * adding another one, exactly as it would be in a paper ledger.
 */
final class PartyNoteController extends Controller
{
    public function store(Request $request, Party $party): RedirectResponse
    {
        // Writing a note is part of serving a customer, not of editing their record —
        // `crm.update` would keep it from the salesperson who took the call.
        $this->authorize('view', $party);

        $validated = $request->validate([
            'body' => ['required', 'string', 'max:2000'],
        ], [
            'body.required' => 'متن یادداشت را بنویسید.',
        ]);

        PartyNote::query()->create([
            'party_id' => $party->getKey(),
            'body' => $validated['body'],
            'author_id' => $request->user()?->id,
        ]);

        return back()->with('success', 'یادداشت ثبت شد.');
    }
}
