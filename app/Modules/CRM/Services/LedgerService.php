<?php

declare(strict_types=1);

namespace App\Modules\CRM\Services;

use App\Modules\CRM\Models\Account;
use App\Modules\CRM\Models\LedgerEntry;
use App\Modules\CRM\Models\Party;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * The only thing that writes `ledger_entries`.
 *
 * Balances are `SUM(debit) - SUM(credit)`, never a stored column (golden rule 3). The
 * reason is the same as for stock: a stored total drifts, and a shop arguing with its
 * own software about what a customer owes stops trusting the software.
 *
 * ## Entries come in balanced sets
 *
 * `post()` takes several lines and refuses unless debits equal credits. Cash received
 * from a customer is two lines — debit the cash account, credit the party — and writing
 * one without the other is how money appears from nowhere. They share a `batch_id`, so a
 * statement can show the event as a unit and a reversal can find every row it must undo.
 *
 * The database enforces the shape of a *row* (one side only, at least one subject); this
 * service enforces the balance of a *set*, which SQL cannot express per-insert.
 */
final class LedgerService
{
    public function __construct(private readonly ConnectionInterface $connection) {}

    /**
     * Write a balanced set of entries.
     *
     * @param  list<array{party_id?: int|null, account_id?: int|null, debit?: int, credit?: int, description?: string|null, branch_id?: int|null}>  $lines
     * @return list<LedgerEntry>
     */
    public function post(
        array $lines,
        ?Model $reference = null,
        ?CarbonImmutable $occurredAt = null,
        ?int $actorId = null,
    ): array {
        $this->guardBalanced($lines);

        $batchId = (string) Str::uuid();
        $occurredAt ??= CarbonImmutable::now();

        /** @var list<LedgerEntry> $entries */
        $entries = $this->connection->transaction(function () use ($lines, $reference, $occurredAt, $actorId, $batchId): array {
            $created = [];

            foreach ($lines as $line) {
                $created[] = LedgerEntry::query()->create([
                    'party_id' => $line['party_id'] ?? null,
                    'account_id' => $line['account_id'] ?? null,
                    'branch_id' => $line['branch_id'] ?? null,
                    'debit' => $line['debit'] ?? 0,
                    'credit' => $line['credit'] ?? 0,
                    'reference_type' => $reference === null ? null : $reference::class,
                    'reference_id' => $reference?->getKey(),
                    'batch_id' => $batchId,
                    'description' => $line['description'] ?? null,
                    'actor_id' => $actorId ?? auth()->id(),
                    'occurred_at' => $occurredAt,
                ]);
            }

            return $created;
        });

        return $entries;
    }

    /**
     * What a party owes, in rial. Positive means they owe the shop.
     *
     * Includes `opening_balance`, because a shop migrating from paper carries a figure
     * in and the statement must start from it rather than from zero.
     */
    public function partyBalance(Party $party, ?CarbonImmutable $at = null): int
    {
        $query = LedgerEntry::query()->where('party_id', $party->getKey());

        if ($at instanceof CarbonImmutable) {
            $query->where('occurred_at', '<=', $at);
        }

        return $party->opening_balance + $this->netOf($query);
    }

    /**
     * Balances for many parties in one query, keyed by party id.
     *
     * The list-screen counterpart to `partyBalance()`, for the same reason
     * `StockLedger::onHandForMany()` exists: a picker showing twelve customers with
     * their balances would otherwise fire twelve aggregate queries per keystroke.
     *
     * Every requested id comes back, including parties with no entries at all — their
     * balance is the opening figure, and a missing key would read as zero somewhere
     * downstream.
     *
     * @param  Collection<int, Party>  $parties
     * @return array<int, int>
     */
    public function partyBalances(Collection $parties): array
    {
        if ($parties->isEmpty()) {
            return [];
        }

        $rows = LedgerEntry::query()
            ->selectRaw('party_id, coalesce(sum(debit), 0) - coalesce(sum(credit), 0) as net')
            ->whereIn('party_id', $parties->modelKeys())
            ->groupBy('party_id')
            ->get();

        $net = [];

        foreach ($rows as $row) {
            /** @var int|numeric-string $partyId */
            $partyId = $row->getAttribute('party_id');
            /** @var int|numeric-string $value */
            $value = $row->getAttribute('net');

            $net[(int) $partyId] = (int) $value;
        }

        $balances = [];

        foreach ($parties as $party) {
            /** @var int|numeric-string $id */
            $id = $party->getKey();

            // A party with no entries is absent from the group-by; its balance is the
            // opening figure, which is not the same statement as "unknown".
            $balances[(int) $id] = $party->opening_balance + ($net[(int) $id] ?? 0);
        }

        return $balances;
    }

    /**
     * What is in an account, in rial.
     */
    public function accountBalance(Account $account, ?CarbonImmutable $at = null): int
    {
        $query = LedgerEntry::query()->where('account_id', $account->getKey());

        if ($at instanceof CarbonImmutable) {
            $query->where('occurred_at', '<=', $at);
        }

        return $account->opening_balance + $this->netOf($query);
    }

    /**
     * A party's statement: every entry with a running balance.
     *
     * The running figure is computed here rather than in SQL so it starts from the
     * opening balance and so the closing figure is arithmetically the same number
     * `partyBalance()` returns — a statement whose total disagrees with the balance
     * shown elsewhere is worse than no statement.
     *
     * @return array{opening: int, closing: int, rows: list<array{entry: LedgerEntry, balance: int}>}
     */
    public function statement(Party $party, ?CarbonImmutable $from = null, ?CarbonImmutable $to = null): array
    {
        $query = LedgerEntry::query()
            ->where('party_id', $party->getKey())
            ->orderBy('occurred_at')
            ->orderBy('id');

        // Anything before the window is folded into the opening figure, so the first row
        // continues from a real number rather than from zero.
        $opening = $party->opening_balance;

        if ($from instanceof CarbonImmutable) {
            $opening += $this->netOf(
                LedgerEntry::query()->where('party_id', $party->getKey())->where('occurred_at', '<', $from)
            );

            $query->where('occurred_at', '>=', $from);
        }

        if ($to instanceof CarbonImmutable) {
            $query->where('occurred_at', '<=', $to);
        }

        $balance = $opening;
        $rows = [];

        foreach ($query->get() as $entry) {
            $balance += $entry->signedAmount();
            $rows[] = ['entry' => $entry, 'balance' => $balance];
        }

        return ['opening' => $opening, 'closing' => $balance, 'rows' => $rows];
    }

    /**
     * Undo a batch by writing its mirror image.
     *
     * Never deletes. The original event and its reversal both stay on the statement,
     * which is the difference between a ledger and a spreadsheet.
     *
     * @return list<LedgerEntry>
     */
    public function reverse(string $batchId, ?string $reason = null): array
    {
        $original = LedgerEntry::query()->where('batch_id', $batchId)->get();

        if ($original->isEmpty()) {
            throw new InvalidArgumentException("No ledger batch [{$batchId}] to reverse.");
        }

        /** @var list<array{party_id?: int|null, account_id?: int|null, debit?: int, credit?: int, description?: string|null, branch_id?: int|null}> $lines */
        $lines = $original->map(static fn (LedgerEntry $entry): array => [
            'party_id' => $entry->party_id,
            'account_id' => $entry->account_id,
            'branch_id' => $entry->branch_id,
            // Swapped: the mirror image.
            'debit' => $entry->credit,
            'credit' => $entry->debit,
            'description' => $reason ?? 'برگشت سند',
        ])->values()->all();

        return $this->post($lines);
    }

    /**
     * Whether a credit sale would push a party past its limit.
     *
     * Returns a warning, not a verdict. The spec is explicit that this is a warning with
     * an override permission rather than a hard block: refusing a regular customer over
     * a limit nobody remembers setting loses the shop money.
     *
     * @return array{exceeds: bool, limit: int|null, balance: int, after: int}
     */
    public function creditCheck(Party $party, int $additionalDebit): array
    {
        $balance = $this->partyBalance($party);
        $after = $balance + $additionalDebit;

        return [
            'exceeds' => $party->credit_limit !== null && $after > $party->credit_limit,
            'limit' => $party->credit_limit,
            'balance' => $balance,
            'after' => $after,
        ];
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<LedgerEntry>  $query
     */
    private function netOf(\Illuminate\Database\Eloquent\Builder $query): int
    {
        $row = $query->selectRaw('coalesce(sum(debit), 0) - coalesce(sum(credit), 0) as net')->first();

        if ($row === null) {
            return 0;
        }

        /** @var int|numeric-string|null $net */
        $net = $row->getAttribute('net');

        return (int) ($net ?? 0);
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    private function guardBalanced(array $lines): void
    {
        if (count($lines) < 2) {
            throw new InvalidArgumentException(
                'A ledger posting needs at least two lines — money always comes from somewhere.'
            );
        }

        $debits = 0;
        $credits = 0;

        foreach ($lines as $line) {
            /** @var int $debit */
            $debit = $line['debit'] ?? 0;
            /** @var int $credit */
            $credit = $line['credit'] ?? 0;

            if ($debit < 0 || $credit < 0) {
                throw new InvalidArgumentException('Ledger amounts are unsigned; use the other column instead.');
            }

            $debits += $debit;
            $credits += $credit;
        }

        if ($debits !== $credits) {
            throw new InvalidArgumentException(
                "Ledger posting does not balance: debits {$debits}, credits {$credits}."
            );
        }

        if ($debits === 0) {
            throw new InvalidArgumentException('A ledger posting of zero records nothing.');
        }
    }
}
