<?php

declare(strict_types=1);

namespace App\Modules\Repairs\Events;

use App\Modules\Repairs\Models\RepairTicket;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * The customer said yes.
 *
 * Carries HOW they said it, because the shop is asked that question when a bill is
 * disputed: a tapped link is evidence on its own, while a phone approval is somebody's
 * word and needs the staff member's name attached.
 */
final class TicketApproved
{
    use Dispatchable;

    public function __construct(
        public readonly RepairTicket $ticket,
        /** `link` or `phone` — {@see RepairTicket::APPROVED_VIA_LINK}. */
        public readonly string $via,
    ) {}
}
