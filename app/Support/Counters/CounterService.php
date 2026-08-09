<?php

declare(strict_types=1);

namespace App\Support\Counters;

use Illuminate\Database\ConnectionInterface;
use RuntimeException;

/**
 * Hands out the next number in a per-tenant sequence, safely under concurrency.
 *
 * The contract is narrow on purpose: `next()` must be called inside a transaction that
 * also writes the thing being numbered. If it were called on its own, the row lock would
 * release the instant it returned and two callers could still interleave between getting
 * a number and using it.
 *
 * Gaps: a rolled-back transaction leaves a gap, because the counter row rolls back with
 * it. That is the correct trade — the alternative (increment outside the transaction) is
 * gap-free but can issue the same number twice, and a duplicate invoice number is a tax
 * problem where a missing one is a shrug.
 */
final class CounterService
{
    public function __construct(private readonly ConnectionInterface $connection) {}

    /**
     * The next value in the sequence, incremented atomically.
     *
     * @param  int|null  $branchId  the branch this document belongs to; null for
     *                              tenant-level sequences such as subscription invoices
     * @param  string|null  $period  scopes the counter, e.g. a Jalali year for numbers
     *                               that restart annually
     *
     * @throws RuntimeException when called outside a transaction, where the lock would
     *                          be useless
     */
    public function next(int $tenantId, string $key, ?int $branchId = null, ?string $period = null): int
    {
        if ($this->connection->transactionLevel() === 0) {
            throw new RuntimeException(
                "CounterService::next('{$key}') must run inside a transaction — outside one the "
                .'row lock releases immediately and two callers can be handed the same number.'
            );
        }

        // Take the lock first. `firstOrCreate` without it would let two callers both
        // miss the row and both insert, and only one survives the unique index.
        $locked = Counter::query()
            ->where('tenant_id', $tenantId)
            ->where('key', $key)
            ->where('branch_id', $branchId)
            ->where('period', $period)
            ->lockForUpdate()
            ->first();

        if (! $locked instanceof Counter) {
            // First use of this counter. The unique index is the real arbiter if two
            // requests race to create it; the loser re-reads under the lock.
            $locked = Counter::query()->create([
                'tenant_id' => $tenantId,
                'key' => $key,
                'branch_id' => $branchId,
                'period' => $period,
                'value' => 0,
            ]);
        }

        $locked->value++;
        $locked->save();

        return $locked->value;
    }

    /**
     * A formatted document number, e.g. `INV-1405-000042`.
     */
    public function nextFormatted(
        int $tenantId,
        string $key,
        string $prefix,
        ?int $branchId = null,
        ?string $period = null,
        int $pad = 6,
    ): string {
        $number = $this->next($tenantId, $key, $branchId, $period);

        return $period === null
            ? sprintf('%s-%0'.$pad.'d', $prefix, $number)
            : sprintf('%s-%s-%0'.$pad.'d', $prefix, $period, $number);
    }
}
