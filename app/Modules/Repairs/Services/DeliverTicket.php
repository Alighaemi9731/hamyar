<?php

declare(strict_types=1);

namespace App\Modules\Repairs\Services;

use App\Modules\CRM\Models\Account;
use App\Modules\Repairs\Enums\TicketStatus;
use App\Modules\Repairs\Events\TicketDelivered;
use App\Modules\Repairs\Models\RepairTicket;
use App\Modules\Repairs\Models\TicketPart;
use App\Modules\Sales\Enums\InvoiceStatus;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Services\DraftInvoiceWriter;
use App\Modules\Sales\Services\FinaliseInvoice;
use App\Support\Settings\ShopSettings;
use Illuminate\Database\ConnectionInterface;
use RuntimeException;

/**
 * Handing the device back.
 *
 * ## The money goes through Sales, not around it
 *
 * A repair bill is a sale. It needs split payments, change, a cash-box posting, a party
 * ledger entry when it is not settled, an invoice number from the same counter, VAT if
 * the shop charges it, and the same rounding policy — all of which Phase 5 already does,
 * carefully, with tests.
 *
 * So delivery builds a `SalesInvoice` and pushes it through {@see DraftInvoiceWriter}
 * and {@see FinaliseInvoice}, exactly as the till does. There is deliberately **no**
 * repair-specific payment code: a second path would be a second place for the change
 * arithmetic to drift, a second ledger posting to get wrong, and a second thing to fix
 * when Phase 7 changes how cheques settle.
 *
 * ## Repair lines are service lines, and that is what stops a double deduction
 *
 * The parts were consumed when they were fitted — stock left the shelf then, as
 * `repair_consume`. Billing them again with a variant on the line would write a `sale`
 * movement and deduct the same screen twice. So every line on a repair invoice is a
 * service line: it carries a price and no stock subject, and `FinaliseInvoice` writes no
 * movement for it. The cost side lives on `ticket_parts.unit_cost`, which is where
 * repair profit is computed from.
 *
 * ## The prepaid amount is a payment, not a discount
 *
 * Money the customer already handed over at intake. Recording it as a discount would
 * understate the sale and the tax on it — the same distinction the trade-in makes in
 * Sales, and for the same reason.
 */
final class DeliverTicket
{
    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly DraftInvoiceWriter $drafts,
        private readonly FinaliseInvoice $finaliser,
        private readonly TicketStateMachine $machine,
        private readonly TicketParts $parts,
        private readonly ShopSettings $settings,
    ) {}

    /**
     * Settle the bill, hand the device over, and close the ticket.
     *
     * @param  list<array{description: string, amount: int, quantity?: int}>  $labour
     *                                                                                 extra charges beyond the parts
     *                                                                                 already fitted
     * @param  list<array{method: string, amount: int, tendered_amount?: int|null, account_id?: int|null, reference?: string|null}>  $payments
     */
    public function deliver(
        RepairTicket $ticket,
        array $labour,
        array $payments,
        int $warrantyDays = 0,
        ?int $actorId = null,
    ): SalesInvoice {
        // Checked once cheaply so an obviously-closed ticket fails without opening a
        // transaction. The check that actually decides is inside, under the lock.
        $this->guardDeliverable($ticket);

        /** @var SalesInvoice $invoice */
        $invoice = $this->connection->transaction(function () use ($ticket, $labour, $payments, $warrantyDays, $actorId): SalesInvoice {
            /*
            | Lock the ticket FIRST, then re-read and re-check.
            |
            | Two tills pressing "deliver" on the same device at the same moment both
            | hold a stale instance from route-model binding: both read `ready`, both
            | pass the guard above, and both bill the customer. A review probe did
            | exactly that and produced two invoices and a doubled cash posting.
            |
            | The lock is taken before anything is written, and released only when the
            | invoice, the payments and the status change have all committed together —
            | so the loser re-reads `delivered` and is refused.
            */
            $locked = RepairTicket::query()->lockForUpdate()->find($ticket->getKey());

            if (! $locked instanceof RepairTicket) {
                throw new RuntimeException('این تیکت دیگر در سیستم نیست.');
            }

            $ticket->setRawAttributes($locked->getRawOriginal(), true);

            $this->guardDeliverable($ticket);

            $lines = $this->lines($ticket, $labour);

            if ($lines === []) {
                throw new RuntimeException('برای تحویل، دست‌کم یک ردیف هزینه یا قطعه لازم است.');
            }

            $invoice = SalesInvoice::query()->create([
                'branch_id' => $ticket->branch_id,
                'party_id' => $ticket->party_id,
                'salesperson_id' => $ticket->technician_id ?? $actorId,
                'type' => SalesInvoice::TYPE_INVOICE,
                'status' => InvoiceStatus::Draft,
                'notes' => "بابت تعمیر {$ticket->code}",
                'settings_snapshot' => [
                    ...$this->settings->rounding()->toSnapshot(),
                    ...$this->settings->vat()->toSnapshot(),
                    ...$this->settings->print()->toSnapshot(),
                    ...$this->settings->commission()->toSnapshot(),
                ],
            ]);

            // Written through the same door the till uses. Split payments, the change
            // clamp and the account guard all come along for free.
            $this->drafts->write($invoice, $lines, $this->withPrepaid($ticket, $payments), $actorId);

            $this->finaliser->finalise($invoice, $actorId);

            $ticket->forceFill(['warranty_days' => max(0, $warrantyDays)])->save();

            // INSIDE the transaction, and inside the lock. If the status moved after the
            // commit, the loser of a race would re-read `ready` and bill the customer a
            // second time — which is precisely the bug this whole block exists to stop.
            //
            // The machine dispatches its event via `afterCommit`, so nothing is
            // announced until this transaction lands.
            $this->machine->transition(
                $ticket,
                TicketStatus::Delivered,
                $actorId,
                "تحویل شد — فاکتور {$invoice->number}",
            );

            return $invoice;
        });

        // Anything still merely reserved goes back on the shelf. The device is out of the
        // door; a hold on a part nobody fitted would quietly shrink what the till may sell
        // for as long as the row survives.
        $this->parts->releaseAll($ticket, $actorId);

        TicketDelivered::dispatch($ticket, $invoice);

        return $invoice;
    }

    private function guardDeliverable(RepairTicket $ticket): void
    {
        if ($ticket->status->isClosed()) {
            throw new RuntimeException("دستگاه تیکت {$ticket->code} قبلاً تحویل داده شده است.");
        }

        if (! $ticket->status->canTransitionTo(TicketStatus::Delivered)) {
            throw new RuntimeException(
                "تا وقتی وضعیت «{$ticket->status->labelFa()}» است، دستگاه تحویل داده نمی‌شود."
            );
        }
    }

    /**
     * Everything the customer is being charged for.
     *
     * Parts first, in the order they were fitted, then labour. All of them service lines
     * — see the class docblock for why billing a fitted part with its variant would
     * deduct the same screen twice.
     *
     * @param  list<array{description: string, amount: int, quantity?: int}>  $labour
     * @return list<array{unit_id: null, variant_id: null, is_service: true, description: string, quantity: int, unit_price: int, discount_amount: int, cost_snapshot: int}>
     */
    private function lines(RepairTicket $ticket, array $labour): array
    {
        $lines = [];

        $parts = $ticket->parts()
            ->with('variant.product:id,name')
            ->where('state', TicketPart::STATE_CONSUMED)
            ->orderBy('id')
            ->get();

        foreach ($parts as $part) {
            // A part priced at nothing is a goodwill fit — a screen protector thrown in
            // with a repair. Charging a zero line would clutter the customer's invoice
            // with something they are not paying for.
            if ($part->unit_price <= 0) {
                continue;
            }

            $lines[] = [
                'unit_id' => null,
                'variant_id' => null,
                'is_service' => true,
                'description' => $part->variant?->product->name ?? 'قطعه',
                'quantity' => $part->quantity,
                'unit_price' => $part->unit_price,
                'discount_amount' => 0,
                // What the part actually cost, snapshotted when it was fitted. Without
                // it the line reads as pure profit: the Z report overstates the day and
                // the technician is paid commission on the customer's whole bill rather
                // than on what the shop made. Finalisation cannot supply this — a service
                // line has no variant to look a weighted average up from.
                'cost_snapshot' => $part->unit_cost,
            ];
        }

        foreach ($labour as $charge) {
            if ($charge['amount'] <= 0) {
                continue;
            }

            $lines[] = [
                'unit_id' => null,
                'variant_id' => null,
                'is_service' => true,
                'description' => $charge['description'],
                'quantity' => max(1, $charge['quantity'] ?? 1),
                'unit_price' => $charge['amount'],
                'discount_amount' => 0,
                // Labour genuinely costs the shop nothing in goods. The technician's
                // wage is an operating expense, not a cost of what was sold.
                'cost_snapshot' => 0,
            ];
        }

        return $lines;
    }

    /**
     * The money already taken at intake, as a payment on this invoice.
     *
     * A payment rather than a discount, deliberately: the customer paid the full price,
     * part of it earlier. Recording it as a discount would understate both the sale and
     * the tax on it.
     *
     * @param  list<array{method: string, amount: int, tendered_amount?: int|null, account_id?: int|null, reference?: string|null}>  $payments
     * @return list<array{method: string, amount: int, tendered_amount?: int|null, account_id?: int|null, reference?: string|null}>
     */
    private function withPrepaid(RepairTicket $ticket, array $payments): array
    {
        if ($ticket->prepaid_amount <= 0) {
            return $payments;
        }

        return [
            [
                'method' => 'cash',
                'amount' => $ticket->prepaid_amount,
                'account_id' => $this->defaultCashAccountId(),
                'reference' => "پیش‌پرداخت هنگام پذیرش {$ticket->code}",
            ],
            ...$payments,
        ];
    }

    private function defaultCashAccountId(): ?int
    {
        $id = Account::query()
            ->where('type', Account::TYPE_CASH)
            ->where('is_active', true)
            ->orderByDesc('is_default')
            ->value('id');

        return is_int($id) ? $id : null;
    }
}
