<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Services;

use App\Modules\CRM\Models\Account;
use App\Modules\CRM\Services\LedgerService;
use App\Modules\Inventory\Enums\MovementType;
use App\Modules\Inventory\Enums\UnitStatus;
use App\Modules\Inventory\Events\UnitAcquired;
use App\Modules\Inventory\Models\ProductUnit;
use App\Modules\Inventory\Services\StockLedger;
use App\Modules\Inventory\Services\UnitStateMachine;
use App\Modules\Purchasing\Models\LandedCost;
use App\Modules\Purchasing\Models\PurchaseInvoice;
use App\Modules\Purchasing\Models\PurchaseInvoiceItem;
use App\Modules\Purchasing\Models\PurchaseUnitItem;
use App\Support\Quota\QuotaGuard;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use RuntimeException;

/**
 * Turns a draft shipment into stock, devices and a debt to the supplier.
 *
 * Everything happens here, in **one transaction**: landed costs are allocated, stock
 * movements are written, `product_units` are created with the first line of their
 * passport, and the supplier is credited. A partial receipt is the failure mode this
 * design exists to prevent — stock that exists without a payable, or a payable with no
 * stock, is a discrepancy that surfaces weeks later with nothing to trace it to.
 *
 * Receiving is idempotent by status: a second call on an already-received invoice is
 * refused rather than doubling the stock. Someone double-clicking "receive" on a slow
 * connection is not a rare event.
 */
final class ReceivePurchaseInvoice
{
    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly StockLedger $stock,
        private readonly UnitStateMachine $units,
        private readonly LedgerService $ledger,
        private readonly LandedCostAllocator $allocator,
        private readonly QuotaGuard $quota,
    ) {}

    public function receive(PurchaseInvoice $invoice, ?CarbonImmutable $at = null): PurchaseInvoice
    {
        if ($invoice->isReceived()) {
            throw new RuntimeException("Purchase invoice {$invoice->number} has already been received.");
        }

        if (! $invoice->isDraft()) {
            throw new RuntimeException("Purchase invoice {$invoice->number} is {$invoice->status} and cannot be received.");
        }

        $at ??= CarbonImmutable::now();

        /** @var PurchaseInvoice $received */
        $received = $this->connection->transaction(function () use ($invoice, $at): PurchaseInvoice {
            $invoice->load(['items', 'unitItems', 'landedCosts']);

            if ($invoice->items->isEmpty() && $invoice->unitItems->isEmpty()) {
                throw new RuntimeException("Purchase invoice {$invoice->number} has no lines to receive.");
            }

            /*
            | Both credits, once each, before anything is written.
            |
            | The receipt itself is one `purchasing.invoices`; the devices on it are `n`
            | of `inventory.units`, reserved in a single statement rather than one per
            | IMEI. A twenty-phone delivery would otherwise hold the counter row open
            | across twenty round trips, and — worse — could be refused halfway, leaving
            | ten phones received and ten not, on a document that says twenty.
            |
            | All-or-nothing is the honest shape for a delivery: the shop either took it
            | in or did not.
            */
            $this->quota->consume('purchasing.invoices');

            $devices = $invoice->unitItems->count();

            if ($devices > 0) {
                $this->quota->consume('inventory.units', $devices);
            }

            $this->allocateLandedCosts($invoice);
            $this->receiveStandardLines($invoice, $at);
            $this->receiveSerializedLines($invoice, $at);
            $this->postToSupplierLedger($invoice, $at);

            $invoice->update([
                'status' => PurchaseInvoice::STATUS_RECEIVED,
                'received_at' => $at,
            ]);

            return $invoice;
        });

        return $received;
    }

    /**
     * Spread freight and customs across every line, standard and serialized alike.
     *
     * Both kinds compete for the same charge — a shipment of phones and cases pays one
     * customs bill — so they are allocated together and then split back out.
     */
    private function allocateLandedCosts(PurchaseInvoice $invoice): void
    {
        $total = (int) $invoice->landedCosts->sum(fn (LandedCost $cost): int => $cost->amount);

        if ($total === 0) {
            return;
        }

        // A composite key per line, so standard and serialized rows can share one
        // allocation pass without their ids colliding.
        $lines = [];

        foreach ($invoice->items as $item) {
            $lines[] = ['id' => $this->lineKey('i', $item->id), 'value' => $item->line_total, 'quantity' => $item->quantity];
        }

        foreach ($invoice->unitItems as $unitItem) {
            $lines[] = ['id' => $this->lineKey('u', $unitItem->id), 'value' => $unitItem->unit_cost, 'quantity' => 1];
        }

        // Each charge allocates by its own method — freight might go by quantity while
        // customs goes by value — so they are summed per line rather than in one pass.
        $totals = array_fill_keys(array_column($lines, 'id'), 0);

        foreach ($invoice->landedCosts as $cost) {
            $allocation = $this->allocator->allocate($cost->amount, $lines, $cost->allocation);

            foreach ($allocation as $id => $share) {
                $totals[$id] += $share;
            }
        }

        foreach ($invoice->items as $item) {
            $item->update(['landed_allocation' => $totals[$this->lineKey('i', $item->id)]]);
        }

        foreach ($invoice->unitItems as $unitItem) {
            $unitItem->update(['landed_allocation' => $totals[$this->lineKey('u', $unitItem->id)]]);
        }

        $invoice->update(['landed_total' => $total]);
    }

    /**
     * A key that keeps standard and serialized line ids from colliding in one allocation
     * pass — they are separate tables, so both can be id 1.
     */
    private function lineKey(string $prefix, int $id): string
    {
        return $prefix.':'.$id;
    }

    private function receiveStandardLines(PurchaseInvoice $invoice, CarbonImmutable $at): void
    {
        foreach ($invoice->items as $item) {
            $this->stock->record(
                $item->product_variant_id,
                $invoice->warehouse_id,
                $item->quantity,
                MovementType::Purchase,
                reference: $invoice,
                // The cost that goes into the ledger is the TRUE one, including freight —
                // a valuation built on the supplier's price alone understates the stock.
                unitCost: $item->trueUnitCost(),
                occurredAt: $at,
            );
        }
    }

    /**
     * Create one `ProductUnit` per handset, and open its passport.
     */
    private function receiveSerializedLines(PurchaseInvoice $invoice, CarbonImmutable $at): void
    {
        foreach ($invoice->unitItems as $unitItem) {
            /** @var ProductUnit $unit */
            $unit = ProductUnit::query()->create([
                'product_variant_id' => $unitItem->product_variant_id,
                'warehouse_id' => $invoice->warehouse_id,
                'imei1' => $unitItem->imei1,
                'imei2' => $unitItem->imei2,
                'serial' => $unitItem->serial,
                'status' => UnitStatus::InStock,
                'condition' => $unitItem->condition,
                'grade' => $unitItem->grade,
                'cost' => $unitItem->trueUnitCost(),
                'acquired_from_party_id' => $invoice->party_id,
                'acquired_at' => $at,
            ]);

            // The first line of the IMEI passport. Without it the device's history starts
            // mid-story and "bought from whom" has no answer.
            $this->units->recordAcquisition(
                $unit,
                reference: $invoice,
                note: "دریافت با فاکتور خرید {$invoice->number}",
                // Already reserved for the whole delivery in `receive()`.
                metered: false,
            );

            $unitItem->update(['product_unit_id' => $unit->getKey()]);

            // After the passport row. HAMTA listens: a used or refurbished handset owes an
            // ownership transfer whichever door it came through.
            UnitAcquired::dispatch($unit);
        }
    }

    /**
     * Credit the supplier: the shop now owes them.
     *
     * Skipped when there is no supplier on the invoice — a shop recording opening stock
     * it already owned has nobody to owe.
     */
    private function postToSupplierLedger(PurchaseInvoice $invoice, CarbonImmutable $at): void
    {
        if ($invoice->party_id === null || $invoice->total === 0) {
            return;
        }

        $inventory = Account::query()->where('type', Account::TYPE_INVENTORY)->first();

        if (! $inventory instanceof Account) {
            // Refusing beats posting a supplier credit against itself, which balances
            // arithmetically and records nothing — the worst kind of wrong, because the
            // books look fine.
            throw new RuntimeException(
                'No inventory account exists to post the value of received stock against.'
            );
        }

        // The shop now owes the supplier (credit them) and holds goods worth the same
        // (debit inventory).
        /** @var int $inventoryId */
        $inventoryId = $inventory->getKey();

        $this->ledger->post(
            [
                [
                    'account_id' => $inventoryId,
                    'debit' => $invoice->total,
                    'branch_id' => $invoice->branch_id,
                    'description' => "ارزش کالای دریافتی — فاکتور {$invoice->number}",
                ],
                [
                    'party_id' => $invoice->party_id,
                    'credit' => $invoice->total,
                    'branch_id' => $invoice->branch_id,
                    'description' => "فاکتور خرید {$invoice->number}",
                ],
            ],
            reference: $invoice,
            occurredAt: $at,
        );
    }

    /**
     * Recompute the money columns from the lines.
     *
     * Called before receiving, and whenever a draft is edited. Kept here rather than in
     * a model accessor because the stored totals are a snapshot — an invoice must keep
     * saying what it said even after a price changes underneath it.
     */
    public function recalculate(PurchaseInvoice $invoice): PurchaseInvoice
    {
        $invoice->load(['items', 'unitItems', 'landedCosts']);

        $subtotal = (int) $invoice->items->sum(fn (PurchaseInvoiceItem $item): int => $item->line_total)
            + (int) $invoice->unitItems->sum(fn (PurchaseUnitItem $item): int => $item->unit_cost);

        $landed = (int) $invoice->landedCosts->sum(fn (LandedCost $cost): int => $cost->amount);

        $invoice->update([
            'subtotal' => $subtotal,
            'landed_total' => $landed,
            'total' => max(0, $subtotal - $invoice->discount + $invoice->vat_amount + $landed),
        ]);

        return $invoice;
    }
}
