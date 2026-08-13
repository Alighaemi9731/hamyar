<?php

declare(strict_types=1);

namespace App\Modules\Repairs\Events;

use App\Modules\Repairs\Models\RepairTicket;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A device was taken in.
 *
 * Phase 8 sends the customer their tracking link from this. Phase 4's CRM timeline will
 * contribute from it too, which is why it carries the ticket and not just an id.
 */
final class TicketCreated
{
    use Dispatchable;

    public function __construct(public readonly RepairTicket $ticket) {}
}
