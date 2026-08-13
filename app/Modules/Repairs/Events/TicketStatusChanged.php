<?php

declare(strict_types=1);

namespace App\Modules\Repairs\Events;

use App\Modules\Repairs\Enums\TicketStatus;
use App\Modules\Repairs\Models\RepairTicket;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A device moved.
 *
 * The hook Phase 8 hangs SMS on — "your phone is ready", "we need your approval" — and
 * the reason the state machine dispatches only after the transaction commits. Texting a
 * customer about a status that then rolled back is a phone call the shop has to make to
 * take it back.
 *
 * Carries the ticket rather than an id: every listener needs the code and the customer,
 * and re-querying inside a queued listener would need the tenant context restored first
 * (which the queue does, but four listeners doing it is four chances to forget).
 */
final class TicketStatusChanged
{
    use Dispatchable;

    public function __construct(
        public readonly RepairTicket $ticket,
        public readonly TicketStatus $from,
        public readonly TicketStatus $to,
        public readonly ?int $actorId = null,
    ) {}
}
