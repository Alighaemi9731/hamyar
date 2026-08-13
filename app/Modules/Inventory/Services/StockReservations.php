<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use App\Modules\Inventory\Enums\MovementType;
use App\Modules\Inventory\Models\StockReservation;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Holding stock that has not moved.
 *
 * ## The one rule this exists to enforce
 *
 * **A till may not sell what a repair has already claimed.** A screen protector set
 * aside for tomorrow's job is physically on the shelf, so `onHand()` still counts it —
 * and a POS that sells against on-hand will happily sell it out from under the repair.
 * The technician then opens the drawer to a gap, and the shop has two unhappy customers
 * and no idea which one is owed an apology.
 *
 * So `available()` is on-hand minus active reservations, and that is the figure the POS
 * quotes. The distinction is deliberate rather than cosmetic: `onHand()` answers "what is
 * in the building", which is what a stock count reconciles against, and `available()`
 * answers "what may I promise somebody", which is what a till needs.
 *
 * ## Reserving writes no stock movement
 *
 * Nothing has moved. Writing one would make the ledger disagree with the shelf, and
 * would have to be reversed on cancellation — leaving two movements describing an event
 * that never happened. The movement is written on **consumption**, once, when the part
 * is actually fitted.
 *
 * ## Closed reservations are kept
 *
 * Released and consumed rows stay. "Why did the shop think it had two of these last
 * Tuesday" is a question somebody asks, and a deleted row cannot answer it.
 */
final class StockReservations
{
    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly StockLedger $stock,
    ) {}

    /**
     * Hold stock for somebody.
     *
     * Refuses to reserve more than is available, under a lock — two technicians claiming
     * the last screen at the same moment is the race this closes, and it is the same
     * shape as the double-sell guard in Sales.
     */
    public function reserve(
        Model $holder,
        int $variantId,
        int $warehouseId,
        int $quantity,
        ?int $actorId = null,
    ): StockReservation {
        if ($quantity <= 0) {
            throw new RuntimeException('تعداد رزرو باید بیشتر از صفر باشد.');
        }

        /** @var StockReservation $reservation */
        $reservation = $this->connection->transaction(function () use ($holder, $variantId, $warehouseId, $quantity, $actorId): StockReservation {
            // Locked so a concurrent reservation cannot read the same availability and
            // both succeed. Reading the rows we are about to add to is what makes the
            // check meaningful.
            StockReservation::query()
                ->where('product_variant_id', $variantId)
                ->where('warehouse_id', $warehouseId)
                ->active()
                ->lockForUpdate()
                ->get();

            $available = $this->available($variantId, $warehouseId);

            if ($quantity > $available) {
                throw new RuntimeException(
                    "موجودی قابل تخصیص کافی نیست؛ {$available} عدد در دسترس است."
                );
            }

            return StockReservation::query()->create([
                'product_variant_id' => $variantId,
                'warehouse_id' => $warehouseId,
                'holder_type' => $holder::class,
                'holder_id' => $holder->getKey(),
                'quantity' => $quantity,
                'state' => StockReservation::STATE_ACTIVE,
                'actor_id' => $actorId,
            ]);
        });

        return $reservation;
    }

    /**
     * What a till may actually promise: on hand, less what is spoken for.
     *
     * Never negative. A shop that has allowed negative stock can genuinely be below
     * zero on-hand, and reporting a negative "available" would make a POS refuse a sale
     * with a number nobody can act on.
     */
    public function available(int $variantId, ?int $warehouseId = null): int
    {
        $onHand = $this->stock->onHand($variantId, $warehouseId);

        return max(0, $onHand - $this->reservedQuantity($variantId, $warehouseId));
    }

    /**
     * How much of a variant is currently held by somebody.
     */
    public function reservedQuantity(int $variantId, ?int $warehouseId = null): int
    {
        return (int) StockReservation::query()
            ->where('product_variant_id', $variantId)
            ->when($warehouseId !== null, fn ($query) => $query->where('warehouse_id', $warehouseId))
            ->active()
            ->sum('quantity');
    }

    /**
     * Reserved quantities for many variants at once, keyed by variant id.
     *
     * The list-screen companion to {@see reservedQuantity()} — asking per row is the N+1
     * that makes a stock page take seconds.
     *
     * @param  list<int>  $variantIds
     * @return array<int, int>
     */
    public function reservedForMany(array $variantIds, ?int $warehouseId = null): array
    {
        if ($variantIds === []) {
            return [];
        }

        $rows = StockReservation::query()
            ->whereIn('product_variant_id', $variantIds)
            ->when($warehouseId !== null, fn ($query) => $query->where('warehouse_id', $warehouseId))
            ->active()
            ->groupBy('product_variant_id')
            ->selectRaw('product_variant_id, sum(quantity) as reserved')
            ->toBase()
            ->get();

        $reserved = [];

        foreach ($rows as $row) {
            $values = (array) $row;
            $id = $values['product_variant_id'] ?? null;
            $quantity = $values['reserved'] ?? null;

            if (is_numeric($id)) {
                $reserved[(int) $id] = is_numeric($quantity) ? (int) $quantity : 0;
            }
        }

        return $reserved;
    }

    /**
     * The part was fitted. Stock leaves the building now, and only now.
     */
    public function consume(StockReservation $reservation, Model $reference, ?int $actorId = null): StockReservation
    {
        $this->guardActive($reservation);

        /** @var StockReservation $consumed */
        $consumed = $this->connection->transaction(function () use ($reservation, $reference, $actorId): StockReservation {
            // The one movement this whole flow writes. Negative: goods left the shelf.
            $this->stock->record(
                $reservation->product_variant_id,
                $reservation->warehouse_id,
                -$reservation->quantity,
                MovementType::RepairConsume,
                reference: $reference,
                unitCost: $this->stock->weightedAverageCost(
                    $reservation->product_variant_id,
                    $reservation->warehouse_id,
                ),
                actorId: $actorId,
            );

            $reservation->forceFill([
                'state' => StockReservation::STATE_CONSUMED,
                'closed_at' => CarbonImmutable::now(),
            ])->save();

            return $reservation;
        });

        return $consumed;
    }

    /**
     * The job was cancelled. The part goes back to being sellable.
     *
     * No stock movement, in either direction — it never left the shelf. A pair of
     * offsetting movements here would be a lie told twice.
     */
    public function release(StockReservation $reservation, ?int $actorId = null): StockReservation
    {
        $this->guardActive($reservation);

        $reservation->forceFill([
            'state' => StockReservation::STATE_RELEASED,
            'closed_at' => CarbonImmutable::now(),
            'actor_id' => $actorId ?? $reservation->actor_id,
        ])->save();

        return $reservation;
    }

    /**
     * Everything a holder is currently sitting on.
     *
     * @return list<StockReservation>
     */
    public function activeFor(Model $holder): array
    {
        return array_values(
            StockReservation::query()
                ->where('holder_type', $holder::class)
                ->where('holder_id', $holder->getKey())
                ->active()
                ->orderBy('id')
                ->get()
                ->all()
        );
    }

    private function guardActive(StockReservation $reservation): void
    {
        if ($reservation->state !== StockReservation::STATE_ACTIVE) {
            throw new RuntimeException('این رزرو قبلاً بسته شده است.');
        }
    }
}
