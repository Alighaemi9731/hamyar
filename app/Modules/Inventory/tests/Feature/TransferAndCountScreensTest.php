<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Enums\MovementType;
use App\Modules\Inventory\Enums\UnitStatus;
use App\Modules\Inventory\Models\ProductUnit;
use App\Modules\Inventory\Models\StockCount;
use App\Modules\Inventory\Models\StockCountItem;
use App\Modules\Inventory\Models\StockTransfer;
use App\Modules\Inventory\Models\StockTransferItem;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockLedger;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Platform\Services\PlanCatalogueSeeder;
use App\Modules\Platform\Services\SubscriptionResolver;
use App\Modules\Platform\Services\TenantProvisioner;
use App\Support\Tenancy\TenantContext;

/**
 * The transfer and stock-count screens.
 *
 * Both exist to make an awkward truth visible rather than tidy it away: goods in a van
 * belong to neither shop, and a shelf that was not visited is not an empty shelf.
 */
beforeEach(function (): void {
    app(PlanCatalogueSeeder::class)->sync();

    $this->tenant = Tenant::factory()->withDomain()->create();
    $this->url = tenantUrl($this->tenant);

    subscribe($this->tenant, 'pro');
    app(SubscriptionResolver::class)->forget();

    app(TenantProvisioner::class)->seedRoles($this->tenant);

    [$this->keeper, $this->seller] = app(TenantContext::class)->runFor($this->tenant, function (): array {
        $keeper = User::factory()->create();
        $keeper->assignRole('Warehousekeeper');

        $seller = User::factory()->create();
        $seller->assignRole('Salesperson');

        return [$keeper, $seller];
    });

    $this->inTenant = fn (Closure $callback): mixed => inTenantContext($this->tenant, $callback);

    /** @var array{Warehouse, Warehouse, ProductVariant} $fixtures */
    $fixtures = inTenantContext($this->tenant, function (): array {
        $from = Warehouse::factory()->create(['name' => 'انبار مرکزی']);
        $to = Warehouse::factory()->create(['name' => 'انبار شعبه ونک']);
        $product = Product::factory()->create(['name' => 'کابل شارژ', 'type' => 'standard']);
        $variant = ProductVariant::factory()->for($product)->create();

        return [$from, $to, $variant];
    });

    [$this->from, $this->to, $this->variant] = $fixtures;
});

afterEach(fn () => app(TenantContext::class)->forget());

function stockIn(int $variantId, int $warehouseId, int $quantity): void
{
    app(StockLedger::class)->record($variantId, $warehouseId, $quantity, MovementType::Purchase, unitCost: 100_000);
}

/* -------------------------------------------------------------- transfers -- */

it('moves stock in two steps, and it belongs to neither warehouse in between', function (): void {
    ($this->inTenant)(fn () => stockIn($this->variant->id, $this->from->id, 10));

    $this->actingAs($this->keeper)->post($this->url.'/inventory/transfers', [
        'from_warehouse_id' => $this->from->id,
        'to_warehouse_id' => $this->to->id,
    ])->assertRedirect();

    $transfer = ($this->inTenant)(fn () => StockTransfer::query()->firstOrFail());

    $this->actingAs($this->keeper)
        ->post($this->url."/inventory/transfers/{$transfer->id}/lines", [
            'product_variant_id' => $this->variant->id,
            'quantity' => 4,
        ])
        ->assertRedirect();

    $this->actingAs($this->keeper)
        ->post($this->url."/inventory/transfers/{$transfer->id}/dispatch")
        ->assertRedirect();

    ($this->inTenant)(function (): void {
        $ledger = app(StockLedger::class);

        // Left the source, arrived nowhere. A one-step transfer would make these four
        // sellable in two shops at once.
        expect($ledger->onHand($this->variant->id, $this->from->id))->toBe(6);
        expect($ledger->onHand($this->variant->id, $this->to->id))->toBe(0);
    });

    $item = ($this->inTenant)(fn () => StockTransferItem::query()->firstOrFail());

    $this->actingAs($this->keeper)
        ->post($this->url."/inventory/transfers/{$transfer->id}/receive", [
            'counted' => [$item->id => 4],
        ])
        ->assertRedirect();

    ($this->inTenant)(function (): void {
        expect(app(StockLedger::class)->onHand($this->variant->id, $this->to->id))->toBe(4);
    });
});

it('records a shortfall instead of smoothing it away', function (): void {
    ($this->inTenant)(fn () => stockIn($this->variant->id, $this->from->id, 10));

    $this->actingAs($this->keeper)->post($this->url.'/inventory/transfers', [
        'from_warehouse_id' => $this->from->id,
        'to_warehouse_id' => $this->to->id,
    ]);

    $transfer = ($this->inTenant)(fn () => StockTransfer::query()->firstOrFail());

    $this->actingAs($this->keeper)->post($this->url."/inventory/transfers/{$transfer->id}/lines", [
        'product_variant_id' => $this->variant->id,
        'quantity' => 5,
    ]);

    $this->actingAs($this->keeper)->post($this->url."/inventory/transfers/{$transfer->id}/dispatch");

    $item = ($this->inTenant)(fn () => StockTransferItem::query()->firstOrFail());

    // Five sent, three arrived. That is a van, a driver and two missing items — worth
    // recording, not reconciling away.
    $this->actingAs($this->keeper)
        ->post($this->url."/inventory/transfers/{$transfer->id}/receive", [
            'counted' => [$item->id => 3],
        ])
        ->assertRedirect();

    ($this->inTenant)(function () use ($item): void {
        expect($item->refresh()->received_quantity)->toBe(3);
        expect($item->shortfall())->toBe(2);
        expect(app(StockLedger::class)->onHand($this->variant->id, $this->to->id))->toBe(3);
    });
});

it('refuses to put a handset from another warehouse on the transfer', function (): void {
    $unit = ($this->inTenant)(function () {
        $product = Product::factory()->serialized()->create();
        $variant = ProductVariant::factory()->for($product)->create();

        // Sitting in the destination, not the source.
        return ProductUnit::factory()->for($variant, 'variant')->create(['warehouse_id' => $this->to->id]);
    });

    $this->actingAs($this->keeper)->post($this->url.'/inventory/transfers', [
        'from_warehouse_id' => $this->from->id,
        'to_warehouse_id' => $this->to->id,
    ]);

    $transfer = ($this->inTenant)(fn () => StockTransfer::query()->firstOrFail());

    $this->actingAs($this->keeper)
        ->post($this->url."/inventory/transfers/{$transfer->id}/lines", ['product_unit_id' => $unit->id])
        ->assertSessionHasErrors('line');
});

it('will not let a Salesperson move stock', function (): void {
    $this->actingAs($this->seller)
        ->post($this->url.'/inventory/transfers', [
            'from_warehouse_id' => $this->from->id,
            'to_warehouse_id' => $this->to->id,
        ])
        ->assertForbidden();
});

/* ----------------------------------------------------------------- counts -- */

it('keeps the expected quantity off a blind sheet entirely', function (): void {
    ($this->inTenant)(fn () => stockIn($this->variant->id, $this->from->id, 7));

    $this->actingAs($this->keeper)->post($this->url.'/inventory/counts', [
        'warehouse_id' => $this->from->id,
        'is_blind' => true,
    ])->assertRedirect();

    $count = ($this->inTenant)(fn () => StockCount::query()->firstOrFail());

    $this->actingAs($this->keeper)->post($this->url."/inventory/counts/{$count->id}/lines", [
        'product_variant_id' => $this->variant->id,
    ]);

    // Not merely hidden in CSS — absent from the payload. Hiding it in the DOM would
    // put it one devtools panel away from the person blind counting protects.
    $this->actingAs($this->keeper)
        ->get($this->url."/inventory/counts/{$count->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Inventory::Counts/Show')
            ->where('count.reveals_expected', false)
            ->where('lines.0.expected_quantity', null)
            ->where('count.variance', null)
        );
});

it('writes the difference as an adjustment and reveals the variance afterwards', function (): void {
    ($this->inTenant)(fn () => stockIn($this->variant->id, $this->from->id, 7));

    $this->actingAs($this->keeper)->post($this->url.'/inventory/counts', [
        'warehouse_id' => $this->from->id,
        'is_blind' => true,
    ]);

    $count = ($this->inTenant)(fn () => StockCount::query()->firstOrFail());

    $this->actingAs($this->keeper)->post($this->url."/inventory/counts/{$count->id}/lines", [
        'product_variant_id' => $this->variant->id,
    ]);

    $item = ($this->inTenant)(fn () => StockCountItem::query()->firstOrFail());

    // The shelf says five; the system believed seven.
    $this->actingAs($this->keeper)
        ->put($this->url."/inventory/counts/{$count->id}/counted", ['counted' => [$item->id => 5]])
        ->assertRedirect();

    $this->actingAs($this->keeper)
        ->post($this->url."/inventory/counts/{$count->id}/apply")
        ->assertRedirect();

    ($this->inTenant)(function () use ($count): void {
        // The correction stays visible as a movement of -2 rather than a total of 5.
        expect(app(StockLedger::class)->onHand($this->variant->id, $this->from->id))->toBe(5);
        expect($count->refresh()->status)->toBe(StockCount::STATUS_APPLIED);
    });

    $this->actingAs($this->keeper)
        ->get($this->url."/inventory/counts/{$count->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('count.reveals_expected', true)
            ->where('lines.0.expected_quantity', 7)
            ->where('lines.0.variance', -2)
        );
});

it('skips uncounted lines rather than writing them off', function (): void {
    ($this->inTenant)(fn () => stockIn($this->variant->id, $this->from->id, 7));

    $this->actingAs($this->keeper)->post($this->url.'/inventory/counts', [
        'warehouse_id' => $this->from->id,
    ]);

    $count = ($this->inTenant)(fn () => StockCount::query()->firstOrFail());

    $this->actingAs($this->keeper)->post($this->url."/inventory/counts/{$count->id}/lines", [
        'product_variant_id' => $this->variant->id,
    ]);

    // Nothing counted: an unvisited shelf is not an empty shelf.
    $this->actingAs($this->keeper)
        ->post($this->url."/inventory/counts/{$count->id}/apply")
        ->assertRedirect();

    ($this->inTenant)(function (): void {
        expect(app(StockLedger::class)->onHand($this->variant->id, $this->from->id))->toBe(7);
    });
});

/* -------------------------------------------------------------- isolation -- */

it('does not expose another shop transfer or count', function (): void {
    $other = Tenant::factory()->withDomain()->create();

    [$foreignTransfer, $foreignCount] = app(TenantContext::class)->runFor($other, function (): array {
        $from = Warehouse::factory()->create();
        $to = Warehouse::factory()->create();

        return [
            StockTransfer::query()->create([
                'from_warehouse_id' => $from->id,
                'to_warehouse_id' => $to->id,
                'number' => 'TRF-000001',
                'status' => StockTransfer::STATUS_DRAFT,
            ]),
            StockCount::query()->create([
                'warehouse_id' => $from->id,
                'number' => 'CNT-000001',
                'status' => StockCount::STATUS_OPEN,
            ]),
        ];
    });

    $this->actingAs($this->keeper)
        ->get($this->url."/inventory/transfers/{$foreignTransfer->id}")
        ->assertNotFound();

    $this->actingAs($this->keeper)
        ->post($this->url."/inventory/transfers/{$foreignTransfer->id}/dispatch")
        ->assertNotFound();

    $this->actingAs($this->keeper)
        ->get($this->url."/inventory/counts/{$foreignCount->id}")
        ->assertNotFound();

    $this->actingAs($this->keeper)
        ->post($this->url."/inventory/counts/{$foreignCount->id}/apply")
        ->assertNotFound();
})->group('isolation');

it('will not transfer a serialized unit into the quantity ledger', function (): void {
    // Serialized transfers move the unit and deliberately write NO stock movement; a
    // phone counted in both registers is counted twice.
    $unit = ($this->inTenant)(function () {
        $product = Product::factory()->serialized()->create();
        $variant = ProductVariant::factory()->for($product)->create();

        return ProductUnit::factory()->for($variant, 'variant')->create([
            'warehouse_id' => $this->from->id,
            'status' => UnitStatus::InStock,
        ]);
    });

    $this->actingAs($this->keeper)->post($this->url.'/inventory/transfers', [
        'from_warehouse_id' => $this->from->id,
        'to_warehouse_id' => $this->to->id,
    ]);

    $transfer = ($this->inTenant)(fn () => StockTransfer::query()->firstOrFail());

    $this->actingAs($this->keeper)
        ->post($this->url."/inventory/transfers/{$transfer->id}/lines", ['product_unit_id' => $unit->id])
        ->assertRedirect();

    $this->actingAs($this->keeper)->post($this->url."/inventory/transfers/{$transfer->id}/dispatch");

    $item = ($this->inTenant)(fn () => StockTransferItem::query()->firstOrFail());

    $this->actingAs($this->keeper)->post($this->url."/inventory/transfers/{$transfer->id}/receive", [
        'counted' => [$item->id => 1],
    ]);

    ($this->inTenant)(function () use ($unit): void {
        expect($unit->refresh()->warehouse_id)->toBe($this->to->id);
        expect(app(StockLedger::class)->onHand($unit->product_variant_id, $this->to->id))->toBe(0);
    });
});
