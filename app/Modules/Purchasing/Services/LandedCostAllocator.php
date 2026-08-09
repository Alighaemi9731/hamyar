<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Services;

use InvalidArgumentException;

/**
 * Spreads freight, customs and courier charges into each unit's cost.
 *
 * Without this, a shop's profit figure is wrong by exactly the shipping bill — and in a
 * market where handsets are imported and customs is a real line item, that is not a
 * rounding error.
 *
 * ## The remainder rule
 *
 * Allocation is integer rial, so a three-way split of 100,000 leaves a remainder. The
 * remainder goes to the **largest** line, which guarantees two things that matter more
 * than fairness:
 *
 * - the allocated amounts sum to the charge **exactly**, so the invoice reconciles;
 * - the distortion lands on the line least affected by it in percentage terms.
 *
 * Dropping the remainder instead would leave the books short by a few rial per invoice,
 * which accumulates into a discrepancy nobody can explain.
 */
final class LandedCostAllocator
{
    public const BY_VALUE = 'by_value';

    public const BY_QUANTITY = 'by_quantity';

    /**
     * Split `$amount` across lines.
     *
     * @param  list<array{id: int|string, value: int, quantity: int}>  $lines
     * @return array<int|string, int> allocation per line id, summing exactly to $amount
     */
    public function allocate(int $amount, array $lines, string $method = self::BY_VALUE): array
    {
        if ($lines === []) {
            throw new InvalidArgumentException('Cannot allocate a landed cost across no lines.');
        }

        if (! in_array($method, [self::BY_VALUE, self::BY_QUANTITY], true)) {
            throw new InvalidArgumentException("Unknown allocation method [{$method}].");
        }

        if ($amount === 0) {
            return array_fill_keys(array_column($lines, 'id'), 0);
        }

        $weights = [];

        foreach ($lines as $line) {
            $weights[$line['id']] = $method === self::BY_VALUE ? $line['value'] : $line['quantity'];
        }

        $total = array_sum($weights);

        // Every weight zero — a zero-value invoice, or lines with no quantity. Falling
        // back to an even split beats dividing by zero or silently allocating nothing.
        if ($total <= 0) {
            $weights = array_fill_keys(array_keys($weights), 1);
            $total = count($weights);
        }

        $allocated = [];
        $running = 0;

        foreach ($weights as $id => $weight) {
            // intdiv truncates, so the sum here is always at or below $amount and the
            // remainder is never negative.
            $share = intdiv($amount * $weight, $total);
            $allocated[$id] = $share;
            $running += $share;
        }

        $remainder = $amount - $running;

        if ($remainder !== 0) {
            $allocated[$this->largestLineId($weights)] += $remainder;
        }

        return $allocated;
    }

    /**
     * Per-unit share for a serialized line, so each handset carries its own portion.
     *
     * Serialized costs are per unit and never averaged (golden rule 4), so the line's
     * allocation is split again across its units — with the same remainder rule, for the
     * same reason.
     *
     * @return list<int> one amount per unit, summing exactly to $lineAllocation
     */
    public function perUnit(int $lineAllocation, int $unitCount): array
    {
        if ($unitCount < 1) {
            throw new InvalidArgumentException('A serialized line needs at least one unit.');
        }

        $base = intdiv($lineAllocation, $unitCount);
        $remainder = $lineAllocation - ($base * $unitCount);
        $shares = [];

        for ($i = 0; $i < $unitCount; $i++) {
            // The remainder lands on the last unit, for the same reason it lands on the
            // largest line above: the shares must sum to the allocation exactly.
            $shares[] = $i === $unitCount - 1 ? $base + $remainder : $base;
        }

        return $shares;
    }

    /**
     * @param  array<int|string, int>  $weights
     */
    private function largestLineId(array $weights): int|string
    {
        /** @var int|string $largestId */
        $largestId = array_key_first($weights);
        $largest = $weights[$largestId];

        foreach ($weights as $id => $weight) {
            if ($weight > $largest) {
                $largest = $weight;
                $largestId = $id;
            }
        }

        return $largestId;
    }
}
