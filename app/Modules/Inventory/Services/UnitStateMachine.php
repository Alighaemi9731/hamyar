<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use App\Modules\Inventory\Enums\UnitStatus;
use App\Modules\Inventory\Exceptions\IllegalUnitTransition;
use App\Modules\Inventory\Models\ProductUnit;
use App\Modules\Inventory\Models\ProductUnitHistory;
use App\Support\Quota\QuotaGuard;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * The only way a unit's status changes.
 *
 * Two guarantees, and they are the reason this is a service rather than a setter:
 *
 * 1. **Illegal transitions are refused.** `sold → in_stock` in particular: undoing a sale
 *    is a *return*, which produces a `returned` unit and a credit document, because the
 *    money has to move back too. A silent status flip loses that entirely and is how the
 *    same phone gets sold twice.
 * 2. **Every transition writes history.** The IMEI passport is only as good as its worst
 *    gap, and a transition with no history row is exactly such a gap. Status and history
 *    are written in one transaction so neither can exist without the other.
 */
final class UnitStateMachine
{
    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly QuotaGuard $quota,
    ) {}

    /**
     * Move a unit to a new status, recording why.
     *
     * @param  Model|null  $reference  the document that caused it — purchase, sale, ticket
     *
     * @throws IllegalUnitTransition
     */
    public function transition(
        ProductUnit $unit,
        UnitStatus $to,
        ?Model $reference = null,
        ?string $note = null,
        ?int $actorId = null,
    ): ProductUnit {
        $from = $unit->status;

        if ($from === $to) {
            // Not an error — callers legitimately re-assert a status — but it must not
            // add a history line, or a passport fills with meaningless repeats.
            return $unit;
        }

        if (! $from->canTransitionTo($to)) {
            throw IllegalUnitTransition::between($unit, $from, $to);
        }

        /** @var ProductUnit $updated */
        $updated = $this->connection->transaction(function () use ($unit, $from, $to, $reference, $note, $actorId): ProductUnit {
            $unit->forceFill(['status' => $to])->save();

            ProductUnitHistory::query()->create([
                'product_unit_id' => $unit->getKey(),
                'from_status' => $from,
                'to_status' => $to,
                'actor_id' => $actorId ?? auth()->id(),
                'reference_type' => $reference === null ? null : $reference::class,
                'reference_id' => $reference?->getKey(),
                'note' => $note,
            ]);

            return $unit;
        });

        return $updated;
    }

    /**
     * Record the first line of the passport: how this device entered the shop.
     *
     * Separate from `transition()` because there is no `from` status — the device did not
     * exist here before. Without this line, a unit's history starts mid-story and
     * "bought from whom" has no answer.
     *
     * ## This is also where a device is metered
     *
     * `inventory.units` counts devices ENTERING the shop, and this method is the one place
     * that happens — a purchase receipt and a trade-in both come through here. Metering
     * the purchase route instead would leave the trade-in as a loophole: a shop out of
     * device credit could still take phones in over the counter, which is the same act
     * with a different door.
     *
     * @param  bool  $metered  false when the caller has already reserved credit for a
     *                         batch. `ReceivePurchaseInvoice` spends `n` once for a
     *                         twenty-IMEI delivery rather than holding the counter row
     *                         open across twenty round trips
     */
    public function recordAcquisition(
        ProductUnit $unit,
        ?Model $reference = null,
        ?string $note = null,
        ?int $actorId = null,
        bool $metered = true,
    ): ProductUnitHistory {
        if ($metered) {
            $this->quota->consume('inventory.units');
        }

        /** @var ProductUnitHistory $history */
        $history = ProductUnitHistory::query()->create([
            'product_unit_id' => $unit->getKey(),
            'from_status' => null,
            'to_status' => $unit->status,
            'actor_id' => $actorId ?? auth()->id(),
            'reference_type' => $reference === null ? null : $reference::class,
            'reference_id' => $reference?->getKey(),
            'note' => $note,
        ]);

        return $history;
    }
}
