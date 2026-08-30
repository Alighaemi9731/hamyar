<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use App\Modules\Inventory\Models\StockCount;
use App\Modules\Inventory\Models\StockCountItem;
use App\Support\Quota\QuotaGuard;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use RuntimeException;

/**
 * Running an انبارگردانی.
 *
 * A count records what was *found* and turns the difference into adjustment movements.
 * It never sets a total — so "we were three short in Mordad" stays answerable and the
 * shrinkage exists as a number someone can be asked about.
 *
 * Blind by default: the expected figure is hidden from whoever is counting, because a
 * number on the screen is a number people count towards. A count that agrees with the
 * system because the counter could see the system has measured nothing.
 */
final class StockCountService
{
    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly StockLedger $stock,
        private readonly QuotaGuard $quota,
    ) {}

    /**
     * Add a variant to the sheet, snapshotting what the system currently believes.
     */
    public function addLine(StockCount $count, int $variantId): StockCountItem
    {
        if (! $count->isOpen()) {
            throw new RuntimeException("Stock count {$count->number} is closed.");
        }

        /** @var StockCountItem $item */
        $item = StockCountItem::query()->create([
            'stock_count_id' => $count->getKey(),
            'product_variant_id' => $variantId,
            // Snapshotted now, so the variance is measured against what was believed at
            // count time rather than a figure that moved while counting was happening.
            'expected_quantity' => $this->stock->onHand($variantId, $count->warehouse_id),
        ]);

        return $item;
    }

    /**
     * Turn the counted figures into adjustment movements.
     *
     * Lines left uncounted are skipped, not treated as zero. An unvisited shelf is not
     * an empty shelf, and writing off stock because nobody got to it would be the single
     * most damaging thing this feature could do.
     *
     * @return int number of adjustment movements written
     */
    public function apply(StockCount $count, ?CarbonImmutable $at = null): int
    {
        if (! $count->isOpen()) {
            throw new RuntimeException("Stock count {$count->number} has already been {$count->status}.");
        }

        $at ??= CarbonImmutable::now();

        /** @var int $written */
        $written = $this->connection->transaction(function () use ($count, $at): int {
            $count->load('items');

            // Metered when the count is APPLIED — the moment it changes the books. An
            // open count is a clipboard, and a shop should be able to walk the shelves
            // without spending anything.
            $this->quota->consume('inventory.stock_counts');

            $adjustments = 0;

            foreach ($count->items as $item) {
                if ($item->counted_quantity === null) {
                    continue;
                }

                $movement = $this->stock->reconcileTo(
                    $item->product_variant_id,
                    $count->warehouse_id,
                    $item->counted_quantity,
                    reference: $count,
                    note: "انبارگردانی {$count->number}",
                );

                if ($movement !== null) {
                    $adjustments++;
                }
            }

            $count->update([
                'status' => StockCount::STATUS_APPLIED,
                'applied_at' => $at,
            ]);

            return $adjustments;
        });

        return $written;
    }

    /**
     * Total shrinkage across the sheet, in units. Negative means stock is missing.
     */
    public function variance(StockCount $count): int
    {
        return (int) $count->items
            ->filter(fn (StockCountItem $item): bool => $item->counted_quantity !== null)
            ->sum(fn (StockCountItem $item): int => $item->variance() ?? 0);
    }
}
