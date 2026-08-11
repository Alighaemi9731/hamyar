<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\CRM\Models\Account;
use App\Modules\CRM\Models\Party;
use App\Modules\CRM\Services\LedgerService;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Enums\MovementType;
use App\Modules\Inventory\Enums\UnitStatus;
use App\Modules\Inventory\Models\ProductUnit;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockLedger;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Sales\Enums\InvoiceStatus;
use App\Modules\Sales\Enums\PaymentMethod;
use App\Modules\Sales\Events\InvoiceFinalised;
use App\Modules\Sales\Exceptions\UnitNoLongerAvailable;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Services\FinaliseInvoice;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Event;

/**
 * Finalisation — the most concurrency-sensitive code in the product.
 *
 * The tests that earn their place are the ones about what happens when two tills race,
 * and about the figures that must still be true months later.
 */
beforeEach(function (): void {
    $this->tenant = Tenant::factory()->withDomain()->create();

    $this->inTenant = fn (Closure $callback): mixed => inTenantContext($this->tenant, $callback);

    /** @var array{Warehouse, ProductVariant, Party, User, Account} $fixtures */
    $fixtures = inTenantContext($this->tenant, function (): array {
        $warehouse = Warehouse::factory()->create(['is_sellable' => true, 'is_default' => true]);

        $product = Product::factory()->create(['name' => 'کابل شارژ', 'type' => 'standard']);
        $variant = ProductVariant::factory()->for($product)->create();

        $party = Party::factory()->create();
        $user = User::factory()->create();

        $cash = Account::factory()->create(['type' => Account::TYPE_CASH]);
        Account::factory()->create(['type' => Account::TYPE_SALES]);

        return [$warehouse, $variant, $party, $user, $cash];
    });

    [$this->warehouse, $this->variant, $this->party, $this->user, $this->cash] = $fixtures;
});

afterEach(fn () => app(TenantContext::class)->forget());

/**
 * A draft with one handset on it, priced as given.
 *
 * @return array{SalesInvoice, ProductUnit}
 */
function draftWithUnit(int $price, int $unitCost = 40_000_000): array
{
    /** @var Tenant $tenant */
    $tenant = test()->tenant;
    /** @var Warehouse $warehouse */
    $warehouse = test()->warehouse;
    /** @var Party $party */
    $party = test()->party;

    /** @var array{SalesInvoice, ProductUnit} $made */
    $made = inTenantContext($tenant, function () use ($price, $unitCost, $warehouse, $party): array {
        $product = Product::factory()->serialized()->create(['name' => 'آیفون ۱۵ پرو']);
        $variant = ProductVariant::factory()->for($product)->create();

        $unit = ProductUnit::factory()->for($variant, 'variant')->create([
            'warehouse_id' => $warehouse->id,
            'status' => UnitStatus::InStock,
            'cost' => $unitCost,
        ]);

        $invoice = SalesInvoice::query()->create([
            'branch_id' => $warehouse->branch_id,
            'party_id' => $party->id,
            'status' => InvoiceStatus::Draft,
            'total' => $price,
            'subtotal' => $price,
        ]);

        $invoice->items()->create([
            'product_variant_id' => $variant->id,
            'product_unit_id' => $unit->id,
            'description' => 'آیفون ۱۵ پرو',
            'quantity' => 1,
            'unit_price' => $price,
            'line_total' => $price,
        ]);

        return [$invoice, $unit];
    });

    return $made;
}

/* ------------------------------------------------------------- happy path -- */

it('numbers the invoice, sells the handset and balances the ledger', function (): void {
    [$invoice, $unit] = draftWithUnit(60_000_000);

    ($this->inTenant)(function () use ($invoice): void {
        $invoice->payments()->create([
            'method' => PaymentMethod::Cash,
            'account_id' => $this->cash->id,
            'amount' => 60_000_000,
        ]);

        app(FinaliseInvoice::class)->finalise($invoice->refresh(), $this->user->id);
    });

    ($this->inTenant)(function () use ($invoice, $unit): void {
        $invoice->refresh();

        expect($invoice->status)->toBe(InvoiceStatus::Final);
        expect($invoice->number)->toStartWith('INV-');
        expect($invoice->paid_total)->toBe(60_000_000)->toBeRial();

        // The device is sold and its passport says why.
        expect($unit->refresh()->status)->toBe(UnitStatus::Sold);
        expect($unit->histories()->latest('id')->first()?->note)
            ->toContain($invoice->number);

        // Cash in, revenue recognised, and nothing owing.
        expect(app(LedgerService::class)->accountBalance($this->cash->refresh()))->toBe(60_000_000);
        expect(app(LedgerService::class)->partyBalance($this->party->refresh()))->toBe(0);
    });
});

it('writes no quantity movement for a serialized sale', function (): void {
    // A handset never entered the quantity ledger, so it must not leave through it —
    // otherwise every stock report is short by the number of phones sold.
    [$invoice, $unit] = draftWithUnit(60_000_000);

    ($this->inTenant)(function () use ($invoice): void {
        $invoice->payments()->create([
            'method' => PaymentMethod::Cash,
            'account_id' => $this->cash->id,
            'amount' => 60_000_000,
        ]);

        app(FinaliseInvoice::class)->finalise($invoice->refresh());
    });

    ($this->inTenant)(function () use ($unit): void {
        expect(app(StockLedger::class)->onHand($unit->product_variant_id, $this->warehouse->id))->toBe(0);
    });
});

/* ----------------------------------------------------------- the race -- */

it('refuses a second sale of a handset that is already sold', function (): void {
    [$invoice, $unit] = draftWithUnit(60_000_000);

    ($this->inTenant)(function () use ($invoice, $unit): void {
        $invoice->payments()->create([
            'method' => PaymentMethod::Cash,
            'account_id' => $this->cash->id,
            'amount' => 60_000_000,
        ]);

        app(FinaliseInvoice::class)->finalise($invoice->refresh());

        // A second basket still holding the sold device — the state a slow till is in
        // when it finally hits "finalise", and the exact shape of the losing side of
        // the race.
        $second = SalesInvoice::query()->create([
            'branch_id' => $this->warehouse->branch_id,
            'party_id' => $this->party->id,
            'status' => InvoiceStatus::Draft,
            'total' => 60_000_000,
            'subtotal' => 60_000_000,
        ]);

        $second->items()->create([
            'product_variant_id' => $unit->product_variant_id,
            'description' => 'آیفون ۱۵ پرو',
            'quantity' => 1,
            'unit_price' => 60_000_000,
            'line_total' => 60_000_000,
        ]);

        // Point it at the sold device the way a stale basket would.
        $second->items()->first()?->forceFill(['product_unit_id' => $unit->id])->save();

        $failure = null;

        try {
            app(FinaliseInvoice::class)->finalise($second->refresh());
        } catch (UnitNoLongerAvailable $exception) {
            $failure = $exception;
        }

        // A specific Persian error naming the handset, never a 500.
        expect($failure)->not->toBeNull();
        expect($failure?->getMessage())->toContain('آیفون ۱۵ پرو');
        expect($failure?->getMessage())->toContain((string) $unit->imei1);

        // And the loser burned no invoice number.
        expect($second->refresh()->number)->toBeNull();
        expect($second->status)->toBe(InvoiceStatus::Draft);
    });
});

/* ------------------------------------------------------------ cost engine -- */

it('snapshots the exact cost of a handset, not a current price', function (): void {
    [$invoice, $unit] = draftWithUnit(60_000_000, unitCost: 41_500_000);

    ($this->inTenant)(function () use ($invoice): void {
        $invoice->payments()->create([
            'method' => PaymentMethod::Cash,
            'account_id' => $this->cash->id,
            'amount' => 60_000_000,
        ]);

        app(FinaliseInvoice::class)->finalise($invoice->refresh());
    });

    ($this->inTenant)(function () use ($invoice, $unit): void {
        $item = $invoice->refresh()->items()->firstOrFail();

        expect($item->cost_snapshot)->toBe(41_500_000)->toBeRial();
        expect($item->profit())->toBe(18_500_000);

        // Changing what the device is recorded as having cost must not move the
        // profit already reported on a closed sale.
        $unit->refresh()->forceFill(['cost' => 1])->save();

        expect($invoice->items()->firstOrFail()->cost_snapshot)->toBe(41_500_000);
    });
});

it('costs standard goods at the weighted average, not the last price paid', function (): void {
    // A hundred at 50,000 and ten at 90,000 average to 53,636 — not to 70,000, which is
    // what a mean of the two prices would say, and not 90,000, which is what "last cost"
    // would say.
    ($this->inTenant)(function (): void {
        $ledger = app(StockLedger::class);

        $ledger->record($this->variant->id, $this->warehouse->id, 100, MovementType::Purchase, unitCost: 50_000);
        $ledger->record($this->variant->id, $this->warehouse->id, 10, MovementType::Purchase, unitCost: 90_000);

        expect($ledger->weightedAverageCost($this->variant->id, $this->warehouse->id))->toBe(53_636);
    });

    $invoice = ($this->inTenant)(function (): SalesInvoice {
        $invoice = SalesInvoice::query()->create([
            'branch_id' => $this->warehouse->branch_id,
            'party_id' => $this->party->id,
            'status' => InvoiceStatus::Draft,
            'total' => 500_000,
            'subtotal' => 500_000,
        ]);

        $invoice->items()->create([
            'product_variant_id' => $this->variant->id,
            'description' => 'کابل شارژ',
            'quantity' => 5,
            'unit_price' => 100_000,
            'line_total' => 500_000,
        ]);

        $invoice->payments()->create([
            'method' => PaymentMethod::Cash,
            'account_id' => $this->cash->id,
            'amount' => 500_000,
        ]);

        return $invoice;
    });

    ($this->inTenant)(fn () => app(FinaliseInvoice::class)->finalise($invoice->refresh()));

    ($this->inTenant)(function () use ($invoice): void {
        expect($invoice->refresh()->items()->firstOrFail()->cost_snapshot)->toBe(53_636);

        // Five left the shelf.
        expect(app(StockLedger::class)->onHand($this->variant->id, $this->warehouse->id))->toBe(105);
    });
});

/* ---------------------------------------------------------------- credit -- */

it('posts an unpaid balance to the customer rather than the till', function (): void {
    [$invoice] = draftWithUnit(60_000_000);

    ($this->inTenant)(function () use ($invoice): void {
        // Half now, half owed.
        $invoice->payments()->create([
            'method' => PaymentMethod::Cash,
            'account_id' => $this->cash->id,
            'amount' => 20_000_000,
        ]);

        app(FinaliseInvoice::class)->finalise($invoice->refresh());
    });

    ($this->inTenant)(function (): void {
        expect(app(LedgerService::class)->accountBalance($this->cash->refresh()))->toBe(20_000_000);
        // Positive means they owe the shop.
        expect(app(LedgerService::class)->partyBalance($this->party->refresh()))->toBe(40_000_000);
    });
});

it('refuses a credit sale with nobody to owe it', function (): void {
    [$invoice] = draftWithUnit(60_000_000);

    ($this->inTenant)(function () use ($invoice): void {
        $invoice->update(['party_id' => null]);

        expect(fn () => app(FinaliseInvoice::class)->finalise($invoice->refresh()))
            ->toThrow(RuntimeException::class);

        // Nothing was written — no number, no stock movement.
        expect($invoice->refresh()->number)->toBeNull();
    });
});

it('refuses payments that exceed the invoice', function (): void {
    [$invoice] = draftWithUnit(60_000_000);

    ($this->inTenant)(function () use ($invoice): void {
        $invoice->payments()->create([
            'method' => PaymentMethod::Cash,
            'account_id' => $this->cash->id,
            'amount' => 70_000_000,
        ]);

        expect(fn () => app(FinaliseInvoice::class)->finalise($invoice->refresh()))
            ->toThrow(RuntimeException::class);
    });
});

/* ----------------------------------------------------------------- event -- */

it('dispatches InvoiceFinalised after the transaction commits', function (): void {
    Event::fake([InvoiceFinalised::class]);

    [$invoice] = draftWithUnit(60_000_000);

    ($this->inTenant)(function () use ($invoice): void {
        $invoice->payments()->create([
            'method' => PaymentMethod::Cash,
            'account_id' => $this->cash->id,
            'amount' => 60_000_000,
        ]);

        app(FinaliseInvoice::class)->finalise($invoice->refresh());
    });

    Event::assertDispatched(InvoiceFinalised::class);
});

it('will not finalise the same invoice twice', function (): void {
    [$invoice] = draftWithUnit(60_000_000);

    ($this->inTenant)(function () use ($invoice): void {
        $invoice->payments()->create([
            'method' => PaymentMethod::Cash,
            'account_id' => $this->cash->id,
            'amount' => 60_000_000,
        ]);

        app(FinaliseInvoice::class)->finalise($invoice->refresh());

        // Twice would double the stock effect and the revenue.
        expect(fn () => app(FinaliseInvoice::class)->finalise($invoice->refresh()))
            ->toThrow(RuntimeException::class);
    });
});
