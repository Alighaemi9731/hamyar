<?php

declare(strict_types=1);

namespace App\Modules\CRM\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\CRM\Models\Party;
use App\Modules\CRM\Services\LedgerService;
use App\Support\Digits;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Party lookup for the `<PartyPicker/>` component.
 *
 * A JSON endpoint rather than a page: the picker is used inside forms all over the
 * product (purchase intake, POS, repair intake), and each of those pages already has
 * its own Inertia payload. Shipping every customer to every one of them would be a
 * page weight nobody needs.
 *
 * Party CRUD screens belong to Phase 4 — this is deliberately read-only.
 */
final class PartyController extends Controller
{
    /** More than a picker can usefully show; past this, refine the search. */
    private const LIMIT = 12;

    public function search(Request $request, LedgerService $ledger): JsonResponse
    {
        $this->authorize('viewAny', Party::class);

        // Digits arrive however the keyboard produced them. A number typed as ۰۹۱۲…
        // must find the customer saved as 0912… — PartyContact normalises on save, so
        // the query has to normalise too or the match never happens.
        $term = Digits::toLatin(trim($request->string('q')->value()));

        $showBalance = $request->user()?->can('crm.view_balance') ?? false;

        $parties = Party::query()
            ->with('contacts')
            ->where('is_active', true)
            ->when($request->filled('kind'), fn ($query) => $query->where('kind', $request->string('kind')->value()))
            ->search($term)
            ->orderBy('name')
            ->limit(self::LIMIT)
            ->get();

        // One aggregate for the whole page of results, not one per row.
        $balances = $showBalance ? $ledger->partyBalances($parties) : [];

        return response()->json([
            'results' => $parties->map(fn (Party $party): array => [
                'id' => $party->id,
                'name' => $party->name,
                'company_name' => $party->company_name,
                'kind' => $party->kind->value,
                'kind_label' => $party->kind->labelFa(),
                'mobile' => $party->primaryMobile(),
                // Signed: positive means they owe the shop. Withheld entirely — not
                // sent as null — from staff without `crm.view_balance`, so the figure
                // never reaches a browser that is not allowed to show it.
                'balance' => $showBalance ? Money::toArray($balances[$party->id] ?? 0) : null,
            ])->values()->all(),
        ]);
    }
}
