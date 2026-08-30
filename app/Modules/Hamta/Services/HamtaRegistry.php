<?php

declare(strict_types=1);

namespace App\Modules\Hamta\Services;

use App\Modules\Hamta\Enums\ChecklistStep;
use App\Modules\Hamta\Enums\HamtaStatus;
use App\Modules\Hamta\Events\HamtaTransferCompleted;
use App\Modules\Hamta\Events\HamtaTransferPending;
use App\Modules\Hamta\Models\HamtaChecklistAnswer;
use App\Modules\Inventory\Enums\UnitCondition;
use App\Modules\Inventory\Models\ProductUnit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The only writer of a device's HAMTA state.
 *
 * ## This module records; it does not integrate
 *
 * همتا has **no public API**. Nothing here calls anything, verifies anything, or knows
 * whether a transfer actually happened — it stores what a member of staff said, with their
 * name and the time on it. Every screen this feeds says so in the same words, because the
 * one failure mode that matters is a shop believing the software handled it.
 *
 * The activation id is the sharp edge: it *looks* like a verification token. It is a string
 * copied off a customer's SMS, stored unvalidated. {@see recordTransfer()} does not check
 * its shape beyond emptiness, deliberately — a format check would imply the format is known
 * to be right, and a rejected id would send a salesperson looking for a bug in the wrong
 * place while the customer waits.
 *
 * ## A device can become pending again after being done
 *
 * Not a bug, and the reason `hamta_status` is about *the current outstanding transfer*
 * rather than about the device's history. A shop buys a used phone (pending), completes the
 * transfer into its own name (done), then sells it (pending again — into the buyer's name).
 * Reading `done` as terminal would leave the second transfer untracked, which is the one the
 * customer walks out of the shop with.
 */
final class HamtaRegistry
{
    /**
     * Conditions that owe a transfer when the device changes hands.
     *
     * Refurbished counts: whatever the shop did to it, the registry still has the previous
     * owner's name against that IMEI.
     *
     * @return list<UnitCondition>
     */
    public static function transferableConditions(): array
    {
        return [UnitCondition::Used, UnitCondition::Refurbished];
    }

    public function requiresTransfer(ProductUnit $unit): bool
    {
        return in_array($unit->condition, self::transferableConditions(), true);
    }

    /**
     * Flag a device as owing a transfer.
     *
     * Idempotent, and it does **not** clear an activation id: a device sold on from stock
     * keeps the record of the transfer that brought it in. Overwriting that would erase the
     * shop's evidence for the earlier hand-over the moment the later one started.
     *
     * @param  string  $reason  `acquired` or `sold`
     */
    public function markPending(ProductUnit $unit, string $reason): bool
    {
        if (! $this->requiresTransfer($unit)) {
            return false;
        }

        if ($unit->hamta_status === HamtaStatus::Pending->value) {
            return false;
        }

        $unit->forceFill([
            'hamta_status' => HamtaStatus::Pending->value,
            'hamta_transferred_at' => null,
        ])->save();

        HamtaTransferPending::dispatch($unit, $reason);

        return true;
    }

    /**
     * Record that a transfer completed.
     *
     * The activation id is optional. A shop often knows the transfer went through — they
     * watched the customer's phone — before the customer forwards the SMS with the id in it,
     * and refusing to record that would leave the pending list showing work that is done.
     */
    public function recordTransfer(
        ProductUnit $unit,
        ?string $activationId = null,
        ?string $note = null,
        ?int $actorId = null,
        ?CarbonImmutable $at = null,
    ): ProductUnit {
        $activationId = $activationId === null ? null : trim($activationId);

        $unit->forceFill([
            'hamta_status' => HamtaStatus::Done->value,
            // Kept if this call did not supply one: a later note must not erase the id
            // recorded earlier.
            'hamta_activation_id' => $activationId === '' || $activationId === null
                ? $unit->hamta_activation_id
                : $activationId,
            'hamta_transferred_at' => $at ?? CarbonImmutable::now(),
            'hamta_note' => $note === null || trim($note) === '' ? $unit->hamta_note : trim($note),
            'hamta_actor_id' => $actorId,
        ])->save();

        HamtaTransferCompleted::dispatch($unit, $unit->hamta_activation_id);

        return $unit;
    }

    /**
     * Reopen a transfer somebody closed by mistake.
     *
     * The activation id and every checklist answer survive. A shop correcting a mis-click
     * must not lose the evidence it already gathered, and the answers are append-only for
     * the same reason.
     */
    public function reopen(ProductUnit $unit, ?string $note = null, ?int $actorId = null): ProductUnit
    {
        $unit->forceFill([
            'hamta_status' => HamtaStatus::Pending->value,
            'hamta_transferred_at' => null,
            'hamta_note' => $note === null || trim($note) === '' ? $unit->hamta_note : trim($note),
            'hamta_actor_id' => $actorId,
        ])->save();

        HamtaTransferPending::dispatch($unit, 'reopened');

        return $unit;
    }

    /**
     * Record answers to checklist steps.
     *
     * Inserted, never updated (see the model). One transaction so a half-saved checklist
     * cannot exist — the record's value in a dispute depends on it being the whole answer
     * somebody gave at one sitting.
     *
     * @param  array<string, array{answer: string, note?: string|null}>  $answers  keyed by step value
     * @return list<HamtaChecklistAnswer>
     */
    public function answerChecklist(ProductUnit $unit, array $answers, ?int $actorId = null): array
    {
        $now = CarbonImmutable::now();
        $written = [];

        DB::transaction(function () use ($unit, $answers, $actorId, $now, &$written): void {
            foreach (ChecklistStep::ordered() as $step) {
                $given = $answers[$step->value] ?? null;

                if ($given === null) {
                    continue;
                }

                $answer = $given['answer'] === HamtaChecklistAnswer::ANSWER_CONFIRMED
                    ? HamtaChecklistAnswer::ANSWER_CONFIRMED
                    : HamtaChecklistAnswer::ANSWER_SKIPPED;

                $written[] = HamtaChecklistAnswer::query()->create([
                    'product_unit_id' => $unit->getKey(),
                    'step' => $step,
                    'answer' => $answer,
                    'note' => $given['note'] ?? null,
                    'actor_id' => $actorId,
                    'answered_at' => $now,
                ]);
            }
        });

        return $written;
    }

    /**
     * The latest answer per step, for rendering the panel.
     *
     * Latest rather than all, because the panel's job is "where does this stand" — the full
     * history is available underneath it. `answered_at` then `id`, so two answers written in
     * the same second still order deterministically.
     *
     * @return array<string, HamtaChecklistAnswer>
     */
    public function latestAnswers(ProductUnit $unit): array
    {
        $rows = HamtaChecklistAnswer::query()
            ->where('product_unit_id', $unit->getKey())
            ->orderBy('answered_at')
            ->orderBy('id')
            ->get();

        $latest = [];

        foreach ($rows as $row) {
            $latest[$row->step->value] = $row;
        }

        return $latest;
    }
}
