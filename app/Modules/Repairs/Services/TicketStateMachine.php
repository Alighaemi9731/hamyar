<?php

declare(strict_types=1);

namespace App\Modules\Repairs\Services;

use App\Modules\Repairs\Enums\TicketStatus;
use App\Modules\Repairs\Events\TicketStatusChanged;
use App\Modules\Repairs\Exceptions\IllegalTicketTransition;
use App\Modules\Repairs\Models\RepairTicket;
use App\Modules\Repairs\Models\TicketStatusHistory;
use App\Support\Settings\ShopSettings;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The only way a ticket's status changes.
 *
 * Modelled on {@see \App\Modules\Inventory\Services\UnitStateMachine}, deliberately: a
 * repair ticket and a serialized handset have the same shape of problem — a small set of
 * legal moves, a history that must have no gaps, and a status that several screens write
 * to. Two guarantees, and they are why this is a service rather than a setter.
 *
 * ## 1. Illegal transitions are refused
 *
 * The map lives on {@see TicketStatus}; this enforces it. The refusals that matter are
 * the ones out of `delivered` and `rejected`: the device has left the shop, and a ticket
 * that can be reopened after the customer walked out with the phone would let somebody
 * quietly rewrite what was agreed at handover.
 *
 * ## 2. Every transition writes history, in the same transaction
 *
 * A status change with no history row is exactly the gap the module exists to prevent —
 * "what has been happening to my phone for three weeks" has to be answerable, and it is
 * only as good as its worst missing line.
 *
 * ## The approval cap
 *
 * A technician may not spend a customer's money above a per-shop cap without asking. So
 * moving into `repairing` is refused while the quote is above the cap and no approval
 * has been recorded. This lives here rather than in a controller because the board drags
 * a card, the ticket page presses a button, and a future SMS reply may do it too — three
 * doors, one rule.
 *
 * ## Events are dispatched after commit
 *
 * `TicketStatusChanged` is what Phase 8 hangs SMS on. Dispatching inside the transaction
 * would text a customer "your phone is ready" and then roll the status back.
 */
final class TicketStateMachine
{
    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly ShopSettings $settings,
    ) {}

    /**
     * Move a ticket, recording who moved it and why.
     *
     * @throws IllegalTicketTransition when the map forbids it
     * @throws RuntimeException when the work needs an approval nobody has given
     */
    public function transition(
        RepairTicket $ticket,
        TicketStatus $to,
        ?int $actorId = null,
        ?string $note = null,
    ): RepairTicket {
        /*
        | Re-read under a row lock before deciding anything.
        |
        | `$ticket->status` is whatever was loaded when the request began, and two
        | concurrent requests both hold their own stale copy — route-model binding gives
        | each of them one. Both would read `ready`, both would pass the map check, and
        | both would move the ticket. That is the same shape as the serialized double-sell
        | in Sales, and it gets the same treatment.
        |
        | Found by an adversarial review probe that delivered one device twice and
        | charged the customer for both.
        */
        $locked = RepairTicket::query()->lockForUpdate()->find($ticket->getKey());

        if (! $locked instanceof RepairTicket) {
            throw new RuntimeException('این تیکت دیگر در سیستم نیست.');
        }

        $ticket->setRawAttributes($locked->getRawOriginal(), true);

        $from = $ticket->status;

        if ($from === $to) {
            // Not an error — a board drops a card back in its own column, and two staff
            // press the same button — but it must not add a history line, or the trail
            // fills with meaningless repeats and the real moves stop standing out.
            return $ticket;
        }

        if (! $from->canTransitionTo($to)) {
            throw IllegalTicketTransition::between($ticket, $from, $to);
        }

        $this->guardApproval($ticket, $to);

        $at = CarbonImmutable::now();

        /** @var RepairTicket $moved */
        $moved = $this->connection->transaction(function () use ($ticket, $from, $to, $actorId, $note, $at): RepairTicket {
            $ticket->forceFill([
                'status' => $to,
                // Stamped as they happen rather than derived from the history later:
                // these three drive the abandoned-device sweep, the delivery receipt and
                // the warranty window, and none of them should have to parse a trail.
                ...$this->stampsFor($to, $at),
            ])->save();

            TicketStatusHistory::query()->create([
                'repair_ticket_id' => $ticket->getKey(),
                'from_status' => $from,
                'to_status' => $to,
                'actor_id' => $actorId ?? auth()->id(),
                'note' => $note,
            ]);

            return $ticket;
        });

        // After commit, on purpose — see the class docblock. `afterCommit` rather than
        // "after this method's transaction", because a caller may have wrapped this in
        // an outer one (delivery does), and firing at the inner boundary would announce
        // a status the outer transaction can still roll back.
        DB::afterCommit(
            fn () => TicketStatusChanged::dispatch($moved, $from, $to, $actorId)
        );

        return $moved;
    }

    /**
     * Refuse to start work that needs an approval nobody has given.
     *
     * Only `repairing` is guarded. Diagnosing a device to produce the quote is exactly
     * what the shop must do *before* anyone can approve anything, and gating that would
     * deadlock every ticket above the cap.
     */
    private function guardApproval(RepairTicket $ticket, TicketStatus $to): void
    {
        if ($to !== TicketStatus::Repairing) {
            return;
        }

        $cap = $this->settings->repairs()->approvalCap;

        // Approval already on file. Checked first, so an approved ticket starts
        // regardless of what the cap has since been changed to.
        if ($ticket->approved_at !== null) {
            return;
        }

        /*
        | FAIL CLOSED. A cap of zero exempts NOTHING — not even a ticket with no
        | estimate on it.
        |
        | The obvious form of this check is `$estimate <= $cap`, and it has a hole
        | exactly where it matters: with the default cap of zero, a ticket quoted at
        | zero satisfies `0 <= 0` and work begins with nobody's permission. An estimate
        | of zero does not mean the job is free, it means nobody has quoted it yet —
        | which is the state most needing a customer's say-so, not least.
        |
        | So the exemption requires a cap that was deliberately set. An unset,
        | negative or malformed setting resolves to zero (see RepairSettings) and
        | therefore to "everything needs approval", which is the safe direction to be
        | wrong in when the money being spent belongs to somebody else.
        */
        if ($cap > 0 && $ticket->estimate_amount <= $cap) {
            return;
        }

        throw new RuntimeException(
            'برآورد این تیکت از سقف مجاز بیشتر است؛ تا ثبت تأیید مشتری، کار روی آن شروع نمی‌شود.'
        );
    }

    /**
     * The timestamps a given status is responsible for.
     *
     * `ready_at` is re-stamped every time a ticket returns to `ready`, deliberately: the
     * abandoned-device clock counts from the last time the shop told the customer it was
     * finished, not from the first. A device that came back off the shelf for more work
     * has not been sitting uncollected.
     *
     * @return array<string, CarbonImmutable|null>
     */
    private function stampsFor(TicketStatus $to, CarbonImmutable $at): array
    {
        return match ($to) {
            TicketStatus::Ready => ['ready_at' => $at],
            TicketStatus::Delivered => ['delivered_at' => $at],
            TicketStatus::Abandoned => ['abandoned_at' => $at],
            default => [],
        };
    }

    /**
     * Record the first line of the trail: the device arriving.
     *
     * Separate from `transition()` because there is no `from` status — the ticket did
     * not exist before. Without this line a repair's history starts mid-story.
     */
    public function recordIntake(RepairTicket $ticket, ?int $actorId = null, ?string $note = null): TicketStatusHistory
    {
        /** @var TicketStatusHistory $history */
        $history = TicketStatusHistory::query()->create([
            'repair_ticket_id' => $ticket->getKey(),
            'from_status' => null,
            'to_status' => $ticket->status,
            'actor_id' => $actorId ?? auth()->id(),
            'note' => $note ?? 'پذیرش دستگاه',
        ]);

        return $history;
    }
}
