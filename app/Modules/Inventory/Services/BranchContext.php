<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Models\Branch;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\Eloquent\Builder;

/**
 * Which branch the user is *looking at* — as distinct from which branches they *may* see.
 *
 * ## Two questions, and conflating them is the bug this class exists to prevent
 *
 * {@see BranchAccess} answers a **permission** question: which branches is this user
 * allowed to see data from? It comes from `branch_user`, an empty assignment means every
 * branch, and the user cannot change it.
 *
 * This class answers a **view** question: of the branches they are allowed, which one are
 * they looking at right now? It comes from the session, «همه شعب» is a legitimate answer,
 * and the user changes it whenever they like.
 *
 * The distinction matters because the obvious implementation — one "current branch"
 * that the switcher writes and every query reads — turns «همه شعب» into a
 * privilege-escalation button: a user restricted to Branch A selects "all", the filter is
 * dropped, and they read Branch B. So {@see apply()} composes them in one fixed order:
 *
 * 1. **The access floor, always.** Even when the view is consolidated.
 * 2. **The view lens, when one branch is selected.**
 *
 * Step 1 is not skippable and does not consult the session. A caller cannot ask for
 * "everything" — the widest thing they can ask for is everything *they are allowed*.
 *
 * ## The session holds an id, and it is re-validated on every read
 *
 * Not a Branch object: sessions outlive deployments, and a serialized model is a stale
 * row waiting to be trusted. And not trusted on read either — a user's assignments can be
 * narrowed by an owner while they are still logged in, and the branch pinned in their
 * session an hour ago may no longer be theirs. {@see current()} checks it against
 * `BranchAccess` every time and quietly falls back to consolidated, which the access floor
 * then narrows correctly.
 *
 * ## A user with exactly one branch has no switcher and no choice
 *
 * `options()` returns their one branch and `canConsolidate()` is false, so the UI renders
 * a label rather than a control. Offering "همه شعب" to somebody for whom it means the same
 * thing as their only branch is a control that does nothing, which reads as broken.
 */
final class BranchContext
{
    private const SESSION_KEY = 'branch.current';

    public function __construct(
        private readonly Session $session,
        private readonly BranchAccess $access,
    ) {}

    /**
     * The branch being viewed, or null for consolidated.
     *
     * Null is returned both for "the user chose all branches" and for "the pinned branch
     * is no longer theirs". Those are the same instruction to a query — apply the access
     * floor and no lens — and distinguishing them would only tempt a caller to treat one
     * of them as unrestricted.
     */
    public function current(?User $user = null): ?int
    {
        $user ??= $this->user();

        if (! $user instanceof User) {
            return null;
        }

        $pinned = $this->session->get(self::SESSION_KEY);

        if (! is_int($pinned)) {
            return $this->onlyBranchFor($user);
        }

        // Re-validated every read: an owner can narrow somebody's assignments while they
        // are still logged in, and a session written before that is not a permission.
        if (! $this->access->canUse($user, $pinned)) {
            return $this->onlyBranchFor($user);
        }

        return $pinned;
    }

    /**
     * Pin a branch, or null for consolidated.
     *
     * Refuses a branch the user may not see rather than silently ignoring it: a switcher
     * that appears to change nothing is worse than one that says no.
     */
    public function set(?int $branchId, ?User $user = null): bool
    {
        $user ??= $this->user();

        if (! $user instanceof User) {
            return false;
        }

        if ($branchId === null) {
            // Consolidated is only offered to somebody who has more than one branch to
            // consolidate; for anybody else it is a no-op wearing a choice.
            if (! $this->canConsolidate($user)) {
                return false;
            }

            $this->session->forget(self::SESSION_KEY);

            return true;
        }

        if (! $this->access->canUse($user, $branchId)) {
            return false;
        }

        $this->session->put(self::SESSION_KEY, $branchId);

        return true;
    }

    /**
     * The branches this user may switch between, in the order the switcher lists them.
     *
     * @return list<Branch>
     */
    public function options(?User $user = null): array
    {
        $user ??= $this->user();

        if (! $user instanceof User) {
            return [];
        }

        $query = Branch::query()->where('is_active', true);
        $allowed = $this->access->allowedFor($user);

        if ($allowed !== null) {
            $query->whereIn('id', $allowed);
        }

        return array_values($query->orderByDesc('is_default')->orderBy('name')->get()->all());
    }

    public function canConsolidate(?User $user = null): bool
    {
        return count($this->options($user)) > 1;
    }

    /**
     * Constrain a query to what this user may see, then to what they are looking at.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @param  string  $column  qualified when the query joins — `warehouses.branch_id`
     * @param  bool  $includeUnassigned  keep rows whose branch is NULL. Some documents
     *                                   legitimately belong to no branch — a party, a
     *                                   shop-wide ledger correction — and dropping them
     *                                   from a per-branch view would make the parts stop
     *                                   summing to the whole
     * @return Builder<TModel>
     */
    public function apply(
        Builder $query,
        string $column = 'branch_id',
        ?User $user = null,
        bool $includeUnassigned = false,
    ): Builder {
        $user ??= $this->user();

        if (! $user instanceof User) {
            return $query;
        }

        /*
        | 1 — the floor. Always, and without consulting the session.
        |
        | Written out rather than delegated to `BranchAccess::constrain()` because
        | `$includeUnassigned` has to reach it. The first version applied the flag to the
        | lens only, and the effect was that a branch cashier could not see the shop's own
        | unassigned paper *at all* — the floor's `whereIn` silently drops NULL, so a
        | cheque belonging to no branch was invisible to every restricted user while
        | remaining visible to unrestricted ones. A row with no branch belongs to everyone
        | or to no one; it cannot belong to whoever happens to be unassigned.
        */
        $allowed = $this->access->allowedFor($user);

        if ($allowed !== null) {
            $query = $includeUnassigned
                ? $query->where(fn (Builder $q): Builder => $q->whereIn($column, $allowed)->orWhereNull($column))
                : $query->whereIn($column, $allowed);
        }

        // 2 — the lens, only when a branch is pinned.
        $current = $this->current($user);

        if ($current === null) {
            return $query;
        }

        return $includeUnassigned
            ? $query->where(fn (Builder $q): Builder => $q->where($column, $current)->orWhereNull($column))
            : $query->where($column, $current);
    }

    /**
     * The branch filter as a list of ids, or null for "no restriction at all".
     *
     * The reporting counterpart to {@see apply()}. Reports build their queries with the
     * query builder rather than Eloquent and compose several of them per screen, so they
     * take the *answer* rather than a builder to decorate.
     *
     * Three cases, and the middle one is the reason this returns a list rather than an id:
     *
     * | user is…                    | viewing…      | result        |
     * |-----------------------------|---------------|---------------|
     * | unrestricted                | consolidated  | `null`        |
     * | anyone                      | one branch    | `[id]`        |
     * | restricted to A and B       | consolidated  | `[a, b]`      |
     *
     * A `?int` parameter cannot express the third row, which is exactly the case a
     * multi-branch shop with a regional manager is made of. Before this existed the report
     * controllers passed nothing at all, so that manager's «همه شعب» meant every branch in
     * the shop — the reports were the one place the access floor was not applied.
     *
     * @return list<int>|null
     */
    public function scopeIds(?User $user = null): ?array
    {
        $user ??= $this->user();

        if (! $user instanceof User) {
            return null;
        }

        $current = $this->current($user);

        if ($current !== null) {
            return [$current];
        }

        // Consolidated: the floor is whatever they are allowed, and null means everything.
        return $this->access->allowedFor($user);
    }

    /**
     * The shape the frontend switcher renders from.
     *
     * @return array{current: int|null, can_consolidate: bool, options: list<array{id: int, name: string}>}
     */
    public function toArray(?User $user = null): array
    {
        $user ??= $this->user();

        $options = [];

        foreach ($this->options($user) as $branch) {
            /** @var int|numeric-string $id */
            $id = $branch->getKey();

            $options[] = ['id' => (int) $id, 'name' => $branch->name];
        }

        return [
            'current' => $this->current($user),
            'can_consolidate' => count($options) > 1,
            'options' => $options,
        ];
    }

    /**
     * A user allowed exactly one branch is always looking at it.
     *
     * Returning their branch rather than null keeps every downstream figure — a counter,
     * a report heading, a print footer — naming the branch the document actually belongs
     * to, instead of reading as consolidated for a shop that has nothing to consolidate.
     */
    private function onlyBranchFor(User $user): ?int
    {
        $options = $this->options($user);

        if (count($options) !== 1) {
            return null;
        }

        /** @var int|numeric-string $id */
        $id = $options[0]->getKey();

        return (int) $id;
    }

    private function user(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }
}
