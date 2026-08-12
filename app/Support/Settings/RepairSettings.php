<?php

declare(strict_types=1);

namespace App\Support\Settings;

/**
 * How a shop runs its repair bench.
 *
 * ## The approval cap
 *
 * The amount a technician may spend of a customer's money without asking. Below it, work
 * starts immediately and everybody is happier; above it, the ticket has to pass through
 * `awaiting_approval` and work is refused until somebody records a yes.
 *
 * The default is **zero — every job needs approval**. That is the cautious end, and it
 * is deliberate: a shop that has never opened the settings screen has not told us what
 * it considers a safe amount to spend on somebody else's phone, and guessing on the
 * generous side means guessing with a customer's money.
 *
 * Zero is genuinely different from "no cap". A shop that wants its technicians to work
 * unimpeded sets a large number; it cannot be expressed by leaving the setting blank,
 * and that asymmetry is the point.
 *
 * **This fails closed, and the direction is load-bearing.** Unset, negative, a string,
 * out of range — every malformed value resolves here to zero, and zero exempts nothing
 * at all. A ticket quoted at zero is not a free job, it is a job nobody has quoted yet,
 * so it needs approval more than a quoted one does, not less. {@see TicketStateMachine}
 * enforces that with `$cap > 0 && $estimate <= $cap` rather than the obvious
 * `$estimate <= $cap`, which would let a zero-quote ticket through a zero cap.
 *
 * ## The abandonment window
 *
 * How many days a finished device may sit before it is رسوبی. Iranian shops lose real
 * floor space to this, and the escalating SMS sequence (Phase 8) keys off the same
 * number. Sixty days is the common shop answer.
 */
final readonly class RepairSettings
{
    public function __construct(
        /** Rial. Work above this needs a recorded customer approval. */
        public int $approvalCap,
        /** Days after `ready` before a device is flagged رسوبی. */
        public int $abandonedAfterDays,
    ) {}

    /**
     * The shape stored on a ticket, so a reprint reflects the day.
     *
     * @return array{approval_cap: int, abandoned_after_days: int}
     */
    public function toSnapshot(): array
    {
        return [
            'approval_cap' => $this->approvalCap,
            'abandoned_after_days' => $this->abandonedAfterDays,
        ];
    }
}
