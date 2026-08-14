<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services;

use App\Modules\CRM\Models\LedgerEntry;
use App\Modules\CRM\Services\LedgerService;
use App\Modules\Inventory\Enums\MovementType;
use App\Modules\Inventory\Enums\UnitStatus;
use App\Modules\Inventory\Models\ProductUnit;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockLedger;
use App\Modules\Inventory\Services\UnitStateMachine;
use App\Modules\Sales\Contracts\InvoiceSettlementGuard;
use App\Modules\Sales\Enums\InvoiceStatus;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesInvoiceItem;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use RuntimeException;

/**
 * Cancelling an invoice that should never have been issued.
 *
 * ## Void is not a return, and the difference is the money
 *
 * A **return** is a real event: the customer had the phone, brought it back, and the
 * shop owes them a refund. It gets its own numbered credit document, and the handset
 * comes back graded because a week in somebody's pocket changes what it is worth.
 *
 * A **void** says the sale never happened — the wrong customer was selected, the wrong
 * phone scanned, the invoice issued twice. Nothing left the shop. So the goods go
 * straight back on the shelf at the grade they left at, and the money is un-posted
 * rather than refunded.
 *
 * Using void to undo a real return would tell the ledger a customer was never charged,
 * while they are standing outside holding the phone. So this refuses any invoice that
 * already has a return against it, and shops keep `sales.void` to an owner.
 *
 * ## The number survives
 *
 * A tax invoice number that disappears is a gap the taxman asks about. The row stays,
 * `status = void`, with who cancelled it, when and why.
 *
 * ## Nothing is deleted, anywhere
 *
 * The ledger is reversed by posting its mirror image (golden rule 3), stock comes back
 * as new movements in the opposite direction, and the handset's passport gains two more
 * lines rather than losing the ones it had. A voided sale is a thing that happened to
 * this shop, and every register says so.
 */
final class VoidInvoice
{
    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly StockLedger $stock,
        private readonly UnitStateMachine $units,
        private readonly LedgerService $ledger,
        private readonly InvoiceSettlementGuard $settlement,
    ) {}

    public function void(SalesInvoice $invoice, string $reason, ?int $actorId = null): SalesInvoice
    {
        $this->guardVoidable($invoice, $reason);

        $at = CarbonImmutable::now();

        /** @var SalesInvoice $voided */
        $voided = $this->connection->transaction(function () use ($invoice, $reason, $actorId, $at): SalesInvoice {
            $invoice->load('items');

            $warehouse = $this->warehouseFor($invoice);

            foreach ($invoice->items as $item) {
                $this->restore($invoice, $item, $warehouse, $reason, $at, $actorId);
            }

            $this->reverseLedger($invoice, $reason);

            $invoice->update([
                'status' => InvoiceStatus::Void,
                'voided_at' => $at,
                'voided_by' => $actorId,
                'void_reason' => $reason,
                // The money never arrived, so nothing is paid. The payment rows stay:
                // what a cashier recorded before the mistake was noticed is part of the
                // story, and the Z-report needs to see both sides of it.
                'paid_total' => 0,
            ]);

            return $invoice;
        });

        return $voided;
    }

    private function guardVoidable(SalesInvoice $invoice, string $reason): void
    {
        if (trim($reason) === '') {
            throw new RuntimeException('برای ابطال فاکتور باید دلیل آن را بنویسید.');
        }

        if ($invoice->status === InvoiceStatus::Void) {
            throw new RuntimeException("فاکتور {$invoice->number} قبلاً ابطال شده است.");
        }

        if (! $invoice->isFinal()) {
            // A draft has no number, no stock effect and no ledger entry. Deleting it is
            // the right verb, and calling that "void" would put unnumbered rows in the
            // cancelled-invoices list that the taxman reads as gaps.
            throw new RuntimeException('پیش‌نویس ابطال نمی‌شود — آن را حذف کنید.');
        }

        if ($invoice->returns()->exists()) {
            throw new RuntimeException(
                'این فاکتور برگشت از فروش دارد و ابطال نمی‌شود. برای اصلاح، برگشت را ثبت یا حذف کنید.'
            );
        }

        /*
        | Settlement paper that is still live blocks the void.
        |
        | `reverseLedger()` below reverses only batches referencing a `SalesInvoice`. A
        | cheque posts its own batch against a `Cheque`, so without this guard voiding a
        | cheque-paid invoice credits the customer the whole amount and leaves the cheque
        | asset standing — the shop owing a customer whose paper is still in its drawer.
        |
        | Refused rather than unwound automatically, deliberately. Unwinding would silently
        | undo an endorsement already given to a supplier, or a deposit sitting at a bank,
        | and the shop would hear about it from the supplier. The paper is a physical thing
        | somebody has to hand back first — the same reasoning as the returns guard above.
        |
        | Asked through an interface Sales owns, so Sales does not depend on the Cheques
        | module (golden rule 6).
        */
        $blocking = $this->settlement->blockingReason($invoice);

        if ($blocking !== null) {
            throw new RuntimeException($blocking);
        }
    }

    /**
     * Put one line's goods back.
     */
    private function restore(
        SalesInvoice $invoice,
        SalesInvoiceItem $item,
        Warehouse $warehouse,
        string $reason,
        CarbonImmutable $at,
        ?int $actorId,
    ): void {
        if ($item->product_unit_id !== null) {
            $unit = ProductUnit::query()->lockForUpdate()->find($item->product_unit_id);

            if (! $unit instanceof ProductUnit) {
                // The row is gone from under a numbered invoice. Refusing beats voiding
                // an invoice whose goods cannot be accounted for.
                throw new RuntimeException('دستگاه این فاکتور دیگر در سیستم نیست؛ ابطال ممکن نیست.');
            }

            // Already back on the shelf — a void retried after a partial failure, or a
            // repair that pulled the device off the sold list. Re-asserting is harmless
            // and the state machine writes no history for a no-op transition.
            if ($unit->status === UnitStatus::InStock) {
                return;
            }

            // Two steps, because `sold → in_stock` is refused by design: undoing a sale
            // is a return, and the machine will not let a status flip pretend otherwise.
            // The passport therefore reads "sold, returned, back in stock", which is
            // exactly what happened.
            if ($unit->status === UnitStatus::Sold) {
                $this->units->transition(
                    $unit,
                    UnitStatus::Returned,
                    reference: $invoice,
                    note: "ابطال فاکتور {$invoice->number}: {$reason}",
                    actorId: $actorId,
                );
            }

            $this->units->transition(
                $unit,
                UnitStatus::InStock,
                reference: $invoice,
                // Back at the grade it left at — nothing happened to it. A return would
                // re-grade here; a void has nothing to re-grade.
                note: "بازگشت به موجودی پس از ابطال فاکتور {$invoice->number}",
                actorId: $actorId,
            );

            return;
        }

        if ($item->product_variant_id === null) {
            return;
        }

        $this->stock->record(
            $item->product_variant_id,
            $warehouse->id,
            $item->quantity,
            MovementType::Return,
            reference: $invoice,
            // The cost the sale took it out at, so the weighted average lands back where
            // it started. Putting it back at today's cost would move the average on a
            // transaction that, by definition, did not happen.
            unitCost: $item->cost_snapshot,
            occurredAt: $at,
            actorId: $actorId,
        );
    }

    /**
     * Post the mirror image of everything this invoice put in the ledger.
     */
    private function reverseLedger(SalesInvoice $invoice, string $reason): void
    {
        $batches = LedgerEntry::query()
            ->where('reference_type', SalesInvoice::class)
            ->where('reference_id', $invoice->getKey())
            ->distinct()
            ->pluck('batch_id')
            ->filter(fn (mixed $batchId): bool => is_string($batchId) && $batchId !== '');

        foreach ($batches as $batchId) {
            /** @var string $batchId */
            $this->ledger->reverse($batchId, "ابطال فاکتور {$invoice->number}: {$reason}");
        }
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
