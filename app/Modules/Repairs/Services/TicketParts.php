<?php

declare(strict_types=1);

namespace App\Modules\Repairs\Services;

use App\Modules\Inventory\Models\StockReservation;
use App\Modules\Inventory\Services\StockLedger;
use App\Modules\Inventory\Services\StockReservations;
use App\Modules\Repairs\Events\RepairPartConsumed;
use App\Modules\Repairs\Events\RepairPartReturned;
use App\Modules\Repairs\Models\RepairTicket;
use App\Modules\Repairs\Models\TicketPart;
use Illuminate\Database\ConnectionInterface;
use RuntimeException;

/**
 * Parts, through their three states.
 *
 * ```
 *   reserved ──consume──▶ consumed     (fitted; stock leaves the shelf here, once)
 *      │
 *      └──────release───▶ returned     (job cancelled; nothing ever moved)
 * ```
 *
 * ## Reserve early, consume late
 *
 * The hold goes on when the job is planned — that is the whole point, because between
 * planning and finishing, a till will otherwise sell the part. The stock movement waits
 * until the part is actually fitted, so the ledger only ever records things that
 * physically happened.
 *
 * ## The cost is snapshotted at consumption
 *
 * Same discipline as `sales_invoice_items.cost_snapshot`, and the same reason: repair
 * profit is labour plus parts margin less outsourcing, and recomputing a margin at
 * today's cost restates a job finished three months ago. Under Iranian inflation that is
 * not a rounding difference.
 *
 * Deliberately taken at **consumption** rather than reservation: what the shop paid for
 * the part it actually fitted is the weighted average on the day it left the shelf, not
 * on the day somebody wrote it on a list.
 */
final class TicketParts
{
    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly StockReservations $reservations,
        private readonly StockLedger $stock,
    ) {}

    /**
     * Plan a part into a job, holding the stock.
     *
     * Refused if the shop cannot spare it — checked against **available**, not on-hand,
     * so two repairs cannot both claim the last screen.
     */
    public function reserve(
        RepairTicket $ticket,
        int $variantId,
        int $warehouseId,
        int $quantity,
        int $unitPrice = 0,
        ?int $actorId = null,
    ): TicketPart {
        if ($ticket->isClosed()) {
            throw new RuntimeException('این تیکت بسته شده است و قطعه جدیدی نمی‌پذیرد.');
        }

        /** @var TicketPart $part */
        $part = $this->connection->transaction(function () use ($ticket, $variantId, $warehouseId, $quantity, $unitPrice, $actorId): TicketPart {
            $reservation = $this->reservations->reserve($ticket, $variantId, $warehouseId, $quantity, $actorId);

            return $ticket->parts()->create([
                'product_variant_id' => $variantId,
                'warehouse_id' => $warehouseId,
                'stock_reservation_id' => $reservation->getKey(),
                'quantity' => $quantity,
                'unit_price' => max(0, $unitPrice),
                'state' => TicketPart::STATE_RESERVED,
                'actor_id' => $actorId,
            ]);
        });

        return $part;
    }

    /**
     * The part was fitted.
     */
    public function consume(TicketPart $part, ?int $actorId = null): TicketPart
    {
        $this->guardReserved($part);

        $ticket = $part->ticket;

        if (! $ticket instanceof RepairTicket) {
            throw new RuntimeException('این قطعه به هیچ تیکتی وصل نیست.');
        }

        /** @var TicketPart $consumed */
        $consumed = $this->connection->transaction(function () use ($part, $ticket, $actorId): TicketPart {
            $reservation = $this->reservationFor($part);

            // Cost read BEFORE the movement, or the average this reads is the one the
            // movement just changed.
            $unitCost = $this->stock->weightedAverageCost($part->product_variant_id, $part->warehouse_id);

            $this->reservations->consume($reservation, $ticket, $actorId);

            $part->forceFill([
                'state' => TicketPart::STATE_CONSUMED,
                'unit_cost' => $unitCost,
            ])->save();

            return $part;
        });

        RepairPartConsumed::dispatch($consumed);

        return $consumed;
    }

    /**
     * The job was cancelled, or the part turned out not to be needed.
     */
    public function release(TicketPart $part, ?int $actorId = null): TicketPart
    {
        $this->guardReserved($part);

        $this->reservations->release($this->reservationFor($part), $actorId);

        $part->forceFill([
            'state' => TicketPart::STATE_RETURNED,
            'stock_reservation_id' => null,
        ])->save();

        RepairPartReturned::dispatch($part);

        return $part;
    }

    /**
     * Let go of everything a ticket is holding.
     *
     * Called when a job is rejected or a device is handed back unrepaired. Consumed
     * parts are untouched — they are inside the device and the customer is paying for
     * them; only the unfitted holds come off.
     */
    public function releaseAll(RepairTicket $ticket, ?int $actorId = null): int
    {
        $released = 0;

        foreach ($ticket->parts()->where('state', TicketPart::STATE_RESERVED)->get() as $part) {
            $this->release($part, $actorId);
            $released++;
        }

        return $released;
    }

    /**
     * What the parts on this job cost the shop, and what they earn it.
     *
     * Consumed only. A reserved part has not been fitted and may yet go back on the
     * shelf, so counting it would quote a profit on work that has not happened.
     *
     * @return array{cost: int, revenue: int, margin: int}
     */
    public function totals(RepairTicket $ticket): array
    {
        $cost = 0;
        $revenue = 0;

        foreach ($ticket->parts()->where('state', TicketPart::STATE_CONSUMED)->get() as $part) {
            $cost += $part->unit_cost * $part->quantity;
            $revenue += $part->unit_price * $part->quantity;
        }

        return ['cost' => $cost, 'revenue' => $revenue, 'margin' => $revenue - $cost];
    }

    private function guardReserved(TicketPart $part): void
    {
        if ($part->state !== TicketPart::STATE_RESERVED) {
            throw new RuntimeException('این قطعه قبلاً مصرف یا برگشت داده شده است.');
        }
    }

    private function reservationFor(TicketPart $part): StockReservation
    {
        $reservation = $part->stock_reservation_id === null
            ? null
            : StockReservation::query()->find($part->stock_reservation_id);

        if (! $reservation instanceof StockReservation) {
            throw new RuntimeException('رزرو انبار این قطعه پیدا نشد.');
        }

        return $reservation;
    }
}
