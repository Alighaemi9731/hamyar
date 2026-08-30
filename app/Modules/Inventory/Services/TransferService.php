<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use App\Modules\Inventory\Enums\MovementType;
use App\Modules\Inventory\Enums\UnitStatus;
use App\Modules\Inventory\Models\ProductUnit;
use App\Modules\Inventory\Models\StockTransfer;
use App\Modules\Inventory\Models\StockTransferItem;
use App\Support\Quota\QuotaGuard;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use RuntimeException;

/**
 * Dispatch and receive, as two separate acts.
 *
 * The whole design rests on one idea: **the van journey is real**. Stock leaves the
 * source when it is dispatched and arrives at the destination when it is received, and
 * in between it is at neither. A one-step transfer makes a van full of phones sellable
 * in two shops at once, and the discrepancy only shows up when the second customer is
 * already at the counter.
 *
 * A shortfall on receipt is recorded rather than reconciled away. Three phones dispatched
 * and two received is an event someone needs to investigate, not an arithmetic problem
 * for the software to smooth over.
 */
final class TransferService
{
    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly StockLedger $stock,
        private readonly UnitStateMachine $units,
        private readonly QuotaGuard $quota,
    ) {}

    /**
     * Send the goods. Stock leaves the source now.
     */
    public function dispatch(StockTransfer $transfer, ?CarbonImmutable $at = null): StockTransfer
    {
        if (! $transfer->isDraft()) {
            throw new RuntimeException("Transfer {$transfer->number} is {$transfer->status} and cannot be dispatched.");
        }

        $at ??= CarbonImmutable::now();

        /** @var StockTransfer $dispatched */
        $dispatched = $this->connection->transaction(function () use ($transfer, $at): StockTransfer {
            $transfer->load('items');

            // Metered at dispatch, not at creation: a draft transfer is a shopkeeper
            // thinking, and charging for that would make people avoid the screen. The
            // stock only moves here.
            $this->quota->consume('inventory.transfers');

            if ($transfer->items->isEmpty()) {
                throw new RuntimeException("Transfer {$transfer->number} has no lines.");
            }

            foreach ($transfer->items as $item) {
                // Serialized lines do NOT touch the quantity ledger. A phone's location
                // is `product_units.warehouse_id` and its life is its history; counting
                // handsets by summing quantities would double-count them against the
                // unit register and throw away the per-unit detail that register exists
                // for. Standard goods are the opposite: quantity is all there is.
                if ($item->product_unit_id === null) {
                    $this->stock->record(
                        $item->product_variant_id,
                        $transfer->from_warehouse_id,
                        -$item->quantity,
                        MovementType::TransferOut,
                        reference: $transfer,
                        occurredAt: $at,
                    );
                } else {
                    $this->moveUnitOut($item, $transfer);
                }
            }

            $transfer->update([
                'status' => StockTransfer::STATUS_DISPATCHED,
                'dispatched_at' => $at,
                'dispatched_by' => auth()->id(),
            ]);

            return $transfer;
        });

        return $dispatched;
    }

    /**
     * Book the goods in at the far end.
     *
     * @param  array<int, int>  $countedByItemId  what actually arrived, per line id;
     *                                            omitted lines are assumed complete
     */
    public function receive(StockTransfer $transfer, array $countedByItemId = [], ?CarbonImmutable $at = null): StockTransfer
    {
        if (! $transfer->isDispatched()) {
            throw new RuntimeException("Transfer {$transfer->number} is {$transfer->status} and cannot be received.");
        }

        $at ??= CarbonImmutable::now();

        /** @var StockTransfer $received */
        $received = $this->connection->transaction(function () use ($transfer, $countedByItemId, $at): StockTransfer {
            $transfer->load('items');

            foreach ($transfer->items as $item) {
                /** @var int $itemId */
                $itemId = $item->getKey();

                $arrived = $countedByItemId[$itemId] ?? $item->quantity;

                if ($arrived > $item->quantity) {
                    throw new RuntimeException(
                        "More arrived than was dispatched on transfer {$transfer->number}: {$arrived} of {$item->quantity}."
                    );
                }

                $item->update(['received_quantity' => $arrived]);

                if ($item->product_unit_id === null) {
                    // A shortfall writes nothing at the destination for the missing
                    // units. They stay out of the source's ledger too, so the loss reads
                    // as a transfer that did not fully arrive rather than as stock that
                    // evaporated.
                    if ($arrived > 0) {
                        $this->stock->record(
                            $item->product_variant_id,
                            $transfer->to_warehouse_id,
                            $arrived,
                            MovementType::TransferIn,
                            reference: $transfer,
                            occurredAt: $at,
                        );
                    }
                } else {
                    $this->moveUnitIn($item, $transfer, $arrived);
                }
            }

            $transfer->update([
                'status' => StockTransfer::STATUS_RECEIVED,
                'received_at' => $at,
                'received_by' => auth()->id(),
            ]);

            return $transfer;
        });

        return $received;
    }

    /**
     * A serialized line leaves the source: the device is reserved, not sold.
     *
     * `Reserved` is the honest status for a phone in a van — it is still the shop's
     * asset and still on the valuation, but nobody may sell it.
     */
    private function moveUnitOut(StockTransferItem $item, StockTransfer $transfer): void
    {
        if ($item->product_unit_id === null) {
            return;
        }

        $unit = ProductUnit::query()->findOrFail($item->product_unit_id);

        $this->units->transition(
            $unit,
            UnitStatus::Reserved,
            reference: $transfer,
            note: "ارسال با حواله {$transfer->number}",
        );
    }

    private function moveUnitIn(StockTransferItem $item, StockTransfer $transfer, int $arrived): void
    {
        if ($item->product_unit_id === null || $arrived < 1) {
            return;
        }

        $unit = ProductUnit::query()->findOrFail($item->product_unit_id);

        // The device is now physically at the destination, so its location moves with it.
        // Leaving `warehouse_id` behind would make the IMEI lookup point at the wrong shop.
        $unit->update(['warehouse_id' => $transfer->to_warehouse_id]);

        $this->units->transition(
            $unit,
            UnitStatus::InStock,
            reference: $transfer,
            note: "دریافت با حواله {$transfer->number}",
        );
    }
}
