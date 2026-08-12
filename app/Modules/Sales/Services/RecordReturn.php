<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services;

use App\Modules\CRM\Models\Account;
use App\Modules\CRM\Services\LedgerService;
use App\Modules\Inventory\Enums\MovementType;
use App\Modules\Inventory\Enums\UnitStatus;
use App\Modules\Inventory\Models\ProductUnit;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockLedger;
use App\Modules\Inventory\Services\UnitStateMachine;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesInvoiceItem;
use App\Modules\Sales\Models\SalesReturn;
use App\Modules\Sales\Models\SalesReturnItem;
use App\Support\Counters\CounterService;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use RuntimeException;

/**
 * The customer brought it back.
 *
 * ## A return is a new document, never an edit of the sale
 *
 * The sale happened. The customer paid, took the phone home, and used it for nine days.
 * Editing the original invoice to say otherwise rewrites a month that may already be
 * closed, and it destroys the only record of what the shop actually did. So a return is
 * its own numbered credit document — `RET-000001` — pointing at the invoice it reverses
 * part of.
 *
 * This is also why {@see VoidInvoice} refuses an invoice that has returns against it:
 * voiding one would claim the customer was never charged, while they are standing
 * outside holding a refund.
 *
 * ## Partial by default
 *
 * A basket of four things comes back as one thing far more often than as four. Every
 * line names how many of it are coming back, and the running total of prior returns is
 * checked so the same charger cannot be refunded twice — computed from the return rows
 * rather than stored on the line, because a stored counter is one more number that can
 * disagree with the documents beneath it (golden rule 3).
 *
 * ## A returned handset is not immediately sellable again
 *
 * It goes to `returned`, not to `in_stock`. Nine days in somebody's pocket changes what
 * a phone is worth, and putting it straight back on the shelf at its old grade is how a
 * shop sells a scratched handset as new. The person taking it back records the grade
 * they are giving it, and only then does it go back into stock — or to `written_off` if
 * it came back broken.
 *
 * ## The refund goes where the money went
 *
 * If the sale was unpaid, the return reduces what the customer owes. If it was paid,
 * the shop owes them — a credit on their account, settled in cash by Treasury (Phase 7).
 * Handing cash back out of the drawer here would post a payment the till never made.
 */
final class RecordReturn
{
    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly StockLedger $stock,
        private readonly UnitStateMachine $units,
        private readonly LedgerService $ledger,
        private readonly CounterService $counters,
        private readonly TenantContext $context,
    ) {}

    /**
     * @param  list<array{item_id: int, quantity: int, refund_amount?: int|null, regrade?: string|null, restock?: bool}>  $lines
     */
    public function record(SalesInvoice $invoice, array $lines, ?string $reason = null, ?int $actorId = null): SalesReturn
    {
        $this->guardReturnable($invoice, $lines);

        $tenantId = $this->context->idOrFail();
        $at = CarbonImmutable::now();

        /** @var SalesReturn $return */
        $return = $this->connection->transaction(function () use ($invoice, $lines, $reason, $actorId, $tenantId, $at): SalesReturn {
            $invoice->load(['items', 'returns.items']);

            $number = $this->counters->nextFormatted($tenantId, 'sales_return', 'RET', $invoice->branch_id);

            $return = SalesReturn::query()->create([
                'sales_invoice_id' => $invoice->id,
                'party_id' => $invoice->party_id,
                'number' => $number,
                'total' => 0,
                'reason' => $reason,
                'returned_at' => $at,
                'actor_id' => $actorId,
            ]);

            $warehouse = $this->warehouseFor($invoice);
            $total = 0;

            foreach ($lines as $line) {
                $total += $this->restoreLine($invoice, $return, $line, $warehouse, $at, $actorId);
            }

            $return->forceFill(['total' => $total])->save();

            $this->postRefund($invoice, $return, $total, $at, $actorId);

            return $return;
        });

        return $return;
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    private function guardReturnable(SalesInvoice $invoice, array $lines): void
    {
        if (! $invoice->isFinal()) {
            throw new RuntimeException('فقط از فاکتور نهایی‌شده می‌توان برگشت ثبت کرد.');
        }

        if ($lines === []) {
            throw new RuntimeException('هیچ ردیفی برای برگشت انتخاب نشده است.');
        }
    }

    /**
     * One returned line: the goods back, the refund counted.
     *
     * @param  array{item_id: int, quantity: int, refund_amount?: int|null, regrade?: string|null, restock?: bool}  $line
     * @return int the refund for this line, in rial
     */
    private function restoreLine(
        SalesInvoice $invoice,
        SalesReturn $return,
        array $line,
        Warehouse $warehouse,
        CarbonImmutable $at,
        ?int $actorId,
    ): int {
        $item = $invoice->items->firstWhere('id', $line['item_id']);

        if (! $item instanceof SalesInvoiceItem) {
            throw new RuntimeException('ردیف انتخاب‌شده به این فاکتور تعلق ندارد.');
        }

        $quantity = max(1, $line['quantity']);
        $alreadyReturned = $this->alreadyReturned($invoice, $item->id);

        if ($alreadyReturned + $quantity > $item->quantity) {
            $remaining = $item->quantity - $alreadyReturned;

            throw new RuntimeException(
                "از «{$item->description}» فقط {$remaining} عدد قابل برگشت است."
            );
        }

        // Defaults to what that many of it were sold for, VAT included — the customer
        // hands back the goods and gets back what they paid. An explicit amount is
        // allowed for the restocking-fee case, and is never more than they paid.
        $proportional = intdiv($item->line_total * $quantity, max(1, $item->quantity));
        $refund = min($proportional, max(0, $line['refund_amount'] ?? $proportional));

        $return->items()->create([
            'sales_invoice_item_id' => $item->id,
            'quantity' => $quantity,
            'refund_amount' => $refund,
            'regrade' => $line['regrade'] ?? null,
        ]);

        $this->returnGoods($invoice, $item, $line, $quantity, $warehouse, $at, $actorId);

        return $refund;
    }

    /**
     * Put the goods back — as a graded device, or as quantity.
     *
     * @param  array{item_id: int, quantity: int, refund_amount?: int|null, regrade?: string|null, restock?: bool}  $line
     */
    private function returnGoods(
        SalesInvoice $invoice,
        SalesInvoiceItem $item,
        array $line,
        int $quantity,
        Warehouse $warehouse,
        CarbonImmutable $at,
        ?int $actorId,
    ): void {
        if ($item->product_unit_id !== null) {
            $unit = ProductUnit::query()->lockForUpdate()->find($item->product_unit_id);

            if (! $unit instanceof ProductUnit) {
                throw new RuntimeException('دستگاه این ردیف دیگر در سیستم نیست.');
            }

            // Back into the shop's hands, but not onto the shelf: `returned` is a real
            // state that means "here, and not yet sellable".
            $this->units->transition(
                $unit,
                UnitStatus::Returned,
                reference: $invoice,
                note: "برگشت از فروش فاکتور {$invoice->number}",
                actorId: $actorId,
            );

            $regrade = $line['regrade'] ?? null;

            if (is_string($regrade) && $regrade !== '') {
                // The grade the person taking it back gave it. Stored on the unit, so
                // the next sale quotes the phone that came back rather than the phone
                // that left.
                $unit->forceFill(['grade' => $regrade])->save();
            }

            // Only when somebody has actually looked at it. `restock` is the checker
            // saying "this is sellable again"; without it the device stays in the
            // returned pile, which is where an unexamined handset belongs.
            if (($line['restock'] ?? false) === true) {
                $this->units->transition(
                    $unit,
                    UnitStatus::InStock,
                    reference: $invoice,
                    note: $regrade === null
                        ? 'بازبینی شد و به موجودی برگشت'
                        : "بازبینی شد، درجه {$regrade}، به موجودی برگشت",
                    actorId: $actorId,
                );
            }

            return;
        }

        if ($item->product_variant_id === null) {
            return;
        }

        $this->stock->record(
            $item->product_variant_id,
            $warehouse->id,
            $quantity,
            MovementType::Return,
            reference: $invoice,
            // Back in at what it went out at, so the weighted average is not moved by a
            // transaction that added no goods to the shop at a new price.
            unitCost: $item->cost_snapshot,
            occurredAt: $at,
            actorId: $actorId,
        );
    }

    /**
     * How many of this line have come back on earlier returns.
     */
    private function alreadyReturned(SalesInvoice $invoice, int $itemId): int
    {
        return $invoice->returns
            ->flatMap(fn (SalesReturn $return) => $return->items)
            ->where('sales_invoice_item_id', $itemId)
            ->sum(fn (SalesReturnItem $item): int => $item->quantity);
    }

    /**
     * The money side: revenue reversed, and the customer put back in credit.
     */
    private function postRefund(
        SalesInvoice $invoice,
        SalesReturn $return,
        int $total,
        CarbonImmutable $at,
        ?int $actorId,
    ): void {
        if ($total <= 0) {
            return;
        }

        $sales = Account::query()->where('type', Account::TYPE_SALES)->first();

        if (! $sales instanceof Account) {
            throw new RuntimeException('حساب فروش تعریف نشده است؛ برگشت جایی برای ثبت ندارد.');
        }

        // Revenue comes back off the sales account. The other side depends on who is
        // holding the money now.
        $lines = [[
            'account_id' => $sales->id,
            'branch_id' => $invoice->branch_id,
            'debit' => $total,
            'description' => "برگشت از فروش {$return->number}",
        ]];

        if ($invoice->party_id !== null) {
            // Credited to the customer: it either cancels what they still owe or leaves
            // the shop owing them, and `partyBalance()` reads the difference correctly
            // either way. Paying it out in cash is a Treasury act (Phase 7), not
            // something a return silently does to the drawer.
            $lines[] = [
                'party_id' => $invoice->party_id,
                'branch_id' => $invoice->branch_id,
                'credit' => $total,
                'description' => "برگشت از فروش {$return->number}",
            ];
        } else {
            // A walk-in with no account. The refund left the drawer at the counter, so
            // the cash box is where it comes from — there is nobody to credit.
            $cash = Account::query()
                ->where('type', Account::TYPE_CASH)
                ->orderByDesc('is_default')
                ->first();

            if (! $cash instanceof Account) {
                throw new RuntimeException('صندوقی برای پرداخت وجه برگشتی تعریف نشده است.');
            }

            $lines[] = [
                'account_id' => $cash->id,
                'branch_id' => $invoice->branch_id,
                'credit' => $total,
                'description' => "برگشت از فروش {$return->number} — پرداخت نقدی به مشتری",
            ];
        }

        /** @var list<array{party_id?: int|null, account_id?: int|null, debit?: int, credit?: int, description?: string|null, branch_id?: int|null}> $lines */
        $this->ledger->post($lines, reference: $return, occurredAt: $at, actorId: $actorId);
    }

    private function warehouseFor(SalesInvoice $invoice): Warehouse
    {
        $warehouse = Warehouse::query()
            ->where('branch_id', $invoice->branch_id)
            ->where('is_active', true)
            ->where('is_sellable', true)
            ->orderByDesc('is_default')
            ->first();

        if (! $warehouse instanceof Warehouse) {
            throw new RuntimeException('این شعبه انبار قابل فروشی ندارد؛ کالا جایی برای بازگشت ندارد.');
        }

        return $warehouse;
    }
}
