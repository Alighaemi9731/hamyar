<?php

declare(strict_types=1);

namespace App\Modules\CRM\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\CRM\Models\Party;
use App\Modules\CRM\Models\PartyFollowUp;
use App\Modules\Identity\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Follow-up reminders: the shop's promise to call someone back.
 *
 * The desk (`index`) is the screen this feature is for. A reminder attached to a
 * customer that only appears on that customer's page is a reminder nobody sees — the
 * question staff actually ask is "who am I supposed to call today", which is a list
 * across parties.
 */
final class FollowUpController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Party::class);

        $mine = $request->boolean('mine');
        $showDone = $request->boolean('done');

        $followUps = PartyFollowUp::query()
            ->with(['party:id,name', 'assignee:id,name'])
            ->when(! $showDone, fn ($query) => $query->open())
            ->when($showDone, fn ($query) => $query->whereNotNull('done_at'))
            ->when($mine, fn ($query) => $query->where('assignee_id', $request->user()?->id))
            ->orderBy('due_at')
            ->paginate(25)
            ->withQueryString();

        $now = CarbonImmutable::now();

        return Inertia::render('CRM::FollowUps/Index', [
            'follow_ups' => [
                'rows' => array_map(fn (PartyFollowUp $followUp): array => [
                    'id' => $followUp->id,
                    'title' => $followUp->title,
                    'body' => $followUp->body,
                    'due_at' => $followUp->due_at->toIso8601String(),
                    'done_at' => $followUp->done_at?->toIso8601String(),
                    'is_overdue' => $followUp->isOverdue($now),
                    'assignee' => $followUp->assignee?->name,
                    'party' => ['id' => $followUp->party->id, 'name' => $followUp->party->name],
                ], $followUps->items()),
                'links' => $followUps->linkCollection()->toArray(),
                'total' => $followUps->total(),
            ],
            'filters' => ['mine' => $mine, 'done' => $showDone],
        ]);
    }

    public function store(Request $request, Party $party): RedirectResponse
    {
        $this->authorize('view', $party);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:180'],
            'body' => ['nullable', 'string', 'max:2000'],
            'due_at' => ['required', 'date'],
            // Null is legitimate and is the default: a shop-wide reminder belongs to
            // whoever picks it up, not to whoever typed it.
            'assignee_id' => ['nullable', 'integer', 'exists:users,id'],
        ], [
            'title.required' => 'موضوع پیگیری را بنویسید.',
            'due_at.required' => 'تاریخ پیگیری را انتخاب کنید.',
        ]);

        PartyFollowUp::query()->create([
            'party_id' => $party->getKey(),
            'title' => $validated['title'],
            'body' => $validated['body'] ?? null,
            'due_at' => $validated['due_at'],
            'assignee_id' => $validated['assignee_id'] ?? null,
            'created_by' => $request->user()?->id,
        ]);

        return back()->with('success', 'پیگیری ثبت شد.');
    }

    /**
     * Mark done, or reopen.
     */
    public function toggle(Request $request, PartyFollowUp $followUp): RedirectResponse
    {
        $this->authorize('view', $followUp->party);

        $done = $followUp->isDone();

        $followUp->update([
            'done_at' => $done ? null : CarbonImmutable::now(),
            'done_by' => $done ? null : $request->user()?->id,
        ]);

        return back()->with('success', $done ? 'پیگیری دوباره باز شد.' : 'پیگیری انجام‌شده ثبت شد.');
    }

    public function destroy(PartyFollowUp $followUp): RedirectResponse
    {
        $this->authorize('update', $followUp->party);

        $followUp->delete();

        return back()->with('success', 'پیگیری حذف شد.');
    }

    /**
     * Staff a follow-up can be assigned to.
     */
    public function assignees(): \Illuminate\Http\JsonResponse
    {
        $this->authorize('viewAny', Party::class);

        return response()->json([
            'results' => User::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (User $user): array => ['id' => $user->id, 'name' => $user->name])
                ->values()
                ->all(),
        ]);
    }
}
