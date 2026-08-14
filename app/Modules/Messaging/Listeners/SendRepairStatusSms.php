<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Listeners;

use App\Modules\Messaging\Enums\AutomationKey;
use App\Modules\Messaging\Services\Automations;
use App\Modules\Repairs\Enums\TicketStatus;
use App\Modules\Repairs\Events\TicketStatusChanged;

/**
 * A repair moved, so tell the customer.
 *
 * Hangs off `Repairs\Events\TicketStatusChanged`, which Phase 6 already dispatches on every
 * transition — no new event, no synthetic name that drifts from its emitter the first time
 * somebody renames the real thing.
 *
 * ## «آماده است» is its own automation
 *
 * A shop that wants one message and one only wants that one: the device is ready, come and
 * collect it. Every other transition — diagnosing, awaiting parts — is chatter most shops
 * switch off, and forcing them to take both is how a shop switches off the one that
 * mattered. So `ready` fires `RepairReady` and everything else fires
 * `RepairStatusChanged`, and the two toggle independently.
 *
 * Deliberately not queued as a listener: `Automations` queues the send itself, so the
 * listener stays synchronous and cheap. Making the listener queued too would put the
 * settings lookup on a worker for no benefit and lose the tenant context question in a
 * second place.
 */
final class SendRepairStatusSms
{
    public function __construct(private readonly Automations $automations) {}

    public function handle(TicketStatusChanged $event): void
    {
        $ticket = $event->ticket;

        // Which transition this is, counting from the ticket's first. `TicketStateMachine`
        // writes exactly one history row per transition, so this is the transition's
        // ordinal — and it is what lets the key distinguish "ready for the second time"
        // from "the ready event fired twice".
        $ordinal = $ticket->histories()->count();

        $key = $event->to === TicketStatus::Ready
            ? AutomationKey::RepairReady
            : AutomationKey::RepairStatusChanged;

        $this->automations->fire(
            $key,
            $ticket->party,
            [
                'name' => $ticket->party?->name,
                'ticket_code' => $ticket->code,
                'device' => trim(($ticket->device_brand ?? '').' '.$ticket->device_model),
                'status' => $event->to->labelFa(),
                'shop' => config()->string('app.name'),
            ],
            /*
            | Keyed on the ticket AND the status it moved to.
            |
            | A ticket that goes ready → repairing → ready texts twice, which is correct:
            | the customer was told it was ready, then it was not, then it was again. But a
            | listener that fires twice for ONE transition — a retried job, a double
            | dispatch — must not. The key covers the second case and allows the first.
            */
            idempotencyKey: "repair-status:{$ticket->id}:{$event->to->value}:{$ordinal}",
            reference: $ticket,
            branchId: $ticket->branch_id,
        );
    }
}
