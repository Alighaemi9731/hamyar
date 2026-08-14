<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Services;

use App\Modules\CRM\Models\Account;
use App\Modules\CRM\Models\LedgerEntry;
use Carbon\CarbonImmutable;

/**
 * What is actually in each till, bank account and card terminal.
 *
 * ## A SUM, never a column
 *
 * Golden rule 3, and the reason is the same one it always is: a stored balance drifts.
 * One failed job, one exception between the write and the update, and the number on the
 * screen stops matching the entries beneath it. A shop that catches its software
 * disagreeing with itself about money stops trusting all of it, including the parts that
 * were right.
 *
 * `opening_balance` is the one stored figure, and it is not a running total — it is the
 * amount that was in the drawer on the day the shop started using the software. A shop
 * migrating from paper carries a number in, and a statement that began at zero would be
 * wrong from its first line.
 *
 * ## Sign, from the shop's point of view
 *
 * A debit to a cash account means money went IN. Positive balance = the shop holds this
 * much. That is the opposite of the party convention — where positive means the party
 * owes the shop — and the asymmetry is not sloppiness: an asset account and a
 * receivable are different kinds of thing, and forcing one sign rule onto both is how a
 * transfer ends up doubling a balance instead of moving it.
 *
 * ## Reconciliation is a fact about a row, not a balance
 *
 * "I have seen this on the bank statement" is something a person asserts about a
 * particular entry. Storing it as a per-row timestamp means the unreconciled figure is a
 * SUM over the rows nobody has ticked yet, which is exactly the number a shopkeeper is
 * looking for when the bank says one thing and the software says another.
 */
final class AccountBalances
{
    /**
     * The balance of one account, optionally as at a moment.
     */
    public function balanceOf(Account $account, ?CarbonImmutable $at = null): int
    {
        return $account->opening_balance + $this->movementOf($account->id, $at);
    }

    /**
     * Balances for every active account, keyed by account id.
     *
     * One query rather than one per account: the treasury index shows every till, bank
     * and terminal at once, and a shop with eight of them should not pay eight
     * aggregates for a page load.
     *
     * @param  list<int>|null  $accountIds  null for all of them
     * @return array<int, int>
     */
    public function balances(?array $accountIds = null, ?CarbonImmutable $at = null): array
    {
        $accounts = Account::query()
            ->when($accountIds !== null, fn ($query) => $query->whereIn('id', $accountIds))
            ->get(['id', 'opening_balance']);

        if ($accounts->isEmpty()) {
            return [];
        }

        $movements = LedgerEntry::query()
            ->whereIn('account_id', $accounts->pluck('id'))
            ->when($at !== null, fn ($query) => $query->where('occurred_at', '<=', $at))
            ->groupBy('account_id')
            ->selectRaw('account_id, coalesce(sum(debit), 0) - coalesce(sum(credit), 0) as movement')
            ->toBase()
            ->get();

        $byAccount = [];

        foreach ($movements as $row) {
            $values = (array) $row;
            $id = $values['account_id'] ?? null;
            $movement = $values['movement'] ?? 0;

            if (is_numeric($id)) {
                $byAccount[(int) $id] = is_numeric($movement) ? (int) $movement : 0;
            }
        }

        $balances = [];

        foreach ($accounts as $account) {
            $balances[(int) $account->id] = (int) $account->opening_balance + ($byAccount[(int) $account->id] ?? 0);
        }

        return $balances;
    }

    /**
     * What has not been ticked off against a bank or card statement yet.
     *
     * The figure a shopkeeper needs when the bank disagrees with the software: not "the
     * balance is wrong" but "these four entries are the ones nobody has confirmed".
     */
    public function unreconciledTotal(Account $account): int
    {
        return $this->movementOf($account->id, null, unreconciledOnly: true);
    }

    private function movementOf(int $accountId, ?CarbonImmutable $at, bool $unreconciledOnly = false): int
    {
        $movement = LedgerEntry::query()
            ->where('account_id', $accountId)
            ->when($at !== null, fn ($query) => $query->where('occurred_at', '<=', $at))
            ->when($unreconciledOnly, fn ($query) => $query->whereNull('reconciled_at'))
            ->selectRaw('coalesce(sum(debit), 0) - coalesce(sum(credit), 0) as movement')
            ->value('movement');

        return is_numeric($movement) ? (int) $movement : 0;
    }
}
