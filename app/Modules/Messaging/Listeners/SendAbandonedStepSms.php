<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Listeners;

use App\Modules\Messaging\Enums\AutomationKey;
use App\Modules\Messaging\Services\Automations;
use App\Modules\Repairs\Events\TicketEscalated;

/**
 * The رسوبی ladder, one rung at a time.
 *
 * Hangs off `Repairs\Events\TicketEscalated`, which Phase 6 dispatches from
 * `AbandonedSweep` — and which is already idempotent at its source: the sweep records the
 * step under a unique index BEFORE announcing it, so this listener never sees the same step
 * twice however often the scheduler runs.
 *
 * The key here is belt-and-braces on top of that, and it costs nothing.
 */
final class SendAbandonedStepSms
{
    public function __construct(private readonly Automations $automations) {}

    public function handle(TicketEscalated $event): void
    {
        $ticket = $event->ticket;

        $this->automations->fire(
            AutomationKey::RepairAbandonedStep,
            $ticket->party,
            [
                'name' => $ticket->party?->name,
                'ticket_code' => $ticket->code,
                'device' => trim(($ticket->device_brand ?? '').' '.$ticket->device_model),
                'days' => (int) ($ticket->ready_at?->diffInDays(now()) ?? 0),
                'shop' => config()->string('app.name'),
            ],
            idempotencyKey: "repair-abandoned:{$ticket->id}:{$event->step}",
            reference: $ticket,
            branchId: $ticket->branch_id,
        );
    }
}
