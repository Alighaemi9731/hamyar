<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Models\Branch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Which branches the current user may see data from.
 *
 * The public surface other modules build on. Sales, Repairs and Reporting all need the
 * same question answered — "which branches count for this user?" — and each answering it
 * with its own join is how the rule ends up enforced in four places and forgotten in the
 * fifth.
 *
 * **No assignment rows means every branch.** See the migration for why the empty case is
 * permissive rather than restrictive.
 */
final class BranchAccess
{
    /** @var array<int, list<int>|null> */
    private array $cache = [];

    /**
     * Branch ids this user is restricted to, or null when unrestricted.
     *
     * Null and "all the ids" are NOT interchangeable to callers: null lets a query skip
     * the `whereIn` entirely, which matters on the hot path of a stock list.
     *
     * @return list<int>|null
     */
    public function allowedFor(User $user): ?array
    {
        /** @var int $userId */
        $userId = $user->getKey();

        if (array_key_exists($userId, $this->cache)) {
            return $this->cache[$userId];
        }

        $ids = [];

        foreach (DB::table('branch_user')->where('user_id', $userId)->pluck('branch_id') as $branchId) {
            $ids[] = (int) $branchId; // @phpstan-ignore-line cast.int — pluck() of a bigint column
        }

        return $this->cache[$userId] = $ids === [] ? null : $ids;
    }

    public function canUse(User $user, int $branchId): bool
    {
        $allowed = $this->allowedFor($user);

        return $allowed === null || in_array($branchId, $allowed, true);
    }

    /**
     * Constrain a query to the branches this user may see.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @param  string  $column  qualified when the query joins — `warehouses.branch_id`
     * @return Builder<TModel>
     */
    public function constrain(Builder $query, User $user, string $column = 'branch_id'): Builder
    {
        $allowed = $this->allowedFor($user);

        return $allowed === null ? $query : $query->whereIn($column, $allowed);
    }

    /**
     * The branch a user works in by default: their first assigned one, else the shop's
     * default. Used to preselect the branch on a new invoice or repair ticket.
     */
    public function defaultFor(User $user): ?Branch
    {
        $allowed = $this->allowedFor($user);

        $query = Branch::query()->active();

        if ($allowed !== null) {
            return $query->whereIn('id', $allowed)->orderByDesc('is_default')->orderBy('id')->first();
        }

        return $query->orderByDesc('is_default')->orderBy('id')->first();
    }

    /**
     * Drop the memo — after changing assignments, and in tests.
     */
    public function forget(): void
    {
        $this->cache = [];
    }
}
