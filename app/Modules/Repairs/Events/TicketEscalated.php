<?php

declare(strict_types=1);

namespace App\Modules\Repairs\Events;

use App\Modules\Repairs\Models\RepairTicket;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A nudge is due about an uncollected device.
 *
 * Phase 8 sends the SMS. Carries the step so the message can escalate in tone — a
 * reminder at a week and a final notice at seven should not read the same.
 *
 * Fired at most ONCE per ticket per step, guaranteed by a unique index rather than by
 * this class being careful. See `AbandonedSweep`.
 */
final class TicketEscalated
{
    use Dispatchable;

    public function __construct(
        public readonly RepairTicket $ticket,
        /** 1-based position in the shop's configured ladder. */
        public readonly int $step,
    ) {}
}
