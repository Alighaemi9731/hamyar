<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Services;

use App\Modules\CRM\Models\Account;
use App\Modules\CRM\Models\LedgerEntry;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use RuntimeException;

/**
 * Ticking entries off against a bank or card statement.
 *
 * ## What this is and is not
 *
 * It is not an edit. `ledger_entries` is append-only about money, and nothing here touches
 * a debit or a credit. Reconciling records that **a person looked at a bank statement and
 * agreed** — a fact about the paper trail, not about the books.
 *
 * That is why un-ticking is allowed. Somebody ticks the wrong line, notices, and unticks
 * it; refusing would leave a false assertion in place permanently, which is worse than
 * letting them correct it. Money entries are corrected by writing the opposite entry
 * because a reversal is itself a financial event worth recording. A mis-tick is not.
 *
 * ## Why per-entry and not per-balance
 *
 * When the bank says 41,300,000 and the software says 44,800,000, "the balance is wrong" is
 * not an answer anybody can act on. "These three entries are the ones nobody has confirmed"
 * is. The 3,500,000 gap is then a list of rows a shopkeeper can look at one at a time.
 *
 * ## Only where money actually sits
 *
 * Reconciliation is refused on a sales, expense, income or inventory account. Those are
 * headings, not places — there is no external statement to tick them against, and offering
 * the action implies one exists.
 */
final class ReconcileEntries
{
    public function __construct(private readonly ConnectionInterface $connection) {}

    /**
     * Mark entries as seen on a statement.
     *
     * @param  list<int>  $entryIds
     * @return int how many were newly ticked
     */
    public function reconcile(Account $account, array $entryIds, int $actorId, ?CarbonImmutable $at = null): int
    {
        $this->guardReconcilable($account);

        if ($entryIds === []) {
            return 0;
        }

        $at ??= CarbonImmutable::now();

        $ticked = $this->connection->transaction(fn (): int => LedgerEntry::query()
            ->where('account_id', $account->id)
            ->whereIn('id', $entryIds)
            // Already-ticked rows are skipped rather than re-stamped: the timestamp
            // records when somebody FIRST agreed, and overwriting it on a second pass
            // loses the only date that answers "when did we last actually check?".
            ->whereNull('reconciled_at')
            ->update(['reconciled_at' => $at, 'reconciled_by' => $actorId]));

        return is_numeric($ticked) ? (int) $ticked : 0;
    }

    /**
     * Undo a tick.
     *
     * @param  list<int>  $entryIds
     */
    public function unreconcile(Account $account, array $entryIds): int
    {
        $this->guardReconcilable($account);

        if ($entryIds === []) {
            return 0;
        }

        $unticked = $this->connection->transaction(fn (): int => LedgerEntry::query()
            ->where('account_id', $account->id)
            ->whereIn('id', $entryIds)
            ->whereNotNull('reconciled_at')
            ->update(['reconciled_at' => null, 'reconciled_by' => null]));

        return is_numeric($unticked) ? (int) $unticked : 0;
    }

    /**
     * The entries nobody has confirmed yet, oldest first.
     *
     * Oldest first deliberately: the entry that has been sitting unticked the longest is
     * the one most likely to be the problem, and it is the one a shopkeeper wants at the
     * top rather than buried under this morning's sales.
     *
     * @return list<LedgerEntry>
     */
    public function unreconciled(Account $account): array
    {
        return array_values(
            LedgerEntry::query()
                ->where('account_id', $account->id)
                ->whereNull('reconciled_at')
                ->orderBy('occurred_at')
                ->orderBy('id')
                ->get()
                ->all()
        );
    }

    private function guardReconcilable(Account $account): void
    {
        if (! $account->holdsMoney()) {
            throw new RuntimeException(
                'مغایرت‌گیری فقط برای صندوق، بانک و کارتخوان معنی دارد.'
            );
        }
    }
}
