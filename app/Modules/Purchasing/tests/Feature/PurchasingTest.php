<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\CRM\Models\Account;
use App\Modules\CRM\Models\Party;
use App\Modules\CRM\Services\LedgerService;
use App\Modules\Inventory\Enums\UnitStatus;
use App\Modules\Inventory\Models\Branch;
use App\Modules\Inventory\Models\ProductUnit;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockLedger;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Purchasing\Models\LandedCost;
use App\Modules\Purchasing\Models\PurchaseInvoice;
use App\Modules\Purchasing\Models\PurchaseInvoiceItem;
use App\Modules\Purchasing\Models\PurchaseUnitItem;
use App\Modules\Purchasing\Services\ImeiBatchParser;
use App\Modules\Purchasing\Services\LandedCostAllocator;
use App\Modules\Purchasing\Services\ReceivePurchaseInvoice;
use App\Support\Tenancy\TenantContext;
use Database\Factories\ProductUnitFactory;

function keyOf(object $model): int
{
    /** @var int $id */
    $id = $model->getKey();

    return $id;
}

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->withDomain()->create();
    $this->parser = app(ImeiBatchParser::class);
    $this->allocator = app(LandedCostAllocator::class);
    $this->receiver = app(ReceivePurchaseInvoice::class);
    $this->stock = app(StockLedger::class);
    $this->ledger = app(LedgerService::class);
});

afterEach(fn () => app(TenantContext::class)->forget());

/**
 * A draft shipment ready to receive, with the accounts a real tenant would have.
 *
 * @return array{invoice: PurchaseInvoice, variant: ProductVariant, warehouse: Warehouse}
 */
function draftShipment(int $unitCount = 3, int $unitCost = 50_000_000): array
{
    $branch = Branch::factory()->default()->create();
    $warehouse = Warehouse::factory()->default()->create(['branch_id' => keyOf($branch)]);
    $variant = ProductVariant::factory()->create();

    Account::factory()->create(['name' => 'ارزش موجودی انبار', 'type' => Account::TYPE_INVENTORY]);

    $invoice = PurchaseInvoice::factory()->create([
        'branch_id' => keyOf($branch),
        'warehouse_id' => keyOf($warehouse),
        'party_id' => keyOf(Party::factory()->supplier()->create()),
    ]);

    for ($i = 0; $i < $unitCount; $i++) {
        PurchaseUnitItem::query()->create([
            'purchase_invoice_id' => keyOf($invoice),
            'product_variant_id' => keyOf($variant),
            'imei1' => ProductUnitFactory::validImei(),
            'condition' => 'new',
            'unit_cost' => $unitCost,
        ]);
    }

    app(ReceivePurchaseInvoice::class)->recalculate($invoice);

    return ['invoice' => $invoice->fresh() ?? $invoice, 'variant' => $variant, 'warehouse' => $warehouse];
}

/* ------------------------------------------------------------ imei intake -- */

it('parses a pasted batch in whatever format it arrives', function (): void {
    app(TenantContext::class)->runFor($this->tenant, function (): void {
        $a = ProductUnitFactory::validImei();
        $b = ProductUnitFactory::validImei();
        $c = ProductUnitFactory::validImei();

        // A scanner emits newlines, a WhatsApp message uses commas, a spreadsheet column
        // uses tabs. All three arrive at this textarea.
        $result = $this->parser->parse("{$a}\n{$b} , {$c}");

        expect($result['counts']['accepted'])->toBe(3);
        expect($result['accepted'])->toBe([$a, $b, $c]);
        expect($this->parser->isClean($result))->toBeTrue();
    });
});

it('reads Persian digits in a pasted batch', function (): void {
    app(TenantContext::class)->runFor($this->tenant, function (): void {
        $imei = ProductUnitFactory::validImei();

        // The numbers often arrive inside a Persian document.
        $result = $this->parser->parse(App\Support\Digits::toPersian($imei));

        expect($result['accepted'])->toBe([$imei]);
    });
});

it('reports a bad checksum per line rather than failing the batch', function (): void {
    app(TenantContext::class)->runFor($this->tenant, function (): void {
        $good = ProductUnitFactory::validImei();

        $result = $this->parser->parse("{$good}\n123456789012345");

        // Per-line verdicts let the operator fix one row instead of retyping twenty.
        expect($result['counts']['accepted'])->toBe(1);
        expect($result['counts']['invalid'])->toBe(1);
        expect($result['lines'][1]['status'])->toBe(ImeiBatchParser::STATUS_INVALID);
        expect($this->parser->isClean($result))->toBeFalse();
    });
});

it('flags a number repeated inside the same paste', function (): void {
    app(TenantContext::class)->runFor($this->tenant, function (): void {
        $imei = ProductUnitFactory::validImei();

        // A scanner double-triggering, or a row copied twice. Reporting it beats
        // silently de-duplicating, which hides a miscount.
        $result = $this->parser->parse("{$imei}\n{$imei}");

        expect($result['counts']['accepted'])->toBe(1);
        expect($result['counts']['duplicate_in_batch'])->toBe(1);
    });
});

it('links an already-registered IMEI to the device that holds it', function (): void {
    app(TenantContext::class)->runFor($this->tenant, function (): void {
        $existing = ProductUnit::factory()->create();

        $result = $this->parser->parse((string) $existing->imei1);

        // With the id, so the screen can link to the device rather than leaving the
        // operator to go and search for it.
        expect($result['counts']['exists'])->toBe(1);
        expect($result['lines'][0]['unit_id'])->toBe(keyOf($existing));
    });
});

/* --------------------------------------------------------- landed costs -- */

it('allocates a landed cost by value with the remainder on the largest line', function (): void {
    $lines = [
        ['id' => 'a', 'value' => 1_000_000, 'quantity' => 1],
        ['id' => 'b', 'value' => 2_000_000, 'quantity' => 1],
        ['id' => 'c', 'value' => 3_000_001, 'quantity' => 1],
    ];

    $allocation = $this->allocator->allocate(100_000, $lines);

    // The sum must equal the charge EXACTLY, or the invoice does not reconcile.
    expect(array_sum($allocation))->toBe(100_000);
    // A phone carries more of the customs bill than a case.
    expect($allocation['c'])->toBeGreaterThan($allocation['a']);
});

it('allocates by quantity when asked', function (): void {
    $lines = [
        ['id' => 'a', 'value' => 9_000_000, 'quantity' => 1],
        ['id' => 'b', 'value' => 1_000_000, 'quantity' => 3],
    ];

    $allocation = $this->allocator->allocate(80_000, $lines, LandedCostAllocator::BY_QUANTITY);

    expect(array_sum($allocation))->toBe(80_000);
    // Three items carry three times the freight of one, regardless of value.
    expect($allocation['b'])->toBe(60_000);
    expect($allocation['a'])->toBe(20_000);
});

it('falls back to an even split when every weight is zero', function (): void {
    $lines = [
        ['id' => 'a', 'value' => 0, 'quantity' => 0],
        ['id' => 'b', 'value' => 0, 'quantity' => 0],
    ];

    // Beats dividing by zero, or silently allocating nothing at all.
    $allocation = $this->allocator->allocate(1_000, $lines);

    expect(array_sum($allocation))->toBe(1_000);
});

it('splits a line allocation across its units exactly', function (): void {
    // 100 across 3 units cannot divide evenly; the shares must still sum to 100.
    $shares = $this->allocator->perUnit(100, 3);

    expect($shares)->toHaveCount(3);
    expect(array_sum($shares))->toBe(100);
});

/* ------------------------------------------------------------- receiving -- */

it('receives a shipment into stock, devices and a supplier debt', function (): void {
    app(TenantContext::class)->runFor($this->tenant, function (): void {
        ['invoice' => $invoice, 'warehouse' => $warehouse] = draftShipment(3, 50_000_000);

        $this->receiver->receive($invoice);

        $invoice->refresh();

        expect($invoice->isReceived())->toBeTrue();
        expect(ProductUnit::query()->count())->toBe(3);

        // Each handset exists, is in stock, and knows who it came from — the first line
        // of its passport.
        $unit = ProductUnit::query()->firstOrFail();
        expect($unit->status)->toBe(UnitStatus::InStock);
        expect($unit->warehouse_id)->toBe(keyOf($warehouse));
        expect($unit->acquired_from_party_id)->toBe($invoice->party_id);
        expect($unit->histories()->count())->toBe(1);
        expect($unit->histories()->first()?->from_status)->toBeNull();

        // The supplier is owed the invoice total.
        $supplier = Party::query()->findOrFail($invoice->party_id);
        expect($this->ledger->partyBalance($supplier))->toBe(-$invoice->total);
    });
});

it('records stock movements for standard lines', function (): void {
    app(TenantContext::class)->runFor($this->tenant, function (): void {
        $branch = Branch::factory()->default()->create();
        $warehouse = Warehouse::factory()->default()->create(['branch_id' => keyOf($branch)]);
        $variant = ProductVariant::factory()->create();
        Account::factory()->create(['type' => Account::TYPE_INVENTORY]);

        $invoice = PurchaseInvoice::factory()->create([
            'branch_id' => keyOf($branch),
            'warehouse_id' => keyOf($warehouse),
        ]);

        PurchaseInvoiceItem::query()->create([
            'purchase_invoice_id' => keyOf($invoice),
            'product_variant_id' => keyOf($variant),
            'quantity' => 10,
            'unit_cost' => 500_000,
            'line_total' => 5_000_000,
        ]);

        $this->receiver->recalculate($invoice);
        $this->receiver->receive($invoice->fresh() ?? $invoice);

        expect($this->stock->onHand(keyOf($variant), keyOf($warehouse)))->toBe(10);
    });
});

it('folds landed costs into each unit cost', function (): void {
    app(TenantContext::class)->runFor($this->tenant, function (): void {
        ['invoice' => $invoice] = draftShipment(2, 10_000_000);

        LandedCost::query()->create([
            'purchase_invoice_id' => keyOf($invoice),
            'type' => LandedCost::TYPE_CUSTOMS,
            'amount' => 2_000_000,
            'allocation' => LandedCostAllocator::BY_VALUE,
        ]);

        $this->receiver->recalculate($invoice->fresh() ?? $invoice);
        $this->receiver->receive($invoice->fresh() ?? $invoice);

        // Profit must reflect what the phone cost to have on the shelf, not just what
        // the supplier charged.
        $costs = ProductUnit::query()->pluck('cost')->all();

        expect(array_sum($costs))->toBe(22_000_000);
        expect($costs[0])->toBe(11_000_000);
    });
});

it('refuses to receive the same shipment twice', function (): void {
    app(TenantContext::class)->runFor($this->tenant, function (): void {
        ['invoice' => $invoice] = draftShipment(2);

        $this->receiver->receive($invoice);

        // Someone double-clicking "receive" on a slow connection is not a rare event,
        // and the second click must not double the stock.
        expect(fn () => $this->receiver->receive($invoice->fresh() ?? $invoice))
            ->toThrow(RuntimeException::class, 'already been received');

        expect(ProductUnit::query()->count())->toBe(2);
    });
});

it('refuses to receive an empty shipment', function (): void {
    app(TenantContext::class)->runFor($this->tenant, function (): void {
        $branch = Branch::factory()->default()->create();
        $invoice = PurchaseInvoice::factory()->create([
            'branch_id' => keyOf($branch),
            'warehouse_id' => keyOf(Warehouse::factory()->create(['branch_id' => keyOf($branch)])),
        ]);

        expect(fn () => $this->receiver->receive($invoice))
            ->toThrow(RuntimeException::class, 'no lines to receive');
    });
});

it('leaves nothing behind when receiving fails partway', function (): void {
    app(TenantContext::class)->runFor($this->tenant, function (): void {
        $branch = Branch::factory()->default()->create();
        $warehouse = Warehouse::factory()->default()->create(['branch_id' => keyOf($branch)]);
        $variant = ProductVariant::factory()->create();

        // No inventory account: the ledger posting will fail AFTER units are created.
        $invoice = PurchaseInvoice::factory()->create([
            'branch_id' => keyOf($branch),
            'warehouse_id' => keyOf($warehouse),
            'party_id' => keyOf(Party::factory()->supplier()->create()),
        ]);

        PurchaseUnitItem::query()->create([
            'purchase_invoice_id' => keyOf($invoice),
            'product_variant_id' => keyOf($variant),
            'imei1' => ProductUnitFactory::validImei(),
            'condition' => 'new',
            'unit_cost' => 1_000_000,
        ]);

        $this->receiver->recalculate($invoice);

        expect(fn () => $this->receiver->receive($invoice->fresh() ?? $invoice))
            ->toThrow(RuntimeException::class);

        // Stock without a payable is the discrepancy this transaction exists to prevent.
        expect(ProductUnit::query()->count())->toBe(0);
        expect($invoice->fresh()?->isDraft())->toBeTrue();
    });
});

it('receives opening stock with no supplier and no ledger entry', function (): void {
    app(TenantContext::class)->runFor($this->tenant, function (): void {
        ['invoice' => $invoice] = draftShipment(2);
        $invoice->update(['party_id' => null]);

        $this->receiver->receive($invoice->fresh() ?? $invoice);

        // A shop recording stock it already owned has nobody to owe.
        expect(ProductUnit::query()->count())->toBe(2);
        expect(App\Modules\CRM\Models\LedgerEntry::query()->count())->toBe(0);
    });
});

it('links each intake row to the device it became', function (): void {
    app(TenantContext::class)->runFor($this->tenant, function (): void {
        ['invoice' => $invoice] = draftShipment(2);

        $this->receiver->receive($invoice);

        // The first link in the passport chain: intake row → device.
        // Eager-loaded: lazy loading is disabled project-wide (Phase 0), which is what
        // keeps an N+1 out of a list screen.
        PurchaseUnitItem::query()->with('unit')->get()->each(function (PurchaseUnitItem $item): void {
            expect($item->product_unit_id)->not->toBeNull();
            expect($item->unit?->imei1)->toBe($item->imei1);
        });
    });
});

/* ------------------------------------------------------------- isolation -- */

it('does not leak purchase invoices across tenants', function (): void {
    pest()->group('isolation');

    $other = Tenant::factory()->withDomain()->create();

    app(TenantContext::class)->runFor($this->tenant, fn () => draftShipment(1));
    app(TenantContext::class)->runFor($other, fn () => draftShipment(1));

    // What a competitor pays its suppliers is the single most commercially sensitive
    // number in this product.
    app(TenantContext::class)->runFor(
        $other,
        fn () => expect(PurchaseInvoice::query()->count())->toBe(1)
    );
});
