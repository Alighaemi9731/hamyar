<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Enums\MovementType;
use App\Modules\Inventory\Models\StockReservation;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockLedger;
use App\Modules\Inventory\Services\StockReservations;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Sales\Enums\InvoiceStatus;
use App\Modules\Sales\Models\SalesInvoice;
use App\Support\Tenancy\TenantContext;

/**
 * Holds, as an Inventory concept rather than a Repairs one.
 *
 * `TicketPartsTest` covers the repair bench's use of this. What is tested here is the
 * property that made it belong in Inventory: the contract is generic, so the other
 * sources of a hold — a parked sale, an in-transit transfer, a held storefront order —
 * can adopt it without a schema change.
 */
beforeEach(function (): void {
    $this->tenant = Tenant::factory()->withDomain()->create();

    /** @var array{User, Warehouse, ProductVariant} $fixtures */
    $fixtures = inTenantContext($this->tenant, function (): array {
        $warehouse = Warehouse::factory()->create(['is_sellable' => true, 'is_default' => true]);

        $product = Product::factory()->create(['name' => 'کابل', 'type' => 'standard']);
        $variant = ProductVariant::factory()->for($product)->create();

        app(StockLedger::class)->record($variant->id, $warehouse->id, 10, MovementType::Purchase, unitCost: 100_000);

        return [User::factory()->create(), $warehouse, $variant];
    });

    [$this->user, $this->warehouse, $this->variant] = $fixtures;

    $this->inTenant = fn (Closure $callback): mixed => inTenantContext($this->tenant, $callback);
});

afterEach(fn () => app(TenantContext::class)->forget());

/* ------------------------------------------------- the generic contract -- */

it('holds stock for anything, not just a repair ticket', function (): void {
    ($this->inTenant)(function (): void {
        // A parked POS basket — the Phase 5.1 case the spec says can adopt this later.
        // Nothing in the table or the service knows what a repair is, so making a draft
        // invoice hold its lines is a call to the same method with a different holder.
        // That is a product decision about whether parking a basket should hold stock,
        // not a technical one.
        $draft = SalesInvoice::query()->create([
            'branch_id' => $this->warehouse->branch_id,
            'type' => SalesInvoice::TYPE_INVOICE,
            'status' => InvoiceStatus::Draft,
        ]);

        $reservations = app(StockReservations::class);

        $reservation = $reservations->reserve($draft, $this->variant->id, $this->warehouse->id, 3, $this->user->id);

        expect($reservation->holder_type)->toBe(SalesInvoice::class)
            ->and($reservation->holder_id)->toBe($draft->id)
            // And the till sees the effect immediately, from the same one definition.
            ->and($reservations->available($this->variant->id, $this->warehouse->id))->toBe(7)
            ->and(app(StockLedger::class)->onHand($this->variant->id, $this->warehouse->id))->toBe(10);

        expect($reservations->activeFor($draft))->toHaveCount(1);
    });
});

/* --------------------------------------------------- the two questions -- */

it('keeps on-hand and available answering different questions', function (): void {
    ($this->inTenant)(function (): void {
        $draft = SalesInvoice::query()->create([
            'branch_id' => $this->warehouse->branch_id,
            'type' => SalesInvoice::TYPE_INVOICE,
            'status' => InvoiceStatus::Draft,
        ]);

        app(StockReservations::class)->reserve($draft, $this->variant->id, $this->warehouse->id, 4);

        // A stock count walks the shelf and finds ten. If `onHand` subtracted holds, the
        // count would report a discrepancy every time somebody reserved something.
        expect(app(StockLedger::class)->onHand($this->variant->id, $this->warehouse->id))->toBe(10)
            ->and(app(StockReservations::class)->available($this->variant->id, $this->warehouse->id))->toBe(6);
    });
});

it('never reports a negative available figure', function (): void {
    ($this->inTenant)(function (): void {
        $draft = SalesInvoice::query()->create([
            'branch_id' => $this->warehouse->branch_id,
            'type' => SalesInvoice::TYPE_INVOICE,
            'status' => InvoiceStatus::Draft,
        ]);

        app(StockReservations::class)->reserve($draft, $this->variant->id, $this->warehouse->id, 10);

        // A shop that allows negative stock can genuinely go below zero on-hand. A
        // negative "available" would make a till refuse a sale with a number nobody can
        // act on, so it floors.
        app(StockLedger::class)->record(
            $this->variant->id, $this->warehouse->id, -4, MovementType::Adjustment, unitCost: 100_000,
        );

        expect(app(StockLedger::class)->onHand($this->variant->id, $this->warehouse->id))->toBe(6)
            ->and(app(StockReservations::class)->available($this->variant->id, $this->warehouse->id))->toBe(0);
    });
});

/* --------------------------------------------------------- bulk reads -- */

it('answers many variants in one query for list screens', function (): void {
    ($this->inTenant)(function (): void {
        $second = ProductVariant::factory()->for(
            Product::factory()->create(['name' => 'قاب', 'type' => 'standard'])
        )->create();

        app(StockLedger::class)->record($second->id, $this->warehouse->id, 5, MovementType::Purchase, unitCost: 50_000);

        $draft = SalesInvoice::query()->create([
            'branch_id' => $this->warehouse->branch_id,
            'type' => SalesInvoice::TYPE_INVOICE,
            'status' => InvoiceStatus::Draft,
        ]);

        $reservations = app(StockReservations::class);

        $reservations->reserve($draft, $this->variant->id, $this->warehouse->id, 3);
        $reservations->reserve($draft, $second->id, $this->warehouse->id, 2);

        $held = $reservations->reservedForMany([$this->variant->id, $second->id], $this->warehouse->id);

        expect($held[$this->variant->id])->toBe(3)
            ->and($held[$second->id])->toBe(2);

        // An unheld variant is simply absent rather than zero — the caller merges against
        // its own list, and inventing rows for variants nobody reserved would make the
        // response grow with the catalogue rather than with the holds.
        expect($reservations->reservedForMany([], $this->warehouse->id))->toBe([]);
    });
});

/* ------------------------------------------------------------- refusals -- */

it('refuses a hold larger than what is free', function (): void {
    ($this->inTenant)(function (): void {
        $draft = SalesInvoice::query()->create([
            'branch_id' => $this->warehouse->branch_id,
            'type' => SalesInvoice::TYPE_INVOICE,
            'status' => InvoiceStatus::Draft,
        ]);

        expect(fn () => app(StockReservations::class)->reserve($draft, $this->variant->id, $this->warehouse->id, 11))
            ->toThrow(RuntimeException::class, '10 عدد در دسترس است');
    });
});

it('refuses a zero or negative hold', function (): void {
    ($this->inTenant)(function (): void {
        $draft = SalesInvoice::query()->create([
            'branch_id' => $this->warehouse->branch_id,
            'type' => SalesInvoice::TYPE_INVOICE,
            'status' => InvoiceStatus::Draft,
        ]);

        $reservations = app(StockReservations::class);

        // A CHECK constraint backs this as well; the service refuses first so the
        // caller gets a sentence rather than a driver exception.
        expect(fn () => $reservations->reserve($draft, $this->variant->id, $this->warehouse->id, 0))
            ->toThrow(RuntimeException::class)
            ->and(fn () => $reservations->reserve($draft, $this->variant->id, $this->warehouse->id, -3))
            ->toThrow(RuntimeException::class);
    });
});

it('keeps closed rows so the past can be explained', function (): void {
    ($this->inTenant)(function (): void {
        $draft = SalesInvoice::query()->create([
            'branch_id' => $this->warehouse->branch_id,
            'type' => SalesInvoice::TYPE_INVOICE,
            'status' => InvoiceStatus::Draft,
        ]);

        $reservations = app(StockReservations::class);

        $reservation = $reservations->reserve($draft, $this->variant->id, $this->warehouse->id, 2);
        $reservations->release($reservation);

        // Released, not deleted. "Why did the shop think it had eight of these last
        // Tuesday" is a question somebody asks.
        expect(StockReservation::query()->count())->toBe(1)
            ->and($reservation->fresh()?->state)->toBe(StockReservation::STATE_RELEASED)
            ->and($reservation->fresh()?->closed_at)->not->toBeNull()
            ->and($reservations->available($this->variant->id, $this->warehouse->id))->toBe(10);
    });
});
