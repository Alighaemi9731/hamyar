<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Services;

use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Purchasing\Models\LandedCost;
use App\Modules\Purchasing\Models\PurchaseInvoice;
use App\Modules\Purchasing\Models\PurchaseInvoiceItem;
use App\Modules\Purchasing\Models\PurchaseUnitItem;
use App\Support\Counters\CounterService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\ConnectionInterface;
use RuntimeException;

/**
 * Building a shipment before it is received.
 *
 * Everything here works on a **draft**. Once `ReceivePurchaseInvoice` has run, the
 * document is stock, debt and ten IMEI passports, and editing it would rewrite all
 * three — so every method refuses a received invoice rather than trusting the screen
 * to have hidden the button.
 *
 * Totals are recalculated from the lines on every change and stored, because an
 * invoice must keep saying what it said even after a cost changes underneath it.
 */
final class PurchaseInvoiceDraft
{
    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly CounterService $counters,
        private readonly TenantContext $context,
        private readonly ImeiBatchParser $parser,
    ) {}

    /**
     * Open a new draft, numbered per branch.
     */
    public function open(Warehouse $warehouse, ?int $partyId, ?int $actorId = null): PurchaseInvoice
    {
        /** @var PurchaseInvoice $invoice */
        $invoice = $this->connection->transaction(function () use ($warehouse, $partyId, $actorId): PurchaseInvoice {
            $tenantId = $this->context->id();

            if ($tenantId === null) {
                throw new RuntimeException('A purchase invoice cannot be numbered outside a tenant context.');
            }

            return PurchaseInvoice::query()->create([
                'branch_id' => $warehouse->branch_id,
                'warehouse_id' => $warehouse->getKey(),
                'party_id' => $partyId,
                // Row-locked per branch, never MAX+1: two people opening a shipment at
                // the same moment must not be handed the same number.
                'number' => $this->counters->nextFormatted(
                    $tenantId,
                    'purchase_invoice',
                    'PUR',
                    $warehouse->branch_id,
                ),
                'status' => PurchaseInvoice::STATUS_DRAFT,
                'issued_at' => now(),
                'actor_id' => $actorId,
            ]);
        });

        return $invoice;
    }

    public function addStandardLine(PurchaseInvoice $invoice, int $variantId, int $quantity, int $unitCost): PurchaseInvoiceItem
    {
        $this->guardDraft($invoice);

        /** @var PurchaseInvoiceItem $item */
        $item = PurchaseInvoiceItem::query()->create([
            'purchase_invoice_id' => $invoice->getKey(),
            'product_variant_id' => $variantId,
            'quantity' => $quantity,
            'unit_cost' => $unitCost,
            'line_total' => $unitCost * $quantity,
        ]);

        $this->recalculate($invoice);

        return $item;
    }

    /**
     * Commit a pasted batch of IMEIs as serialized lines.
     *
     * The blob is parsed again HERE rather than trusting the verdicts the screen
     * showed. The screen's parse is a preview; between it and this call another till
     * could have registered one of these handsets, and a client that decides which
     * IMEIs are acceptable is a client that can register a duplicate device.
     *
     * @return array{committed: int, result: array<string, mixed>}
     */
    public function addUnitLines(
        PurchaseInvoice $invoice,
        string $blob,
        int $variantId,
        int $unitCost,
        string $condition = 'new',
        ?string $grade = null,
        bool $skipRejected = false,
    ): array {
        $this->guardDraft($invoice);

        $result = $this->parser->parse($blob);

        if (! $skipRejected && ! $this->parser->isClean($result)) {
            // Nothing is written until the batch is clean or the operator has said, in
            // as many words, to skip the bad rows. A half-received shipment is how
            // stock stops reconciling, and it surfaces weeks later with no way to tell
            // which phone was missed.
            return ['committed' => 0, 'result' => $result];
        }

        /** @var int $committed */
        $committed = $this->connection->transaction(function () use ($invoice, $result, $variantId, $unitCost, $condition, $grade): int {
            foreach ($result['accepted'] as $imei) {
                PurchaseUnitItem::query()->create([
                    'purchase_invoice_id' => $invoice->getKey(),
                    'product_variant_id' => $variantId,
                    'imei1' => $imei,
                    'condition' => $condition,
                    'grade' => $grade,
                    'unit_cost' => $unitCost,
                ]);
            }

            return count($result['accepted']);
        });

        $this->recalculate($invoice);

        return ['committed' => $committed, 'result' => $result];
    }

    public function addLandedCost(PurchaseInvoice $invoice, string $type, int $amount, string $allocation, ?string $description = null): LandedCost
    {
        $this->guardDraft($invoice);

        /** @var LandedCost $cost */
        $cost = LandedCost::query()->create([
            'purchase_invoice_id' => $invoice->getKey(),
            'type' => $type,
            'amount' => $amount,
            'allocation' => $allocation,
            'description' => $description,
        ]);

        $this->recalculate($invoice);

        return $cost;
    }

    /**
     * Drop a line from a draft.
     */
    public function removeLine(PurchaseInvoice $invoice, string $kind, int $lineId): void
    {
        $this->guardDraft($invoice);

        match ($kind) {
            'standard' => PurchaseInvoiceItem::query()
                ->where('purchase_invoice_id', $invoice->getKey())->whereKey($lineId)->delete(),
            'unit' => PurchaseUnitItem::query()
                ->where('purchase_invoice_id', $invoice->getKey())->whereKey($lineId)->delete(),
            'landed' => LandedCost::query()
                ->where('purchase_invoice_id', $invoice->getKey())->whereKey($lineId)->delete(),
            default => throw new RuntimeException("Unknown purchase line kind [{$kind}]."),
        };

        $this->recalculate($invoice);
    }

    /**
     * Sum the lines back onto the header.
     *
     * `landed_total` is included in what the shop owes: the freight and customs on this
     * screen are charges the shipment carried, and leaving them out of the total would
     * credit the supplier less than the shipment cost.
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

    private function guardDraft(PurchaseInvoice $invoice): void
    {
        if (! $invoice->isDraft()) {
            throw new RuntimeException(
                "Purchase invoice {$invoice->number} is {$invoice->status} and can no longer be edited."
            );
        }
    }
}
