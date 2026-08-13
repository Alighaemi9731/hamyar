<?php

declare(strict_types=1);

namespace App\Modules\Repairs\Services;

use App\Modules\Repairs\Enums\TicketStatus;
use App\Modules\Repairs\Events\TicketAbandoned;
use App\Modules\Repairs\Events\TicketEscalated;
use App\Modules\Repairs\Models\RepairTicket;
use App\Modules\Repairs\Models\TicketEscalation;
use App\Support\Settings\ShopSettings;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;

/**
 * رسوبی — chasing devices nobody came back for.
 *
 * A real and expensive Iranian shop problem: finished phones pile up on a shelf for
 * months, taking space the shop pays rent on and carrying a liability it cannot
 * discharge. The sweep nudges the customer on a ladder the shop configures, and finally
 * marks the ticket `abandoned`.
 *
 * ## Idempotency is the whole design, because a scheduler runs twice
 *
 * Not "might" — will. A retry after a timeout, a second worker on a bad deploy, somebody
 * running the command by hand to see what it does. Each of those re-texts every waiting
 * customer, and the person whose phone has sat for ninety days gets four identical
 * messages in a minute.
 *
 * So **the step is recorded before it is announced**, and the record has a unique index.
 * A duplicate attempt collides on the insert and is swallowed; the event never fires
 * twice. That ordering matters: announcing first and recording after would send the
 * message and then lose the record if the write failed, which is the same bug with worse
 * timing.
 *
 * Checking `SELECT`-then-`INSERT` would be enough for a single worker and useless for
 * two — both would read "not sent", both would send. The database is the only place this
 * guarantee can live.
 *
 * ## The last passed step, not the next one
 *
 * A sweep that has not run for a fortnight lands on the step the shop is actually at,
 * rather than working through the ladder one message per day to catch up. Earlier steps
 * are backfilled as recorded-but-unannounced, so the trail stays honest without the
 * customer's phone paying for the outage.
 *
 * ## `ready_at` is re-stamped, and that is deliberate
 *
 * A device that came back off the shelf for more work has not been sitting uncollected.
 * {@see TicketStateMachine} re-stamps `ready_at` on every return to `ready`, so the
 * clock counts from the last time the shop said it was finished.
 */
final class AbandonedSweep
{
    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly ShopSettings $settings,
        private readonly TicketStateMachine $machine,
    ) {}

    /**
     * Run the ladder for one shop.
     *
     * @return array{escalated: int, abandoned: int, considered: int}
     */
    public function run(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();

        $repairs = $this->settings->repairs();

        $escalated = 0;
        $abandoned = 0;

        $waiting = RepairTicket::query()
            ->where('status', TicketStatus::Ready->value)
            ->whereNotNull('ready_at')
            ->orderBy('ready_at')
            ->get();

        foreach ($waiting as $ticket) {
            // Whole days, floored. A device ready at 23:50 yesterday is one day old at
            // 00:10 tonight only if you count calendar boundaries, which is not what a
            // shop means by "it has been a week".
            $daysReady = (int) $ticket->ready_at?->diffInDays($now);

            if ($daysReady >= $repairs->abandonedAfterDays) {
                $this->markAbandoned($ticket, $daysReady);
                $abandoned++;

                continue;
            }

            $step = $repairs->stepFor($daysReady);

            if ($step === null) {
                continue;
            }

            if ($this->announce($ticket, $step, $repairs->escalationDays[$step - 1] ?? $daysReady)) {
                $escalated++;
            }
        }

        return [
            'escalated' => $escalated,
            'abandoned' => $abandoned,
            'considered' => $waiting->count(),
        ];
    }

    /**
     * Record the step, then announce it — and only if the record was ours to make.
     *
     * Returns false when this step has already gone out, which is what makes running the
     * sweep twice harmless.
     */
    private function announce(RepairTicket $ticket, int $step, int $afterDays): bool
    {
        $backfilled = $this->backfill($ticket, $step, $afterDays);

        if (! $backfilled) {
            return false;
        }

        TicketEscalated::dispatch($ticket, $step);

        return true;
    }

    /**
     * Write this step and every earlier one, and say whether THIS step was new.
     *
     * Earlier steps are recorded silently. If a sweep was down for a fortnight the shop
     * genuinely skipped those nudges, and the honest record says they were due and not
     * sent — while the customer's phone is spared three messages in a row for an outage
     * that was not their fault.
     */
    private function backfill(RepairTicket $ticket, int $step, int $afterDays): bool
    {
        $isNew = false;

        for ($earlier = 1; $earlier <= $step; $earlier++) {
            $inserted = $this->insertOnce($ticket, $earlier, $earlier === $step ? $afterDays : 0);

            if ($earlier === $step) {
                $isNew = $inserted;
            }
        }

        return $isNew;
    }

    /**
     * Insert a step, returning false if it was already there.
     *
     * The unique index is the guarantee, not the check. Two workers reaching this line
     * at the same instant both attempt the insert; exactly one succeeds and the other
     * lands here with a violation, which is precisely the outcome wanted.
     */
    private function insertOnce(RepairTicket $ticket, int $step, int $afterDays): bool
    {
        try {
            $this->connection->transaction(function () use ($ticket, $step, $afterDays): void {
                TicketEscalation::query()->create([
                    'repair_ticket_id' => $ticket->getKey(),
                    'step' => $step,
                    'after_days' => $afterDays,
                ]);
            });

            return true;
        } catch (QueryException $exception) {
            // 23505 — unique violation. Anything else is a real failure and must not be
            // mistaken for "already sent".
            if ($exception->getCode() === '23505') {
                return false;
            }

            throw $exception;
        }
    }

    private function markAbandoned(RepairTicket $ticket, int $daysReady): void
    {
        $this->machine->transition(
            $ticket,
            TicketStatus::Abandoned,
            null,
            "پس از {$daysReady} روز از آماده‌شدن، تحویل گرفته نشد",
        );

        TicketAbandoned::dispatch($ticket, $daysReady);
    }
}
