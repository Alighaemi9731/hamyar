<?php

declare(strict_types=1);

namespace App\Modules\Repairs\Enums;

/**
 * Where a device is in the shop.
 *
 * ## This is the spine of the module
 *
 * A repair bench runs on trust: somebody handed over a device worth months of income
 * and wants to know what is happening to it. Every screen, every SMS and the public
 * tracking page are all reading this one field, so the set of statuses has to match what
 * actually happens at a bench — not a tidy abstraction of it.
 *
 * The three that earn their place beyond the obvious:
 *
 * - **`awaiting_approval`** exists because a technician may not spend the customer's
 *   money above a cap without asking. Without a status for "we have quoted and are
 *   waiting", that wait is invisible and the device sits on a shelf while everyone
 *   assumes somebody else is chasing it.
 * - **`awaiting_parts`** is separate from `repairing` because the shop can do nothing
 *   about it, and a queue that cannot distinguish "blocked on a supplier" from "being
 *   worked on" tells a manager nothing.
 * - **`abandoned`** (رسوبی) is a real and expensive Iranian shop problem — devices pile
 *   up for months after they are ready. It is a status rather than a flag because it
 *   changes what the shop may do with the device.
 */
enum TicketStatus: string
{
    case Queued = 'queued';

    case Diagnosing = 'diagnosing';

    /** Quoted above the cap; work is blocked until the customer says yes. */
    case AwaitingApproval = 'awaiting_approval';

    /** Blocked on a supplier, not on the bench. */
    case AwaitingParts = 'awaiting_parts';

    case Repairing = 'repairing';

    case Ready = 'ready';

    case Delivered = 'delivered';

    /** Returned to the customer without being repaired — declined, or not economic. */
    case Rejected = 'rejected';

    /** رسوبی — ready for weeks and nobody came. */
    case Abandoned = 'abandoned';

    public function labelFa(): string
    {
        return match ($this) {
            self::Queued => 'در صف بررسی',
            self::Diagnosing => 'کارشناسی',
            self::AwaitingApproval => 'منتظر تأیید مشتری',
            self::AwaitingParts => 'منتظر قطعه',
            self::Repairing => 'در حال تعمیر',
            self::Ready => 'آماده تحویل',
            self::Delivered => 'تحویل‌شده',
            self::Rejected => 'مرجوع بدون تعمیر',
            self::Abandoned => 'رسوبی',
        };
    }

    /**
     * Where a ticket may go from here.
     *
     * ## What the map deliberately allows
     *
     * **Going backwards.** `repairing → diagnosing` is legal, and so is
     * `ready → repairing`. A bench is not a pipeline: a device comes back off the
     * shelf because the fault recurred during testing, and a machine that refuses
     * that forces the technician to open a second ticket for one device — which
     * breaks the history that the whole module exists to keep.
     *
     * **Rejecting from almost anywhere.** A customer can decline at any point up to
     * the moment the device is handed back.
     *
     * ## What it refuses, and why each refusal is load-bearing
     *
     * - **Nothing returns from `delivered`.** The device is gone. A fault that shows up
     *   afterwards is a new ticket, linked to this one by the same device — which is
     *   what makes the warranty question answerable ("was this the same fault?").
     * - **Nothing returns from `rejected`.** Same reason: the device left the shop.
     * - **`abandoned` is reachable only from `ready`.** رسوبی means "we finished it and
     *   nobody came". A device abandoned while still being diagnosed is not abandoned,
     *   it is in progress and somebody has stopped working on it — a different problem,
     *   and one this status would hide.
     * - **`abandoned → delivered`** is the one way out: the customer finally turns up.
     *   That has to stay open, because they do.
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Queued => [self::Diagnosing, self::AwaitingApproval, self::Repairing, self::Rejected],
            self::Diagnosing => [self::AwaitingApproval, self::AwaitingParts, self::Repairing, self::Ready, self::Rejected],
            self::AwaitingApproval => [self::Diagnosing, self::AwaitingParts, self::Repairing, self::Rejected],
            self::AwaitingParts => [self::Diagnosing, self::Repairing, self::Rejected],
            self::Repairing => [self::Diagnosing, self::AwaitingParts, self::AwaitingApproval, self::Ready, self::Rejected],
            // Back to the bench when testing finds the fault again; out of the shop
            // when somebody comes; رسوبی when nobody does.
            self::Ready => [self::Repairing, self::Delivered, self::Abandoned, self::Rejected],
            self::Abandoned => [self::Delivered, self::Ready],
            // Terminal. The device is not in the shop any more.
            self::Delivered, self::Rejected => [],
        };
    }

    public function canTransitionTo(self $status): bool
    {
        return in_array($status, $this->allowedTransitions(), true);
    }

    /**
     * Whether the device has left the shop for good.
     */
    public function isClosed(): bool
    {
        return $this === self::Delivered || $this === self::Rejected;
    }

    /**
     * Whether this status means somebody should be doing something today.
     *
     * Drives the board's default filter and the technician workload screen. `ready` is
     * deliberately **not** open work — the bench is finished with it and the ball is in
     * the customer's court, which is exactly the distinction the abandoned-device
     * problem is made of.
     */
    public function isOpenWork(): bool
    {
        return match ($this) {
            self::Queued, self::Diagnosing, self::AwaitingApproval,
            self::AwaitingParts, self::Repairing => true,
            self::Ready, self::Delivered, self::Rejected, self::Abandoned => false,
        };
    }

    /**
     * The statuses a Kanban board shows as columns, in bench order.
     *
     * `delivered` and `rejected` are absent on purpose: a board is a picture of work in
     * the shop, and a column that grows forever is one nobody reads.
     *
     * @return list<self>
     */
    public static function boardColumns(): array
    {
        return [
            self::Queued,
            self::Diagnosing,
            self::AwaitingApproval,
            self::AwaitingParts,
            self::Repairing,
            self::Ready,
        ];
    }
}
