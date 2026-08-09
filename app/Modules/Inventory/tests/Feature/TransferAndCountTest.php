<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Inventory\Enums\MovementType;
use App\Modules\Inventory\Enums\UnitStatus;
use App\Modules\Inventory\Models\ProductUnit;
use App\Modules\Inventory\Models\StockCount;
use App\Modules\Inventory\Models\StockCountItem;
use App\Modules\Inventory\Models\StockTransfer;
use App\Modules\Inventory\Models\StockTransferItem;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockCountService;
use App\Modules\Inventory\Services\StockLedger;
use App\Modules\Inventory\Services\TransferService;
use App\Modules\Platform\Models\Tenant;
use App\Support\Tenancy\TenantContext;

function id(object $model): int
{
    /** @var int $key */
    $key = $model->getKey();

    return $key;
}

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->withDomain()->create();
    $this->transfers = app(TransferService::class);
    $this->counts = app(StockCountService::class);
    $this->stock = app(StockLedger::class);
});

afterEach(fn () => app(TenantContext::class)->forget());

/* ------------------------------------------------------------- transfers -- */

it('takes stock out on dispatch and puts it in on receipt', function (): void {
    app(TenantContext::class)->runFor($this->tenant, function (): void {
        $variant = ProductVariant::factory()->create();
        $from = Warehouse::factory()->create();
        $to = Warehouse::factory()->create();

        $this->stock->record(id($variant), id($from), 10, MovementType::Purchase);

        $transfer = StockTransfer::factory()->create([
            'from_warehouse_id' => id($from),
            'to_warehouse_id' => id($to),
        ]);

        StockTransferItem::query()->create([
            'stock_transfer_id' => id($transfer),
            'product_variant_id' => id($variant),
            'quantity' => 4,
        ]);

        $this->transfers->dispatch($transfer);

        // In transit: gone from the source, not yet at the destination. A van full of
        // phones must not be sellable at both ends.
        expect($this->stock->onHand(id($variant), id($from)))->toBe(6);
        expect($this->stock->onHand(id($variant), id($to)))->toBe(0);
        expect($transfer->fresh()?->isInTransit())->toBeTrue();

        $this->transfers->receive($transfer->fresh() ?? $transfer);

        expect($this->stock->onHand(id($variant), id($to)))->toBe(4);
    });
});

it('records a shortfall instead of smoothing it over', function (): void {
    app(TenantContext::class)->runFor($this->tenant, function (): void {
        $variant = ProductVariant::factory()->create();
        $from = Warehouse::factory()->create();
        $to = Warehouse::factory()->create();

        $this->stock->record(id($variant), id($from), 10, MovementType::Purchase);

        $transfer = StockTransfer::factory()->create([
            'from_warehouse_id' => id($from),
            'to_warehouse_id' => id($to),
        ]);

        $item = StockTransferItem::query()->create([
            'stock_transfer_id' => id($transfer),
            'product_variant_id' => id($variant),
            'quantity' => 5,
        ]);

        $this->transfers->dispatch($transfer);
        $this->transfers->receive($transfer->fresh() ?? $transfer, [id($item) => 3]);

        // Three phones dispatched and two received is something to investigate, not an
        // arithmetic problem for the software to smooth over.
        expect($this->stock->onHand(id($variant), id($to)))->toBe(3);
        expect($item->fresh()?->shortfall())->toBe(2);
        // The missing two are not back at the source either — they are simply gone,
        // which is what the ledger should say.
        expect($this->stock->onHand(id($variant), id($from)))->toBe(5);
    });
});

it('refuses to receive more than was dispatched', function (): void {
    app(TenantContext::class)->runFor($this->tenant, function (): void {
        $variant = ProductVariant::factory()->create();
        $from = Warehouse::factory()->create();

        $this->stock->record(id($variant), id($from), 10, MovementType::Purchase);

        $transfer = StockTransfer::factory()->create([
            'from_warehouse_id' => id($from),
            'to_warehouse_id' => id(Warehouse::factory()->create()),
        ]);

        $item = StockTransferItem::query()->create([
            'stock_transfer_id' => id($transfer),
            'product_variant_id' => id($variant),
            'quantity' => 2,
        ]);

        $this->transfers->dispatch($transfer);

        // Stock cannot appear in a van.
        expect(fn () => $this->transfers->receive($transfer->fresh() ?? $transfer, [id($item) => 5]))
            ->toThrow(RuntimeException::class, 'More arrived than was dispatched');
    });
});

it('reserves a serialized device in transit and moves it on arrival', function (): void {
    app(TenantContext::class)->runFor($this->tenant, function (): void {
        $from = Warehouse::factory()->create();
        $to = Warehouse::factory()->create();

        $unit = ProductUnit::factory()->create(['warehouse_id' => id($from)]);
        $variant = ProductVariant::factory()->create();

        $transfer = StockTransfer::factory()->create([
            'from_warehouse_id' => id($from),
            'to_warehouse_id' => id($to),
        ]);

        StockTransferItem::query()->create([
            'stock_transfer_id' => id($transfer),
            'product_variant_id' => id($variant),
            'product_unit_id' => id($unit),
            'quantity' => 1,
        ]);

        $this->transfers->dispatch($transfer);

        // Reserved, not sold: still the shop's asset and still on the valuation, but
        // nobody may sell it.
        expect($unit->fresh()?->status)->toBe(UnitStatus::Reserved);

        $this->transfers->receive($transfer->fresh() ?? $transfer);

        $unit->refresh();

        expect($unit->status)->toBe(UnitStatus::InStock);
        // The location moves with the device, or an IMEI lookup points at the wrong shop.
        expect($unit->warehouse_id)->toBe(id($to));
        expect($unit->histories()->count())->toBe(2);
    });
});

it('refuses to dispatch twice', function (): void {
    app(TenantContext::class)->runFor($this->tenant, function (): void {
        $variant = ProductVariant::factory()->create();
        $from = Warehouse::factory()->create();

        $this->stock->record(id($variant), id($from), 5, MovementType::Purchase);

        $transfer = StockTransfer::factory()->create([
            'from_warehouse_id' => id($from),
            'to_warehouse_id' => id(Warehouse::factory()->create()),
        ]);

        StockTransferItem::query()->create([
            'stock_transfer_id' => id($transfer),
            'product_variant_id' => id($variant),
            'quantity' => 1,
        ]);

        $this->transfers->dispatch($transfer);

        expect(fn () => $this->transfers->dispatch($transfer->fresh() ?? $transfer))
            ->toThrow(RuntimeException::class, 'cannot be dispatched');
    });
});

it('refuses to dispatch stock the source does not have', function (): void {
    app(TenantContext::class)->runFor($this->tenant, function (): void {
        $variant = ProductVariant::factory()->create();
        $from = Warehouse::factory()->create();

        $transfer = StockTransfer::factory()->create([
            'from_warehouse_id' => id($from),
            'to_warehouse_id' => id(Warehouse::factory()->create()),
        ]);

        StockTransferItem::query()->create([
            'stock_transfer_id' => id($transfer),
            'product_variant_id' => id($variant),
            'quantity' => 3,
        ]);

        expect(fn () => $this->transfers->dispatch($transfer))
            ->toThrow(App\Modules\Inventory\Exceptions\InsufficientStock::class);

        // Nothing half-applied.
        expect($transfer->fresh()?->isDraft())->toBeTrue();
    });
});

it('keeps serialized transfers out of the quantity ledger', function (): void {
    app(TenantContext::class)->runFor($this->tenant, function (): void {
        $from = Warehouse::factory()->create();
        $to = Warehouse::factory()->create();
        $variant = ProductVariant::factory()->create();
        $unit = ProductUnit::factory()->create(['warehouse_id' => id($from), 'product_variant_id' => id($variant)]);

        $transfer = StockTransfer::factory()->create([
            'from_warehouse_id' => id($from),
            'to_warehouse_id' => id($to),
        ]);

        StockTransferItem::query()->create([
            'stock_transfer_id' => id($transfer),
            'product_variant_id' => id($variant),
            'product_unit_id' => id($unit),
            'quantity' => 1,
        ]);

        $this->transfers->dispatch($transfer);
        $this->transfers->receive($transfer->fresh() ?? $transfer);

        // Regression. A phone's location is `product_units.warehouse_id`; writing a
        // stock movement for it as well would count the same handset twice — once in the
        // unit register and once in the quantity ledger — and every stock report would
        // then disagree with the shelf by exactly the number of phones transferred.
        expect(App\Modules\Inventory\Models\StockMovement::query()->count())->toBe(0);
        expect($unit->fresh()?->warehouse_id)->toBe(id($to));
    });
});

/* ---------------------------------------------------------- stock counts -- */

it('snapshots the expected quantity when a line is added', function (): void {
    app(TenantContext::class)->runFor($this->tenant, function (): void {
        $variant = ProductVariant::factory()->create();
        $warehouse = Warehouse::factory()->create();

        $this->stock->record(id($variant), id($warehouse), 7, MovementType::Purchase);

        $count = StockCount::factory()->create(['warehouse_id' => id($warehouse)]);
        $item = $this->counts->addLine($count, id($variant));

        // Measured against what the system believed at count time, not a figure that
        // moved while the counting was happening.
        expect($item->expected_quantity)->toBe(7);
    });
});

it('turns a variance into an adjustment movement', function (): void {
    app(TenantContext::class)->runFor($this->tenant, function (): void {
        $variant = ProductVariant::factory()->create();
        $warehouse = Warehouse::factory()->create();

        $this->stock->record(id($variant), id($warehouse), 10, MovementType::Purchase);

        $count = StockCount::factory()->create(['warehouse_id' => id($warehouse)]);
        $item = $this->counts->addLine($count, id($variant));
        $item->update(['counted_quantity' => 8]);

        $written = $this->counts->apply($count->fresh() ?? $count);

        expect($written)->toBe(1);
        expect($this->stock->onHand(id($variant), id($warehouse)))->toBe(8);
        // The correction is a movement of -2, so the shrinkage stays reportable.
        expect($item->fresh()?->variance())->toBe(-2);
    });
});

it('skips uncounted lines rather than writing them off', function (): void {
    app(TenantContext::class)->runFor($this->tenant, function (): void {
        $counted = ProductVariant::factory()->create();
        $missed = ProductVariant::factory()->create();
        $warehouse = Warehouse::factory()->create();

        $this->stock->record(id($counted), id($warehouse), 5, MovementType::Purchase);
        $this->stock->record(id($missed), id($warehouse), 9, MovementType::Purchase);

        $count = StockCount::factory()->create(['warehouse_id' => id($warehouse)]);
        $this->counts->addLine($count, id($counted))->update(['counted_quantity' => 4]);
        $this->counts->addLine($count, id($missed));

        $this->counts->apply($count->fresh() ?? $count);

        // An unvisited shelf is not an empty shelf. Writing off stock because nobody got
        // to it would be the most damaging thing this feature could do.
        expect($this->stock->onHand(id($missed), id($warehouse)))->toBe(9);
        expect($this->stock->onHand(id($counted), id($warehouse)))->toBe(4);
    });
});

it('writes nothing when the count agrees', function (): void {
    app(TenantContext::class)->runFor($this->tenant, function (): void {
        $variant = ProductVariant::factory()->create();
        $warehouse = Warehouse::factory()->create();

        $this->stock->record(id($variant), id($warehouse), 5, MovementType::Purchase);

        $count = StockCount::factory()->create(['warehouse_id' => id($warehouse)]);
        $this->counts->addLine($count, id($variant))->update(['counted_quantity' => 5]);

        expect($this->counts->apply($count->fresh() ?? $count))->toBe(0);
    });
});

it('refuses to apply a count twice', function (): void {
    app(TenantContext::class)->runFor($this->tenant, function (): void {
        $count = StockCount::factory()->create(['warehouse_id' => id(Warehouse::factory()->create())]);

        $this->counts->apply($count);

        expect(fn () => $this->counts->apply($count->fresh() ?? $count))
            ->toThrow(RuntimeException::class, 'already been applied');
    });
});

it('defaults a count to blind', function (): void {
    app(TenantContext::class)->runFor($this->tenant, function (): void {
        $count = StockCount::factory()->create(['warehouse_id' => id(Warehouse::factory()->create())]);

        // A number on the screen is a number people count towards.
        expect($count->is_blind)->toBeTrue();
    });
});

it('totals the shrinkage across a sheet', function (): void {
    app(TenantContext::class)->runFor($this->tenant, function (): void {
        $warehouse = Warehouse::factory()->create();
        $count = StockCount::factory()->create(['warehouse_id' => id($warehouse)]);

        foreach ([[10, 8], [5, 5], [3, 1]] as [$expected, $found]) {
            $variant = ProductVariant::factory()->create();
            $this->stock->record(id($variant), id($warehouse), $expected, MovementType::Purchase);
            $this->counts->addLine($count, id($variant))->update(['counted_quantity' => $found]);
        }

        $count->load('items');

        expect($this->counts->variance($count))->toBe(-4);
    });
});

/* ------------------------------------------------------------- isolation -- */

it('does not leak transfers or counts across tenants', function (): void {
    pest()->group('isolation');

    $other = Tenant::factory()->withDomain()->create();

    app(TenantContext::class)->runFor($this->tenant, function (): void {
        StockTransfer::factory()->create([
            'from_warehouse_id' => id(Warehouse::factory()->create()),
            'to_warehouse_id' => id(Warehouse::factory()->create()),
        ]);
        StockCount::factory()->create(['warehouse_id' => id(Warehouse::factory()->create())]);
    });

    app(TenantContext::class)->runFor($other, function (): void {
        expect(StockTransfer::query()->count())->toBe(0);
        expect(StockCount::query()->count())->toBe(0);
        expect(StockCountItem::query()->count())->toBe(0);
    });
});
