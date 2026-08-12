<?php

declare(strict_types=1);

namespace App\Modules\Repairs\Services;

use App\Modules\Repairs\Enums\TicketStatus;
use App\Modules\Repairs\Events\TicketApproved;
use App\Modules\Repairs\Models\RepairTicket;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Asking the customer, and recording what they said.
 *
 * ## What the customer approves is what the customer was shown
 *
 * The figure is frozen when the link is minted, not read when the link is used. The
 * hazard that closes: the shop quotes 4,500,000 and texts a link; somebody edits the
 * estimate to 9,000,000 before the customer taps it; the customer approves — and if
 * approval simply copied the current estimate, they have just agreed to a number they
 * never saw.
 *
 * So {@see request()} snapshots `approval_quoted_amount`, the public page renders that,
 * and {@see approveByLink()} records that. Re-quoting mints a **new** token and the old
 * link stops working, which is the honest outcome: a changed price is a new question,
 * not a silent substitution in an old one.
 *
 * ## The token is separate from the tracking token, and single-use
 *
 * Tracking is printed on the receipt and lives in a pocket; approving commits somebody
 * to spending money. So this secret is minted only when the shop asks, sent to the
 * number on the ticket, and cleared the moment it is used — a photographed receipt
 * cannot authorise work, and a link cannot be replayed.
 *
 * ## Approving by phone is a first-class path
 *
 * Most Iranian customers say yes on a call, not by tapping a link. That path records the
 * staff member who took the call and their note, so «who agreed to this?» has an answer
 * that names a person rather than a URL.
 */
final class QuoteApproval
{
    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly TicketStateMachine $machine,
    ) {}

    /**
     * Quote the job and ask the customer.
     *
     * Moves the ticket to `awaiting_approval` and mints the link. Returns the ticket with
     * a fresh token; Phase 8 texts it.
     */
    public function request(RepairTicket $ticket, int $quotedAmount, ?int $actorId = null): RepairTicket
    {
        if ($quotedAmount <= 0) {
            throw new RuntimeException('برای درخواست تأیید، مبلغ برآورد را وارد کنید.');
        }

        if ($ticket->isClosed()) {
            throw new RuntimeException('این تیکت بسته شده است و برآورد جدیدی نمی‌پذیرد.');
        }

        /** @var RepairTicket $updated */
        $updated = $this->connection->transaction(function () use ($ticket, $quotedAmount): RepairTicket {
            $ticket->forceFill([
                'estimate_amount' => $quotedAmount,
                // Frozen. The public page shows this and the approval records it, so a
                // later edit cannot re-price a request the customer is looking at.
                'approval_quoted_amount' => $quotedAmount,
                // A NEW token every time, which invalidates any link already sent. A
                // changed price is a new question.
                'approval_token' => Str::random(48),
                'approval_requested_at' => CarbonImmutable::now(),
                // Re-asking clears any previous answer: the old yes was to a different
                // number, and leaving it in place would read as approval of this one.
                'approved_at' => null,
                'approved_amount' => null,
                'approved_via' => null,
                'approved_by' => null,
                'declined_at' => null,
            ])->save();

            return $ticket;
        });

        // Only moved if it is not already there — `transition()` is a no-op on a repeat
        // and refuses illegal moves, so a re-quote from `repairing` walks back properly.
        if ($updated->status !== TicketStatus::AwaitingApproval) {
            $this->machine->transition(
                $updated,
                TicketStatus::AwaitingApproval,
                $actorId,
                'درخواست تأیید مشتری برای مبلغ برآورد',
            );
        }

        return $updated;
    }

    /**
     * The customer tapped the link.
     */
    public function approveByLink(RepairTicket $ticket): RepairTicket
    {
        return $this->record($ticket, RepairTicket::APPROVED_VIA_LINK, null, null);
    }

    /**
     * The customer said yes on the phone, and a member of staff wrote it down.
     */
    public function approveByPhone(RepairTicket $ticket, int $actorId, ?string $note = null): RepairTicket
    {
        return $this->record(
            $ticket,
            RepairTicket::APPROVED_VIA_PHONE,
            $actorId,
            // Recorded even when blank, so the trail always says how the yes arrived.
            $note ?? 'تأیید تلفنی مشتری',
        );
    }

    /**
     * The customer said no.
     *
     * Deliberately does NOT move the ticket to `rejected`. Declining a quote means the
     * work is not authorised; whether the device is returned unrepaired, re-quoted lower,
     * or collected as-is is a conversation the shop still has to have. Closing the ticket
     * automatically would make that decision for them.
     */
    public function decline(RepairTicket $ticket, ?string $note = null): RepairTicket
    {
        $ticket->forceFill([
            'declined_at' => CarbonImmutable::now(),
            'approval_token' => null,
            'approval_note' => $note,
        ])->save();

        return $ticket;
    }

    private function record(RepairTicket $ticket, string $via, ?int $actorId, ?string $note): RepairTicket
    {
        if ($ticket->approved_at !== null) {
            // Not an error worth throwing over: a customer taps a link twice, or says
            // yes on the phone after already tapping. The first answer stands.
            return $ticket;
        }

        $quoted = $ticket->approval_quoted_amount;

        if ($quoted === null) {
            throw new RuntimeException('برای این تیکت درخواست تأییدی ثبت نشده است.');
        }

        /** @var RepairTicket $approved */
        $approved = $this->connection->transaction(function () use ($ticket, $via, $actorId, $note, $quoted): RepairTicket {
            $ticket->forceFill([
                // The figure they were shown, never the current estimate.
                'approved_amount' => $quoted,
                'approved_at' => CarbonImmutable::now(),
                'approved_via' => $via,
                'approved_by' => $actorId,
                'approval_note' => $note,
                // Single-use. The link cannot be replayed, and a photographed receipt
                // cannot authorise anything.
                'approval_token' => null,
                'declined_at' => null,
            ])->save();

            return $ticket;
        });

        TicketApproved::dispatch($approved, $via);

        return $approved;
    }
}
