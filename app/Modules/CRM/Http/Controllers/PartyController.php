<?php

declare(strict_types=1);

namespace App\Modules\CRM\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Models\PriceLevel;
use App\Modules\CRM\Enums\PartyKind;
use App\Modules\CRM\Http\Requests\PartyRequest;
use App\Modules\CRM\Models\Party;
use App\Modules\CRM\Models\PartyContact;
use App\Modules\CRM\Models\PartyFollowUp;
use App\Modules\CRM\Services\LedgerService;
use App\Modules\CRM\Services\LoyaltyService;
use App\Support\Digits;
use App\Support\Money;
use App\Support\Quota\QuotaGuard;
use App\Support\Timeline\TimelineEntry;
use App\Support\Timeline\TimelineRegistry;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Customers, suppliers and همکارها — one table, one screen, both sides of the counter.
 *
 * The page that matters is `show`: a shop looks a person up to answer one of three
 * questions — what do they owe, what have we done for them, and what did we promise.
 * All three are on it, which is why the timeline is assembled from every module rather
 * than from CRM's own tables (see `TimelineRegistry`).
 */
final class PartyController extends Controller
{
    /** More than a picker can usefully show; past this, refine the search. */
    private const LIMIT = 12;

    private const PER_PAGE = 25;

    public function index(Request $request, LedgerService $ledger): Response
    {
        $this->authorize('viewAny', Party::class);

        $term = Digits::toLatin(trim($request->string('q')->value()));
        $showBalance = $request->user()?->can('crm.view_balance') ?? false;

        $parties = Party::query()
            ->with('contacts')
            ->when($request->filled('kind'), fn ($query) => $query->where('kind', $request->string('kind')->value()))
            ->when(! $request->boolean('include_inactive'), fn ($query) => $query->where('is_active', true))
            ->search($term)
            ->orderBy('name')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        // One aggregate for the page, not one per row.
        // `getCollection()` keeps it an Eloquent collection, which is what the batched
        // balance lookup expects; `items()` would hand it a plain array.
        $balances = $showBalance ? $ledger->partyBalances($parties->getCollection()) : [];

        return Inertia::render('CRM::Parties/Index', [
            'parties' => [
                'rows' => array_map(fn (Party $party): array => [
                    'id' => $party->id,
                    'name' => $party->name,
                    'company_name' => $party->company_name,
                    'kind' => $party->kind->value,
                    'kind_label' => $party->kind->labelFa(),
                    'mobile' => $party->primaryMobile(),
                    'is_active' => $party->is_active,
                    'balance' => $showBalance ? Money::toArray($balances[$party->id] ?? 0) : null,
                ], $parties->items()),
                'links' => $parties->linkCollection()->toArray(),
                'total' => $parties->total(),
            ],
            'filters' => [
                'q' => $term,
                'kind' => $request->string('kind')->value() ?: null,
                'include_inactive' => $request->boolean('include_inactive'),
            ],
            'kinds' => $this->kindOptions(),
            'can' => [
                'create' => $request->user()?->can('crm.create') ?? false,
                'view_balance' => $showBalance,
            ],
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', Party::class);

        return Inertia::render('CRM::Parties/Edit', [
            'party' => null,
            'contacts' => [],
            'kinds' => $this->kindOptions(),
            'price_levels' => $this->priceLevelOptions(),
        ]);
    }

    public function store(PartyRequest $request, QuotaGuard $quota, ConnectionInterface $connection): RedirectResponse
    {
        $this->authorize('create', Party::class);

        // The party and its contacts were two statements with nothing wrapping them, so a
        // failure between them left a customer with no phone number. The credit needs a
        // transaction anyway; both defects close with the same three lines.
        /** @var Party $party */
        $party = $connection->transaction(function () use ($request, $quota): Party {
            $quota->consume('crm.parties');

            /** @var Party $party */
            $party = Party::query()->create($request->partyAttributes());

            foreach ($request->contacts() as $contact) {
                $party->contacts()->create($contact);
            }

            return $party;
        });

        return redirect()
            ->route('crm.parties.show', $party)
            ->with('success', 'طرف حساب ثبت شد.');
    }

    public function show(Request $request, Party $party, LedgerService $ledger, TimelineRegistry $timeline, LoyaltyService $loyalty): Response
    {
        $this->authorize('view', $party);

        $party->load(['contacts', 'addresses', 'priceLevel:id,name_fa', 'tags:id,name,colour']);

        $showBalance = $request->user()?->can('crm.view_balance') ?? false;

        $assembled = $timeline->for($party->id);

        return Inertia::render('CRM::Parties/Show', [
            'party' => [
                'id' => $party->id,
                'name' => $party->name,
                'company_name' => $party->company_name,
                'kind' => $party->kind->value,
                'kind_label' => $party->kind->labelFa(),
                'national_id' => $party->national_id,
                'economic_code' => $party->economic_code,
                'price_level' => $party->priceLevel?->name_fa,
                'birthday' => $party->birthday?->toIso8601String(),
                'is_active' => $party->is_active,
                'notes' => $party->notes,
                'tags' => $party->tags->map(fn ($tag): array => [
                    'id' => $tag->id,
                    'name' => $tag->name,
                    'colour' => $tag->colour,
                ])->values()->all(),
            ],
            'contacts' => $party->contacts->map(fn (PartyContact $contact): array => [
                'id' => $contact->id,
                'type' => $contact->type,
                'value' => $contact->value,
                'label' => $contact->label,
                'is_primary' => $contact->is_primary,
            ])->values()->all(),
            'addresses' => $party->addresses->map(fn ($address): array => [
                'id' => $address->id,
                'label' => $address->label,
                'city' => $address->city,
                'province' => $address->province,
                'line' => $address->line,
                'postal_code' => $address->postal_code,
            ])->values()->all(),

            // The three figures the page exists to answer, all withheld together from
            // staff without `crm.view_balance` — a credit limit is as revealing as the
            // balance it is measured against.
            'finance' => $showBalance ? $this->finance($party, $ledger) : null,

            'timeline' => array_map(
                static fn (TimelineEntry $entry): array => $entry->toArray(),
                $assembled['entries']
            ),
            // Named rather than hidden: a page missing its repair history should say so.
            'timeline_failed' => $assembled['failed'],

            'follow_ups' => $this->followUps($party),
            'loyalty' => [
                'balance' => $loyalty->balanceFor($party->id),
                'rule' => $loyalty->activeRuleSummary(),
            ],
            'can' => [
                'update' => $request->user()?->can('crm.update') ?? false,
                'view_balance' => $showBalance,
                // Draws the «تاریخچه» link only. The viewer authorises for itself.
                'view_activity' => $request->user()?->can('activity.view') ?? false,
            ],
        ]);
    }

    public function edit(Request $request, Party $party): Response
    {
        $this->authorize('update', $party);

        $party->load('contacts');

        return Inertia::render('CRM::Parties/Edit', [
            'party' => [
                'id' => $party->id,
                'name' => $party->name,
                'company_name' => $party->company_name,
                'kind' => $party->kind->value,
                'national_id' => $party->national_id,
                'economic_code' => $party->economic_code,
                'price_level_id' => $party->price_level_id,
                'credit_limit' => $party->credit_limit,
                'opening_balance' => $party->opening_balance,
                'birthday' => $party->birthday?->toIso8601String(),
                'is_active' => $party->is_active,
                'notes' => $party->notes,
            ],
            'contacts' => $party->contacts->map(fn (PartyContact $contact): array => [
                'id' => $contact->id,
                'type' => $contact->type,
                'value' => $contact->value,
                'label' => $contact->label,
                'is_primary' => $contact->is_primary,
            ])->values()->all(),
            'kinds' => $this->kindOptions(),
            'price_levels' => $this->priceLevelOptions(),
        ]);
    }

    public function update(PartyRequest $request, Party $party): RedirectResponse
    {
        $this->authorize('update', $party);

        $party->update($request->partyAttributes());

        // Contacts are replaced wholesale rather than diffed: the form edits them as a
        // list, and a diff would have to guess which row a changed number used to be.
        $party->contacts()->delete();

        foreach ($request->contacts() as $contact) {
            $party->contacts()->create($contact);
        }

        return redirect()
            ->route('crm.parties.show', $party)
            ->with('success', 'طرف حساب به‌روزرسانی شد.');
    }

    /**
     * Party lookup for the `<PartyPicker/>` component.
     *
     * A JSON endpoint rather than a page: the picker is used inside forms all over the
     * product (purchase intake, POS, repair intake), and each of those pages already
     * has its own Inertia payload.
     */
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
                // sent as null — from staff without `crm.view_balance`.
                'balance' => $showBalance ? Money::toArray($balances[$party->id] ?? 0) : null,
            ])->values()->all(),
        ]);
    }

    /**
     * Balance, limit and headroom — the credit conversation in three numbers.
     *
     * @return array{balance: array{value: int, formatted: string}, opening_balance: array{value: int, formatted: string}, credit_limit: array{value: int, formatted: string}|null, exceeds_limit: bool, statement: list<array<string, mixed>>}
     */
    private function finance(Party $party, LedgerService $ledger): array
    {
        $statement = $ledger->statement($party);
        $balance = $statement['closing'];

        /** @var list<array<string, mixed>> $rows */
        $rows = [];

        // Newest first, and only the recent past: the full statement is its own screen.
        foreach (array_slice(array_reverse($statement['rows']), 0, 25) as $row) {
            $entry = $row['entry'];

            $rows[] = [
                'id' => $entry->id,
                'occurred_at' => $entry->occurred_at->toIso8601String(),
                'description' => $entry->description,
                'debit' => Money::toArray($entry->debit),
                'credit' => Money::toArray($entry->credit),
                'balance' => Money::toArray($row['balance']),
            ];
        }

        return [
            'balance' => Money::toArray($balance),
            'opening_balance' => Money::toArray($party->opening_balance),
            'credit_limit' => $party->credit_limit === null ? null : Money::toArray($party->credit_limit),
            // A warning, never a block (spec). The screen says so in as many words.
            'exceeds_limit' => $party->credit_limit !== null && $balance > $party->credit_limit,
            'statement' => $rows,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function followUps(Party $party): array
    {
        $rows = [];

        $followUps = PartyFollowUp::query()
            ->with('assignee:id,name')
            ->where('party_id', $party->id)
            // Open ones first regardless of date, then the most recently completed.
            ->orderByRaw('done_at is not null')
            ->orderBy('due_at')
            ->limit(20)
            ->get();

        foreach ($followUps as $followUp) {
            $rows[] = [
                'id' => $followUp->id,
                'title' => $followUp->title,
                'body' => $followUp->body,
                'due_at' => $followUp->due_at->toIso8601String(),
                'done_at' => $followUp->done_at?->toIso8601String(),
                'assignee' => $followUp->assignee?->name,
                'is_overdue' => $followUp->isOverdue(),
            ];
        }

        return $rows;
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function kindOptions(): array
    {
        return array_map(
            static fn (PartyKind $kind): array => ['value' => $kind->value, 'label' => $kind->labelFa()],
            PartyKind::cases()
        );
    }

    /**
     * @return list<array{id: int, label: string}>
     */
    private function priceLevelOptions(): array
    {
        $options = [];

        foreach (PriceLevel::query()->orderBy('position')->get(['id', 'name_fa']) as $level) {
            $options[] = ['id' => $level->id, 'label' => $level->name_fa];
        }

        return $options;
    }
}
