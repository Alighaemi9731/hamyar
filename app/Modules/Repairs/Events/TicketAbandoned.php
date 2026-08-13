<?php

declare(strict_types=1);

namespace App\Modules\Repairs\Events;

use App\Modules\Repairs\Models\RepairTicket;
use Illuminate\Foundation\Events\Dispatchable;

/** رسوبی — the shop has given up expecting somebody to collect this. */
final class TicketAbandoned
{
    use Dispatchable;

    public function __construct(
        public readonly RepairTicket $ticket,
        public readonly int $daysReady,
    ) {}
}
