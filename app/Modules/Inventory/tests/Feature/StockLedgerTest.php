<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Inventory\Enums\MovementType;
use App\Modules\Inventory\Exceptions\InsufficientStock;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockLedger;
use App\Modules\Platform\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Golden rule 3: quantity is a SUM over movements, never a stored total.
 */
function idOf(object $model): int
{
    /** @var int $id */
    $id = $model->getKey();

    return $id;
}

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->withDomain()->create();
    $this->ledger = app(StockLedger::class);
});

afterEach(fn () => app(TenantContext::class)->forget());

/* ------------------------------------------------------------- on hand -- */

it('derives on-hand by summing movements', function (): void {
    app(TenantContext::class)->runFor($this->tenant, function (): void {
        $variant = ProductVariant::factory()->create();
        $warehouse = Warehouse::factory()->create();

        $this->ledger->record(idOf($variant), idOf($warehouse), 10, MovementType::Purchase);
        $this->ledger->record(idOf($variant), idOf($warehouse), -3, MovementType::Sale);
        $this->ledger->record(idOf($variant), idOf($warehouse), 1, MovementType::Return);

        expect($this->ledger->onHand(idOf($variant), idOf($warehouse)))->toBe(8);
    });
});

it('reports zero for a variant that has never moved', function (): void {
    app(TenantContext::class)->runFor($this->tenant, function (): void {
        $variant = ProductVariant::factory()->create();

        expect($this->ledger->onHand(idOf($variant)))->toBe(0);
    });
});

it('sums across warehouses when none is given', function (): void {
    app(TenantContext::class)->runFor($this->tenant, function (): void {
        $variant = ProductVariant::factory()->create();
        $shop = Warehouse::factory()->create();
        $backRoom = Warehouse::factory()->create();

        $this->ledger->record(idOf($variant), idOf($shop), 4, MovementType::Purchase);
        $this->ledger->record(idOf($variant), idOf($backRoom), 6, MovementType::Purchase);

        expect($this->ledger->onHand(idOf($variant)))->toBe(10);
        expect($this->ledger->onHand(idOf($variant), idOf($shop)))->toBe(4);
    });
});

it('answers what the quantity was on a past date', function (): void {
    app(TenantContext::class)->runFor($this->tenant, function (): void {
        $variant = ProductVariant::factory()->create();
        $warehouse = Warehouse::factory()->create();

        $this->ledger->record(idOf($variant), idOf($warehouse), 10, MovementType::Purchase, occurredAt: now()->subMonth()->toImmutable());
        $this->ledger->record(idOf($variant), idOf($warehouse), -4, MovementType::Sale, occurredAt: now()->subDay()->toImmutable());

        // A stored total could never answer this. A ledger answers it for free, which is
        // the whole argument for golden rule 3.
        expect($this->ledger->onHand(idOf($variant), idOf($warehouse), now()->subWeek()->toImmutable()))->toBe(10);
        expect($this->ledger->onHand(idOf($variant), idOf($warehouse)))->toBe(6);
    });
});

it('returns a figure for every requested variant including untouched ones', function (): void {
    app(TenantContext::class)->runFor($this->tenant, function (): void {
        $stocked = ProductVariant::factory()->create();
        $untouched = ProductVariant::factory()->create();
        $warehouse = Warehouse::factory()->create();

        $this->ledger->record(idOf($stocked), idOf($warehouse), 5, MovementType::Purchase);

        $totals = $this->ledger->onHandForMany([idOf($stocked), idOf($untouched)]);

        // A missing key would read as "unknown" downstream when it means zero.
        expect($totals[idOf($stocked)])->toBe(5);
        expect($totals[idOf($untouched)])->toBe(0);
    });
});

/* ------------------------------------------------------ negative stock -- */

it('blocks a withdrawal that would go below zero', function (): void {
    app(TenantContext::class)->runFor($this->tenant, function (): void {
        $variant = ProductVariant::factory()->create();
        $warehouse = Warehouse::factory()->create();

        $this->ledger->record(idOf($variant), idOf($warehouse), 2, MovementType::Purchase);

        expect(fn () => $this->ledger->record(idOf($variant), idOf($warehouse), -3, MovementType::Sale))
            ->toThrow(InsufficientStock::class);

        expect($this->ledger->onHand(idOf($variant), idOf($warehouse)))->toBe(2);
    });
});

it('allows negative stock where the warehouse opts in', function (): void {
    app(TenantContext::class)->runFor($this->tenant, function (): void {
        $variant = ProductVariant::factory()->create();
        $warehouse = Warehouse::factory()->create(['allows_negative_stock' => true]);

        // Some shops genuinely sell ahead of a delivery and would rather record the sale
        // than block the till. Per warehouse, never globally.
        $this->ledger->record(idOf($variant), idOf($warehouse), -3, MovementType::Sale);

        expect($this->ledger->onHand(idOf($variant), idOf($warehouse)))->toBe(-3);
    });
});

it('reports how many were available when it refuses', function (): void {
    app(TenantContext::class)->runFor($this->tenant, function (): void {
        $variant = ProductVariant::factory()->create();
        $warehouse = Warehouse::factory()->create();

        $this->ledger->record(idOf($variant), idOf($warehouse), 2, MovementType::Purchase);

        try {
            $this->ledger->record(idOf($variant), idOf($warehouse), -5, MovementType::Sale);
            $this->fail('Expected InsufficientStock.');
        } catch (InsufficientStock $exception) {
            // The cashier's next question is "how many do we have?".
            expect($exception->available)->toBe(2);
            expect($exception->requested)->toBe(5);
        }
    });
});

/* ------------------------------------------------------------- guards -- */

it('rejects a zero-quantity movement', function (): void {
    app(TenantContext::class)->runFor($this->tenant, function (): void {
        $variant = ProductVariant::factory()->create();
        $warehouse = Warehouse::factory()->create();

        // Always a bug — a caller computing a delta that turned out to be nothing.
        expect(fn () => $this->ledger->record(idOf($variant), idOf($warehouse), 0, MovementType::Adjustment))
            ->toThrow(InvalidArgumentException::class);
    });
});

it('rejects a zero quantity even when written directly', function (): void {
    app(TenantContext::class)->runFor($this->tenant, function (): void {
        // The service guards it, and so does the database — a raw insert from a future
        // caller cannot pollute the ledger with lines that explain no change.
        expect(fn () => DB::transaction(fn () => StockMovement::factory()->create(['quantity' => 0])))
            ->toThrow(QueryException::class);
    });
});

it('rejects a movement whose sign contradicts its type', function (): void {
    app(TenantContext::class)->runFor($this->tenant, function (): void {
        $variant = ProductVariant::factory()->create();
        $warehouse = Warehouse::factory()->create();

        // A negative purchase is a return, and calling it a purchase makes the ledger
        // lie about why stock moved.
        expect(fn () => $this->ledger->record(idOf($variant), idOf($warehouse), -1, MovementType::Purchase))
            ->toThrow(InvalidArgumentException::class);

        expect(fn () => $this->ledger->record(idOf($variant), idOf($warehouse), 1, MovementType::Sale))
            ->toThrow(InvalidArgumentException::class);
    });
});

it('lets adjustments and counts go either way', function (): void {
    app(TenantContext::class)->runFor($this->tenant, function (): void {
        $variant = ProductVariant::factory()->create();
        $warehouse = Warehouse::factory()->create(['allows_negative_stock' => true]);

        expect(MovementType::Adjustment->requiredSign())->toBeNull();

        $this->ledger->record(idOf($variant), idOf($warehouse), 5, MovementType::Adjustment);
        $this->ledger->record(idOf($variant), idOf($warehouse), -2, MovementType::Adjustment);

        expect($this->ledger->onHand(idOf($variant), idOf($warehouse)))->toBe(3);
    });
});

/* ----------------------------------------------------------- transfers -- */

it('moves stock as two movements, one each side', function (): void {
    app(TenantContext::class)->runFor($this->tenant, function (): void {
        $variant = ProductVariant::factory()->create();
        $from = Warehouse::factory()->create();
        $to = Warehouse::factory()->create();

        $this->ledger->record(idOf($variant), idOf($from), 10, MovementType::Purchase);
        $this->ledger->transfer(idOf($variant), idOf($from), idOf($to), 4);

        expect($this->ledger->onHand(idOf($variant), idOf($from)))->toBe(6);
        expect($this->ledger->onHand(idOf($variant), idOf($to)))->toBe(4);
        // Two rows, so each side's ledger explains its own change.
        expect(StockMovement::query()->whereIn('type', ['transfer_out', 'transfer_in'])->count())->toBe(2);
    });
});

it('refuses a transfer that the source cannot cover', function (): void {
    app(TenantContext::class)->runFor($this->tenant, function (): void {
        $variant = ProductVariant::factory()->create();
        $from = Warehouse::factory()->create();
        $to = Warehouse::factory()->create();

        $this->ledger->record(idOf($variant), idOf($from), 1, MovementType::Purchase);

        expect(fn () => $this->ledger->transfer(idOf($variant), idOf($from), idOf($to), 5))
            ->toThrow(InsufficientStock::class);

        // Neither leg may survive: a half-applied transfer invents or destroys stock.
        expect($this->ledger->onHand(idOf($variant), idOf($from)))->toBe(1);
        expect($this->ledger->onHand(idOf($variant), idOf($to)))->toBe(0);
    });
});

it('refuses a transfer to the same warehouse', function (): void {
    app(TenantContext::class)->runFor($this->tenant, function (): void {
        $variant = ProductVariant::factory()->create();
        $warehouse = Warehouse::factory()->create();

        expect(fn () => $this->ledger->transfer(idOf($variant), idOf($warehouse), idOf($warehouse), 1))
            ->toThrow(InvalidArgumentException::class);
    });
});

/* ------------------------------------------------------- stock counts -- */

it('records the difference when reconciling a count', function (): void {
    app(TenantContext::class)->runFor($this->tenant, function (): void {
        $variant = ProductVariant::factory()->create();
        $warehouse = Warehouse::factory()->create();

        $this->ledger->record(idOf($variant), idOf($warehouse), 10, MovementType::Purchase);

        // Counted 7 where the system said 10. The correction is a movement of -3, not a
        // rewrite to 7, so "we were 3 short in Mordad" stays answerable.
        $movement = $this->ledger->reconcileTo(idOf($variant), idOf($warehouse), 7);

        expect($movement?->quantity)->toBe(-3);
        expect($movement?->type)->toBe(MovementType::Count);
        expect($this->ledger->onHand(idOf($variant), idOf($warehouse)))->toBe(7);
    });
});

it('writes nothing when a count agrees with the ledger', function (): void {
    app(TenantContext::class)->runFor($this->tenant, function (): void {
        $variant = ProductVariant::factory()->create();
        $warehouse = Warehouse::factory()->create();

        $this->ledger->record(idOf($variant), idOf($warehouse), 5, MovementType::Purchase);

        expect($this->ledger->reconcileTo(idOf($variant), idOf($warehouse), 5))->toBeNull();
        expect(StockMovement::query()->count())->toBe(1);
    });
});

/* ------------------------------------------------------------ isolation -- */

it('does not leak stock movements across tenants', function (): void {
    pest()->group('isolation');

    $other = Tenant::factory()->withDomain()->create();

    app(TenantContext::class)->runFor($this->tenant, function (): void {
        $variant = ProductVariant::factory()->create();
        $warehouse = Warehouse::factory()->create();
        $this->ledger->record(idOf($variant), idOf($warehouse), 10, MovementType::Purchase);
    });

    app(TenantContext::class)->runFor($other, function (): void {
        // Stock levels are commercially sensitive — knowing a competitor is out of a hot
        // model is worth money.
        expect(StockMovement::query()->count())->toBe(0);
    });
});
