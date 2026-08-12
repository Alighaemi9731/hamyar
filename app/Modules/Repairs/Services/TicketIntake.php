<?php

declare(strict_types=1);

namespace App\Modules\Repairs\Services;

use App\Modules\Inventory\Models\ProductUnit;
use App\Modules\Repairs\Enums\TicketStatus;
use App\Modules\Repairs\Events\TicketCreated;
use App\Modules\Repairs\Models\RepairTicket;
use App\Support\Counters\CounterService;
use App\Support\Imei;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * قبض پذیرش — taking a device in.
 *
 * The moment the module is judged on. Somebody is handing over a phone worth months of
 * their income, and what they get back is a piece of paper. Everything recorded here is
 * either something the shop will need to prove later or something the customer will ring
 * up to ask.
 *
 * ## The device is linked to our own stock when we can, and described when we cannot
 *
 * If the IMEI matches a `product_unit` this shop sold, the ticket points at it — which
 * is what makes the IMEI passport answer "repaired when" (golden rule 4). Most devices
 * on a bench were bought somewhere else, so the brand, model and IMEI are always stored
 * as plain columns too. A ticket that could only exist for our own stock would refuse
 * most of a shop's work.
 *
 * ## Two identifiers, doing different jobs
 *
 * `code` is short, printable and read out over the phone. `tracking_token` is long,
 * random and is what the public URL carries. They are separate because a public page
 * keyed on a sequential code would let anybody enumerate a shop's tickets by counting.
 *
 * ## The passcode
 *
 * Written once, here, into an encrypted column, and never read back by any code path
 * except the audited reveal. This service does not return it, log it, or put it in the
 * event it dispatches.
 */
final class TicketIntake
{
    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly CounterService $counters,
        private readonly TenantContext $context,
        private readonly TicketStateMachine $machine,
    ) {}

    /**
     * @param  array{branch_id: int, party_id?: int|null, device_brand?: string|null, device_model: string, device_imei?: string|null, device_colour?: string|null, reported_issue: string, technician_id?: int|null, priority?: int, promised_at?: \Carbon\CarbonImmutable|null, estimate_amount?: int, prepaid_amount?: int, device_passcode?: string|null, accessories?: list<string>, checklist?: list<array{item_key: string, label: string, answer: string, note?: string|null}>}  $data
     */
    public function take(array $data, ?int $actorId = null): RepairTicket
    {
        $tenantId = $this->context->idOrFail();

        /** @var RepairTicket $ticket */
        $ticket = $this->connection->transaction(function () use ($data, $tenantId, $actorId): RepairTicket {
            $imei = $this->normaliseImei($data['device_imei'] ?? null);

            $ticket = RepairTicket::query()->create([
                'branch_id' => $data['branch_id'],
                'code' => $this->counters->nextFormatted($tenantId, 'repair_ticket', 'REP', $data['branch_id']),
                'party_id' => $data['party_id'] ?? null,
                // Linked when it is a handset we sold — the other half of the passport.
                'product_unit_id' => $this->matchOwnUnit($imei),
                'device_brand' => $data['device_brand'] ?? null,
                'device_model' => $data['device_model'],
                'device_imei' => $imei,
                'device_colour' => $data['device_colour'] ?? null,
                'reported_issue' => $data['reported_issue'],
                'status' => TicketStatus::Queued,
                'technician_id' => $data['technician_id'] ?? null,
                'priority' => $data['priority'] ?? RepairTicket::PRIORITY_NORMAL,
                'promised_at' => $data['promised_at'] ?? null,
                'estimate_amount' => max(0, $data['estimate_amount'] ?? 0),
                'prepaid_amount' => max(0, $data['prepaid_amount'] ?? 0),
                // Encrypted by the cast on the way in. Never read back except through
                // the audited reveal.
                'device_passcode' => $this->passcodeOrNull($data['device_passcode'] ?? null),
                'accessories' => $data['accessories'] ?? [],
                'tracking_token' => $this->freshToken(),
                'intake_by' => $actorId,
            ]);

            $this->recordChecklist($ticket, $data['checklist'] ?? []);

            // The first line of the trail. Without it a repair's history starts
            // mid-story, and "when did you take it in" has no row to point at.
            $this->machine->recordIntake($ticket, $actorId);

            return $ticket;
        });

        // After commit: Phase 8 texts the customer their tracking link from this, and a
        // link to a ticket that rolled back is a phone call the shop has to make.
        TicketCreated::dispatch($ticket);

        return $ticket;
    }

    /**
     * A device we sold, if this is one.
     *
     * Matched on the normalised IMEI so a number typed on a Persian keypad finds the
     * same handset as one scanned off a box.
     */
    private function matchOwnUnit(?string $imei): ?int
    {
        if ($imei === null) {
            return null;
        }

        $id = ProductUnit::query()->matchingCode($imei)->value('id');

        return is_int($id) ? $id : null;
    }

    private function normaliseImei(?string $imei): ?string
    {
        if ($imei === null) {
            return null;
        }

        $digits = Imei::normalise($imei);

        // Stored even when it fails the Luhn check. A counter clerk copying fifteen
        // digits off a cracked screen gets one wrong sometimes, and refusing the intake
        // over it would send a paying customer away. The number is evidence, not a key.
        return $digits === '' ? null : $digits;
    }

    private function passcodeOrNull(?string $passcode): ?string
    {
        if ($passcode === null) {
            return null;
        }

        $trimmed = trim($passcode);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * A token that cannot be guessed from the ticket code.
     *
     * 48 characters from Laravel's CSPRNG. The public tracking page has no login, so
     * this string *is* the access control — the same reasoning as the signed invoice
     * link, arrived at the same way.
     */
    private function freshToken(): string
    {
        return Str::random(48);
    }

    /**
     * @param  list<array{item_key: string, label: string, answer: string, note?: string|null}>  $checklist
     */
    private function recordChecklist(RepairTicket $ticket, array $checklist): void
    {
        $position = 0;

        foreach ($checklist as $item) {
            if (($item['item_key'] ?? '') === '' || ($item['label'] ?? '') === '') {
                throw new RuntimeException('هر ردیف چک‌لیست باید کلید و عنوان داشته باشد.');
            }

            $ticket->checklistAnswers()->create([
                'item_key' => $item['item_key'],
                // Copied, not joined: the template gets edited, and an answer that
                // silently re-labels itself would rewrite what the customer signed.
                'label' => $item['label'],
                'answer' => $item['answer'],
                'note' => $item['note'] ?? null,
                'position' => $position++,
            ]);
        }
    }
}
