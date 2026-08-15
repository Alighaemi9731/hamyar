<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Http\Requests\BranchRequest;
use App\Modules\Inventory\Models\Branch;
use App\Modules\Inventory\Services\BranchAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Branches, and who works at each one.
 *
 * ## Why this screen had to exist before the audit could finish
 *
 * `branch_user` has existed since Phase 2, `BranchAccess` reads it, and Sales, Repairs and
 * Inventory enforce it — but **nothing ever wrote to it**, and there was no way to create a
 * second branch either. A shop could not reach the multi-branch behaviour the code was
 * carefully implementing. Roadmap 10.1 reads "every module respects branch context"; the
 * honest first half is that branch context had no on-ramp.
 *
 * ## Assignment lives here, not on the user screen
 *
 * "Who works at this branch" rather than "which branches does this user have". The UX
 * argument is that a shop thinks in branches when it is staffing one; the structural
 * argument is golden rule 6 — `branch_user` is Inventory's table, and putting the control
 * on Identity's user screen would make Identity import an Inventory model.
 *
 * ## An empty assignment list means every branch, and the screen says so
 *
 * That default is in the migration and it is the right one — a single-branch shop must not
 * have to assign anybody to anything — but it reads as a bug on a screen showing zero
 * users. So the empty state states the rule rather than showing an empty list.
 */
final class BranchController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorise($request, 'settings.view');

        $branches = Branch::query()
            ->with('warehouses:id,branch_id,name,is_active')
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get();

        $assignments = $this->assignments();

        return Inertia::render('Inventory::Branches/Index', [
            'branches' => $branches->map(fn (Branch $branch): array => [
                'id' => $this->idOf($branch),
                'name' => $branch->name,
                'code' => $branch->code,
                'phone' => $branch->phone,
                'address' => $branch->address,
                'is_default' => $branch->is_default,
                'is_active' => $branch->is_active,
                'warehouses' => $branch->warehouses
                    ->map(fn ($warehouse): array => ['id' => $warehouse->id, 'name' => $warehouse->name])
                    ->values()
                    ->all(),
                'user_ids' => $assignments[$this->idOf($branch)] ?? [],
            ])->values()->all(),

            'users' => User::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (User $user): array => ['id' => $this->idOf($user), 'name' => $user->name])
                ->values()
                ->all(),

            'can_manage' => $request->user() instanceof User && $request->user()->can('settings.update'),
        ]);
    }

    public function store(BranchRequest $request): RedirectResponse
    {
        $this->authorise($request, 'settings.update');

        $branch = new Branch;

        $this->fill($branch, $request);
        $branch->save();

        $this->settleDefault($branch, $request->boolean('is_default'));

        return back()->with('success', 'شعبه ثبت شد.');
    }

    public function update(BranchRequest $request, Branch $branch): RedirectResponse
    {
        $this->authorise($request, 'settings.update');

        $this->fill($branch, $request);

        /*
        | The default branch cannot be deactivated. Every document that reaches
        | finalisation with no branch of its own falls back to it, so switching it off
        | would leave those documents with nowhere to go — and the failure would surface
        | at the till, mid-sale, rather than here.
        */
        if ($branch->is_default) {
            $branch->is_active = true;
        }

        $branch->save();

        $this->settleDefault($branch, $request->boolean('is_default'));

        return back()->with('success', 'شعبه به‌روزرسانی شد.');
    }

    /**
     * Replace a branch's staff list.
     *
     * A whole-list replace rather than add/remove calls: the screen presents it as a set of
     * checkboxes, and a partial update would let two people editing the same branch each
     * silently undo the other's removals.
     */
    public function assign(Request $request, Branch $branch): RedirectResponse
    {
        $this->authorise($request, 'settings.update');

        $validated = $request->validate([
            // `array` without `present`: unticking every box sends no key at all, and
            // "nobody is pinned to this branch" is a legitimate instruction — it is how a
            // shop undoes a restriction.
            'user_ids' => ['nullable', 'array'],
            'user_ids.*' => ['integer', 'exists:users,id'],
        ]);

        /** @var list<int> $ids */
        $ids = array_map('intval', $validated['user_ids'] ?? []);

        $branch->users()->sync($ids);

        // The memo is per-request, but a queued job or a test that reuses the container
        // would otherwise answer from a list that is now wrong.
        app(BranchAccess::class)->forget();

        return back()->with('success', 'کارکنان شعبه به‌روزرسانی شد.');
    }

    private function fill(Branch $branch, BranchRequest $request): void
    {
        $branch->fill([
            'name' => $request->string('name')->value(),
            'code' => strtoupper($request->string('code')->value()),
            'phone' => $request->string('phone')->value() ?: null,
            'address' => $request->string('address')->value() ?: null,
            'is_active' => $request->boolean('is_active', true),
        ]);
    }

    /**
     * Move the default flag, in a transaction, or leave it alone.
     *
     * A partial unique index enforces one default per tenant, so clearing the old one and
     * setting the new one must happen together — two statements outside a transaction leave
     * a window with zero defaults, and the second can fail leaving none at all.
     */
    private function settleDefault(Branch $branch, bool $wantsDefault): void
    {
        if (! $wantsDefault || $branch->is_default) {
            return;
        }

        DB::transaction(function () use ($branch): void {
            Branch::query()->where('is_default', true)->update(['is_default' => false]);

            $branch->forceFill(['is_default' => true, 'is_active' => true])->save();
        });
    }

    /**
     * Branch id => the user ids pinned to it.
     *
     * One query for the whole screen. The relation per branch would be a query per row,
     * which is the shape golden rule "no N+1" exists for.
     *
     * @return array<int, list<int>>
     */
    private function assignments(): array
    {
        $map = [];

        foreach (DB::table('branch_user')->get(['branch_id', 'user_id']) as $row) {
            $values = (array) $row;

            $branchId = is_numeric($values['branch_id'] ?? null) ? (int) $values['branch_id'] : 0;
            $userId = is_numeric($values['user_id'] ?? null) ? (int) $values['user_id'] : 0;

            $map[$branchId][] = $userId;
        }

        return $map;
    }

    /**
     * A model's primary key as an int. `getKey()` is `mixed` and level 8 will not cast it
     * blind.
     */
    private function idOf(Branch|User $model): int
    {
        /** @var int|numeric-string $key */
        $key = $model->getKey();

        return (int) $key;
    }

    private function authorise(Request $request, string $permission): void
    {
        $user = $request->user();

        abort_unless($user instanceof User && $user->can($permission), 403);
    }
}
