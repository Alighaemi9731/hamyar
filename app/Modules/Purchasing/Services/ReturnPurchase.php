<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Services;

use App\Modules\CRM\Models\Account;
use App\Modules\CRM\Services\LedgerService;
use App\Modules\Inventory\Enums\MovementType;
use App\Modules\Inventory\Enums\UnitStatus;
use App\Modules\Inventory\Models\ProductUnit;
use App\Modules\Inventory\Services\StockLedger;
use App\Modules\Inventory\Services\UnitStateMachine;
use App\Modules\Purchasing\Models\PurchaseInvoice;
use App\Modules\Purchasing\Models\PurchaseReturn;
use App\Modules\Purchasing\Models\PurchaseReturnItem;
use App\Support\Counters\CounterService;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use RuntimeException;

/**
 * Sending goods back to the supplier.
 *
 * The exact mirror of receiving, and deliberately a **new document** rather than an
 * edit of the original: the shipment did arrive, and the return is a second event with
 * its own date. Editing the purchase down would rewrite a month that may already be
 * closed and would leave nothing to explain the stock movement.
 *
 * A returned handset becomes `written_off` rather than being deleted. The device
 * existed here, its IMEI was registered to this shop, and its passport has to keep
 * saying so — the alternative is a hole exactly where someone will later look.
 */
final class ReturnPurchase
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
     * @param  list<array{variant_id?: int, unit_id?: int, quantity?: int, unit_cost?: int}>  $lines
     */
    public function return(PurchaseInvoice $invoice, array $lines, ?string $reason = null, ?int $actorId = null): PurchaseReturn
    {
        if (! $invoice->isReceived()) {
            throw new RuntimeException("Purchase invoice {$invoice->number} has not been received, so nothing can be returned.");
        }

        if ($lines === []) {
            throw new RuntimeException('A purchase return needs at least one line.');
        }

        $tenantId = $this->context->id();

        if ($tenantId === null) {
            throw new RuntimeException('A purchase return cannot be numbered outside a tenant context.');
        }

        $at = CarbonImmutable::now();

        /** @var PurchaseReturn $return */
        $return = $this->connection->transaction(function () use ($invoice, $lines, $reason, $actorId, $tenantId, $at): PurchaseReturn {
            $return = PurchaseReturn::query()->create([
                'purchase_invoice_id' => $invoice->getKey(),
                'party_id' => $invoice->party_id,
                'number' => $this->counters->nextFormatted($tenantId, 'purchase_return', 'PRT', $invoice->branch_id),
                'reason' => $reason,
                'returned_at' => $at,
                'actor_id' => $actorId,
            ]);

            $total = 0;

            foreach ($lines as $line) {
                $total += $this->returnLine($invoice, $return, $line, $at);
            }

            $return->update(['total' => $total]);

            $this->postToSupplierLedger($invoice, $return, $total, $at);

            return $return;
        });

        return $return;
    }

    /**
     * @param  array{variant_id?: int, unit_id?: int, quantity?: int, unit_cost?: int}  $line
     * @return int the value returned, in rial
     */
    private function returnLine(PurchaseInvoice $invoice, PurchaseReturn $return, array $line, CarbonImmutable $at): int
    {
        if (isset($line['unit_id'])) {
            /** @var ProductUnit $unit */
            $unit = ProductUnit::query()->findOrFail($line['unit_id']);

            // Serialized stock never touched the quantity ledger on the way in, so it
            // must not touch it on the way out either — the device leaves by changing
            // state, which is what its passport records.
            $this->units->transition(
                $unit,
                UnitStatus::WrittenOff,
                reference: $return,
                note: 'برگشت به تأمین‌کننده',
            );

            PurchaseReturnItem::query()->create([
                'purchase_return_id' => $return->getKey(),
                'product_variant_id' => $unit->product_variant_id,
                'product_unit_id' => $unit->getKey(),
                'quantity' => 1,
                'unit_cost' => $unit->cost,
            ]);

            return $unit->cost;
        }

        if (! isset($line['variant_id'], $line['quantity'], $line['unit_cost'])) {
            throw new RuntimeException('A standard return line needs a variant, a quantity and a cost.');
        }

        $this->stock->record(
            $line['variant_id'],
            $invoice->warehouse_id,
            -$line['quantity'],
            MovementType::Adjustment,
            reference: $return,
            unitCost: $line['unit_cost'],
            occurredAt: $at,
        );

        PurchaseReturnItem::query()->create([
            'purchase_return_id' => $return->getKey(),
            'product_variant_id' => $line['variant_id'],
            'quantity' => $line['quantity'],
            'unit_cost' => $line['unit_cost'],
        ]);

        return $line['unit_cost'] * $line['quantity'];
    }

    /**
     * The shop owes the supplier less now: debit them, credit inventory.
     *
     * The exact reverse of the posting `ReceivePurchaseInvoice` made, and skipped for
     * the same reason — opening stock with no supplier has nobody to owe.
     */
    private function postToSupplierLedger(PurchaseInvoice $invoice, PurchaseReturn $return, int $total, CarbonImmutable $at): void
    {
        if ($invoice->party_id === null || $total === 0) {
            return;
        }

        $inventory = Account::query()->where('type', Account::TYPE_INVENTORY)->first();

        if (! $inventory instanceof Account) {
            throw new RuntimeException('No inventory account exists to reverse the value of returned stock against.');
        }

        /** @var int $inventoryId */
        $inventoryId = $inventory->getKey();

        $this->ledger->post(
            [
                ['party_id' => $invoice->party_id, 'debit' => $total, 'description' => "برگشت از خرید {$return->number}"],
                ['account_id' => $inventoryId, 'credit' => $total, 'description' => "برگشت از خرید {$return->number}"],
            ],
            reference: $return,
            occurredAt: $at,
        );
    }
}
