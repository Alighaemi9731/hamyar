<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Services;

use App\Modules\CRM\Models\Account;
use App\Modules\CRM\Models\LedgerEntry;
use Carbon\CarbonImmutable;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * What went through this till, bank account or terminal, in order, with a running balance.
 *
 * ## The running balance is computed from the opening figure forward, not per row
 *
 * A statement whose rows each carry their own `SUM(...)` subquery is a page that takes
 * seconds and a query plan nobody wants to read. The opening balance for the page is one
 * aggregate over everything before it; every row after that is arithmetic in PHP.
 *
 * That ordering matters more than performance, though. **The running balance must be
 * computed in the same order the statement is displayed in**, or the last row's balance
 * will not equal the account balance and a shopkeeper checking the bottom line against the
 * treasury page will find they disagree. So the query orders by `occurred_at` then `id` —
 * `id` because two entries on the same day (a sale and its change, a transfer's two legs)
 * would otherwise come back in whatever order the planner felt like, and the running
 * balance would shuffle between page loads.
 *
 * ## Paging backwards, reading forwards
 *
 * A shopkeeper opens a statement to see what happened recently, so the newest page comes
 * first. But a running balance only makes sense read oldest-to-newest. The resolution is
 * to page in descending order and reverse within the page, so page 1 shows the most recent
 * entries with balances that still add up downward.
 */
final class AccountStatement
{
    public function __construct(private readonly AccountBalances $balances) {}

    /**
     * One page of an account's history, newest first, each row carrying the balance after it.
     *
     * @return array{opening: int, closing: int, entries: LengthAwarePaginator<int, LedgerEntry>}
     */
    public function for(
        Account $account,
        ?CarbonImmutable $from = null,
        ?CarbonImmutable $to = null,
        int $perPage = 50,
    ): array {
        $query = LedgerEntry::query()
            ->with('actor:id,name')
            ->where('account_id', $account->id)
            ->when($from !== null, fn ($q) => $q->where('occurred_at', '>=', $from))
            ->when($to !== null, fn ($q) => $q->where('occurred_at', '<=', $to))
            ->orderByDesc('occurred_at')
            ->orderByDesc('id');

        /** @var LengthAwarePaginator<int, LedgerEntry> $entries */
        $entries = $query->paginate($perPage)->withQueryString();

        // Everything that happened before this window: the balance the statement starts
        // from. Without it the first row's running balance would begin at zero and the
        // whole column would be wrong by the account's entire history.
        $opening = $from === null
            ? $account->opening_balance
            : $this->balances->balanceOf($account, $from->subSecond());

        $closing = $this->balances->balanceOf($account, $to);

        $this->attachRunningBalance($entries, $closing);

        return ['opening' => $opening, 'closing' => $closing, 'entries' => $entries];
    }

    /**
     * Walk the page from the newest row backwards, subtracting as we go.
     *
     * The newest row on page 1 sits at the closing balance by definition. Each earlier
     * row is the one after it, less that row's own effect — which is why this runs
     * backwards rather than forwards from the opening figure: on page 3 of a statement,
     * "the balance before this page" would need its own aggregate, and the closing
     * balance is already known.
     *
     * @param  LengthAwarePaginator<int, LedgerEntry>  $entries
     */
    private function attachRunningBalance(LengthAwarePaginator $entries, int $closing): void
    {
        // On page 2 and beyond, the running balance starts below the account's closing
        // figure by everything on the pages above.
        $running = $closing - $this->effectAfter($entries);

        foreach ($entries->items() as $entry) {
            $entry->setAttribute('running_balance', $running);
            $running -= ($entry->debit - $entry->credit);
        }
    }

    /**
     * The net effect of every entry newer than this page — zero on page 1.
     *
     * @param  LengthAwarePaginator<int, LedgerEntry>  $entries
     */
    private function effectAfter(LengthAwarePaginator $entries): int
    {
        if ($entries->currentPage() <= 1 || $entries->isEmpty()) {
            return 0;
        }

        /** @var LedgerEntry $newestOnPage */
        $newestOnPage = $entries->items()[0];

        $effect = LedgerEntry::query()
            ->where('account_id', $newestOnPage->account_id)
            ->where(function ($query) use ($newestOnPage): void {
                $query->where('occurred_at', '>', $newestOnPage->occurred_at)
                    ->orWhere(function ($same) use ($newestOnPage): void {
                        $same->where('occurred_at', $newestOnPage->occurred_at)
                            ->where('id', '>', $newestOnPage->id);
                    });
            })
            ->selectRaw('coalesce(sum(debit), 0) - coalesce(sum(credit), 0) as effect')
            ->value('effect');

        return is_numeric($effect) ? (int) $effect : 0;
    }
}
