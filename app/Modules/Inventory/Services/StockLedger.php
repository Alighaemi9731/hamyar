<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use App\Modules\Inventory\Enums\MovementType;
use App\Modules\Inventory\Exceptions\InsufficientStock;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\Warehouse;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Writing and reading the quantity ledger.
 *
 * The only way stock quantities change. Callers write *movements* and ask for *sums*;
 * nothing anywhere stores a running total (golden rule 3).
 *
 * ## Negative stock
 *
 * Blocked by default, allowed per warehouse. Some Iranian shops genuinely sell ahead of
 * a delivery arriving and would rather record the sale than block the till — but that is
 * a deliberate choice per location, because the same behaviour left on everywhere hides
 * counting errors for months.
 */
final class StockLedger
{
    public function __construct(private readonly ConnectionInterface $connection) {}

    /**
     * Quantity on hand for one variant, optionally in one warehouse.
     *
     * A SUM, always. `$at` lets a valuation ask what the figure was on a past date, which
     * a stored total could never answer.
     */
    public function onHand(int $variantId, ?int $warehouseId = null, ?CarbonImmutable $at = null): int
    {
        $query = StockMovement::query()->where('product_variant_id', $variantId);

        if ($warehouseId !== null) {
            $query->where('warehouse_id', $warehouseId);
        }

        if ($at instanceof CarbonImmutable) {
            $query->where('occurred_at', '<=', $at);
        }

        return (int) $query->sum('quantity');
    }

    /**
     * On-hand for many variants at once, keyed by variant id.
     *
     * The list screen needs a figure per row; asking `onHand()` in a loop is the N+1 that
     * makes a stock page take seconds.
     *
     * @param  list<int>  $variantIds
     * @return array<int, int>
     */
    public function onHandForMany(array $variantIds, ?int $warehouseId = null): array
    {
        if ($variantIds === []) {
            return [];
        }

        $query = StockMovement::query()
            ->selectRaw('product_variant_id, sum(quantity) as total')
            ->whereIn('product_variant_id', $variantIds)
            ->groupBy('product_variant_id');

        if ($warehouseId !== null) {
            $query->where('warehouse_id', $warehouseId);
        }

        $totals = [];

        foreach ($query->get() as $row) {
            /** @var int|numeric-string $variantId */
            $variantId = $row->getAttribute('product_variant_id');
            /** @var int|numeric-string $total */
            $total = $row->getAttribute('total');

            $totals[(int) $variantId] = (int) $total;
        }

        // Variants with no movements at all are absent from the group-by, and a missing
        // key reads as "unknown" downstream when it means zero.
        foreach ($variantIds as $id) {
            $totals[$id] ??= 0;
        }

        return $totals;
    }

    /**
     * Record a movement.
     *
     * @param  int  $quantity  signed — positive in, negative out
     *
     * @throws InsufficientStock when the result would go negative in a warehouse that
     *                           does not allow it
     */
    public function record(
        int $variantId,
        int $warehouseId,
        int $quantity,
        MovementType $type,
        ?Model $reference = null,
        int $unitCost = 0,
        ?string $note = null,
        ?CarbonImmutable $occurredAt = null,
        ?int $actorId = null,
    ): StockMovement {
        $this->guardSign($type, $quantity);

        /** @var StockMovement $movement */
        $movement = $this->connection->transaction(function () use (
            $variantId, $warehouseId, $quantity, $type, $reference, $unitCost, $note, $occurredAt, $actorId
        ): StockMovement {
            // Locked read-then-write: two concurrent sales of the last item must not both
            // see one in stock. The lock is on the warehouse row because there is no
            // single row representing "the balance" to lock instead.
            $warehouse = Warehouse::query()->lockForUpdate()->findOrFail($warehouseId);

            if ($quantity < 0 && ! $warehouse->allows_negative_stock) {
                $available = $this->onHand($variantId, $warehouseId);

                if ($available + $quantity < 0) {
                    throw InsufficientStock::for($variantId, $warehouse, $available, abs($quantity));
                }
            }

            return StockMovement::query()->create([
                'product_variant_id' => $variantId,
                'warehouse_id' => $warehouseId,
                'quantity' => $quantity,
                'type' => $type,
                'reference_type' => $reference === null ? null : $reference::class,
                'reference_id' => $reference?->getKey(),
                'unit_cost' => $unitCost,
                'actor_id' => $actorId ?? auth()->id(),
                'note' => $note,
                'occurred_at' => $occurredAt ?? CarbonImmutable::now(),
            ]);
        });

        return $movement;
    }

    /**
     * Move stock between warehouses as two movements, not one.
     *
     * Deliberately two rows: goods in transit are then visible at neither end, and each
     * side's ledger explains its own change. A single row with two warehouse columns
     * would make the on-hand SUM need to know about direction.
     *
     * @return array{0: StockMovement, 1: StockMovement}
     */
    public function transfer(
        int $variantId,
        int $fromWarehouseId,
        int $toWarehouseId,
        int $quantity,
        ?Model $reference = null,
        int $unitCost = 0,
    ): array {
        if ($quantity <= 0) {
            throw new InvalidArgumentException('A transfer quantity must be positive.');
        }

        if ($fromWarehouseId === $toWarehouseId) {
            throw new InvalidArgumentException('Cannot transfer stock to the warehouse it is already in.');
        }

        /** @var array{0: StockMovement, 1: StockMovement} $pair */
        $pair = $this->connection->transaction(fn (): array => [
            $this->record($variantId, $fromWarehouseId, -$quantity, MovementType::TransferOut, $reference, $unitCost),
            $this->record($variantId, $toWarehouseId, $quantity, MovementType::TransferIn, $reference, $unitCost),
        ]);

        return $pair;
    }

    /**
     * Bring the recorded quantity into line with a counted one.
     *
     * Writes the difference as a `count` movement rather than setting a total, so the
     * correction itself is visible — "we were 3 short in Mordad" is a question worth
     * being able to answer.
     */
    public function reconcileTo(
        int $variantId,
        int $warehouseId,
        int $countedQuantity,
        ?Model $reference = null,
        ?string $note = null,
    ): ?StockMovement {
        $difference = $countedQuantity - $this->onHand($variantId, $warehouseId);

        if ($difference === 0) {
            return null;
        }

        return $this->record(
            $variantId,
            $warehouseId,
            $difference,
            MovementType::Count,
            $reference,
            note: $note,
        );
    }

    private function guardSign(MovementType $type, int $quantity): void
    {
        if ($quantity === 0) {
            throw new InvalidArgumentException('A stock movement of zero explains no change.');
        }

        $required = $type->requiredSign();

        if ($required !== null && ($quantity <=> 0) !== $required) {
            throw new InvalidArgumentException(sprintf(
                'A %s movement must be %s, got %d.',
                $type->value,
                $required === 1 ? 'positive' : 'negative',
                $quantity
            ));
        }
    }
}
