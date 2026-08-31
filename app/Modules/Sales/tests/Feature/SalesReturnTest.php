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
use App\Modules\Platform\Services\PlanCatalogueSeeder;
use App\Modules\Platform\Services\SubscriptionResolver;
use App\Modules\Platform\Services\TenantProvisioner;
use App\Modules\Sales\Enums\InvoiceStatus;
use App\Modules\Sales\Enums\PaymentMethod;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesReturn;
use App\Modules\Sales\Services\FinaliseInvoice;
use App\Modules\Sales\Services\InvoiceTotals;
use App\Modules\Sales\Services\RecordReturn;
use App\Modules\Sales\Services\VoidInvoice;
use App\Support\Tenancy\TenantContext;

/**
 * برگشت از فروش, and its boundary with void.
 *
 * The two are constantly confused and must never behave the same: a return says the
 * customer had the goods and brought them back, a void says the sale never happened.
 */
beforeEach(function (): void {
    app(PlanCatalogueSeeder::class)->sync();

    $this->tenant = Tenant::factory()->withDomain()->create();
    $this->url = appUrl();

    subscribe($this->tenant, 'pro');
    app(SubscriptionResolver::class)->forget();
    app(TenantProvisioner::class)->seedRoles($this->tenant);

    /** @var array{User, Warehouse, Account, Party} $fixtures */
    $fixtures = inTenantContext($this->tenant, function (): array {
        $owner = User::factory()->create();
        $owner->assignRole('Owner');

        $warehouse = Warehouse::factory()->create(['is_sellable' => true, 'is_default' => true]);

        $cash = Account::factory()->create(['type' => Account::TYPE_CASH, 'is_default' => true]);
        Account::factory()->create(['type' => Account::TYPE_SALES]);

        return [$owner, $warehouse, $cash, Party::factory()->create()];
    });

    [$this->owner, $this->warehouse, $this->cash, $this->party] = $fixtures;

    $this->inTenant = fn (Closure $callback): mixed => inTenantContext($this->tenant, $callback);
});

afterEach(fn () => app(TenantContext::class)->forget());

/**
 * A finalised sale: one handset and four chargers, paid in cash.
 *
 * @return array{SalesInvoice, ProductUnit, ProductVariant}
 */
function soldBasket(): array
{
    /** @var Tenant $tenant */
    $tenant = test()->tenant;

    /** @var array{SalesInvoice, ProductUnit, ProductVariant} $made */
    $made = inTenantContext($tenant, function (): array {
        /** @var Warehouse $warehouse */
        $warehouse = test()->warehouse;
        /** @var Party $party */
        $party = test()->party;
        /** @var Account $cash */
        $cash = test()->cash;
        /** @var User $owner */
        $owner = test()->owner;

        $phone = Product::factory()->serialized()->create(['name' => 'آیفون ۱۵ پرو']);
        $phoneVariant = ProductVariant::factory()->for($phone)->create();

        $unit = ProductUnit::factory()->for($phoneVariant, 'variant')->create([
            'warehouse_id' => $warehouse->id,
            'status' => UnitStatus::InStock,
            'grade' => 'A',
            'cost' => 40_000_000,
        ]);

        $charger = Product::factory()->create(['name' => 'شارژر', 'type' => 'standard']);
        $chargerVariant = ProductVariant::factory()->for($charger)->create();

        app(StockLedger::class)->record(
            $chargerVariant->id,
            $warehouse->id,
            10,
            MovementType::Purchase,
            unitCost: 200_000,
        );

        $invoice = SalesInvoice::query()->create([
            'branch_id' => $warehouse->branch_id,
            'party_id' => $party->id,
            'type' => SalesInvoice::TYPE_INVOICE,
            'status' => InvoiceStatus::Draft,
        ]);

        $invoice->items()->create([
            'product_variant_id' => $phoneVariant->id,
            'product_unit_id' => $unit->id,
            'description' => 'آیفون ۱۵ پرو',
            'quantity' => 1,
            'unit_price' => 60_000_000,
            'line_total' => 0,
        ]);

        $invoice->items()->create([
            'product_variant_id' => $chargerVariant->id,
            'description' => 'شارژر',
            'quantity' => 4,
            'unit_price' => 500_000,
            'line_total' => 0,
        ]);

        app(InvoiceTotals::class)->recalculate($invoice);

        $invoice->payments()->create([
            'method' => PaymentMethod::Cash,
            'account_id' => $cash->id,
            'amount' => $invoice->refresh()->total,
        ]);

        app(FinaliseInvoice::class)->finalise($invoice->refresh(), $owner->id);

        return [$invoice->refresh(), $unit->refresh(), $chargerVariant];
    });

    return $made;
}

/* ------------------------------------------------------------- the basics -- */

it('records a return as its own numbered document, leaving the invoice alone', function (): void {
    [$invoice, , $chargerVariant] = soldBasket();

    ($this->inTenant)(function () use ($invoice): void {
        $charger = $invoice->items()->where('quantity', 4)->firstOrFail();

        $return = app(RecordReturn::class)->record($invoice, [
            ['item_id' => $charger->id, 'quantity' => 2],
        ], 'مشتری دو عدد اضافه برداشته بود', $this->owner->id);

        expect($return->number)->toBe('RET-000001')
            ->and($return->total)->toBe(1_000_000);

        // The sale itself is untouched — it happened, and a closed month must keep
        // saying so.
        expect($invoice->refresh()->status)->toBe(InvoiceStatus::Final)
            ->and($invoice->total)->toBe(62_000_000);
    });

    ($this->inTenant)(function () use ($chargerVariant): void {
        // Two back on the shelf: 10 purchased − 4 sold + 2 returned.
        expect(app(StockLedger::class)->onHand($chargerVariant->id, $this->warehouse->id))->toBe(8);
    });
});

it('credits the customer rather than opening the drawer', function (): void {
    [$invoice] = soldBasket();

    ($this->inTenant)(function () use ($invoice): void {
        $charger = $invoice->items()->where('quantity', 4)->firstOrFail();

        app(RecordReturn::class)->record($invoice, [
            ['item_id' => $charger->id, 'quantity' => 2],
        ], null, $this->owner->id);

        // The sale was paid in full, so after the return the shop owes the customer.
        // Negative means بستانکار. Handing the cash back is a Treasury act (Phase 7);
        // a return that quietly emptied the till would post a payment nobody made.
        expect(app(LedgerService::class)->partyBalance($this->party))->toBe(-1_000_000);
    });
});

/* ------------------------------------------------------------- handsets -- */

it('brings a handset back as returned, not straight onto the shelf', function (): void {
    [$invoice, $unit] = soldBasket();

    ($this->inTenant)(function () use ($invoice, $unit): void {
        $phone = $invoice->items()->whereNotNull('product_unit_id')->firstOrFail();

        app(RecordReturn::class)->record($invoice, [
            ['item_id' => $phone->id, 'quantity' => 1, 'regrade' => 'B'],
        ], null, $this->owner->id);

        // Present, and not sellable: nine days in a pocket changes what it is worth,
        // and a device that goes back to `in_stock` unexamined gets sold as new.
        expect($unit->refresh()->status)->toBe(UnitStatus::Returned)
            ->and($unit->grade)->toBe('B');
    });
});

it('puts a handset back in stock only when somebody says they checked it', function (): void {
    [$invoice, $unit] = soldBasket();

    ($this->inTenant)(function () use ($invoice, $unit): void {
        $phone = $invoice->items()->whereNotNull('product_unit_id')->firstOrFail();

        app(RecordReturn::class)->record($invoice, [
            ['item_id' => $phone->id, 'quantity' => 1, 'regrade' => 'B', 'restock' => true],
        ], null, $this->owner->id);

        expect($unit->refresh()->status)->toBe(UnitStatus::InStock)
            ->and($unit->grade)->toBe('B');

        // And the passport records both steps rather than one flip — sold, returned,
        // back in stock is what actually happened to this device.
        // `pluck` through Eloquent returns the cast enums, not the raw column.
        $trail = $unit->histories()
            ->orderBy('id')
            ->get()
            ->map(fn ($history): string => $history->to_status->value)
            ->all();

        expect($trail)->toContain(UnitStatus::Returned->value)
            ->and($trail)->toContain(UnitStatus::InStock->value);
    });
});

/* -------------------------------------------------------------- refusals -- */

it('refuses to refund more than was sold, across several returns', function (): void {
    [$invoice] = soldBasket();

    ($this->inTenant)(function () use ($invoice): void {
        $charger = $invoice->items()->where('quantity', 4)->firstOrFail();

        app(RecordReturn::class)->record($invoice, [
            ['item_id' => $charger->id, 'quantity' => 3],
        ], null, $this->owner->id);

        // Only one left. The count comes from the return rows, not a stored counter —
        // so a second partial return cannot forget what the first one took.
        expect(fn () => app(RecordReturn::class)->record($invoice->refresh(), [
            ['item_id' => $charger->id, 'quantity' => 2],
        ], null, $this->owner->id))
            ->toThrow(RuntimeException::class, 'فقط 1 عدد قابل برگشت است');
    });
});

it('refuses a return against a draft', function (): void {
    ($this->inTenant)(function (): void {
        $invoice = SalesInvoice::query()->create([
            'branch_id' => $this->warehouse->branch_id,
            'type' => SalesInvoice::TYPE_INVOICE,
            'status' => InvoiceStatus::Draft,
        ]);

        expect(fn () => app(RecordReturn::class)->record($invoice, [
            ['item_id' => 1, 'quantity' => 1],
        ]))->toThrow(RuntimeException::class, 'فقط از فاکتور نهایی‌شده');
    });
});

/* ---------------------------------------------------- the void boundary -- */

it('refuses to void an invoice that has already been returned against', function (): void {
    [$invoice] = soldBasket();

    ($this->inTenant)(function () use ($invoice): void {
        $charger = $invoice->items()->where('quantity', 4)->firstOrFail();

        app(RecordReturn::class)->record($invoice, [
            ['item_id' => $charger->id, 'quantity' => 1],
        ], null, $this->owner->id);

        // Voiding now would tell the ledger the customer was never charged, while they
        // are standing outside holding a refund for part of it.
        expect(fn () => app(VoidInvoice::class)->void($invoice->refresh(), 'اشتباه شد'))
            ->toThrow(RuntimeException::class, 'برگشت از فروش دارد');
    });
});

it('void puts everything back and keeps the number', function (): void {
    [$invoice, $unit, $chargerVariant] = soldBasket();

    ($this->inTenant)(function () use ($invoice, $unit, $chargerVariant): void {
        $number = $invoice->number;

        app(VoidInvoice::class)->void($invoice, 'مشتری اشتباهی انتخاب شده بود', $this->owner->id);

        expect($invoice->refresh()->status)->toBe(InvoiceStatus::Void)
            // A tax invoice number that disappears is a gap the taxman asks about.
            ->and($invoice->number)->toBe($number)
            ->and($invoice->void_reason)->toBe('مشتری اشتباهی انتخاب شده بود');

        // Nothing left the shop, so the handset is sellable again at the grade it left
        // at — a void has nothing to re-grade.
        expect($unit->refresh()->status)->toBe(UnitStatus::InStock)
            ->and($unit->grade)->toBe('A');

        expect(app(StockLedger::class)->onHand($chargerVariant->id, $this->warehouse->id))->toBe(10);

        // And the money is un-posted: the ledger nets to zero for this party.
        expect(app(LedgerService::class)->partyBalance($this->party))->toBe(0);
    });
});

/* ------------------------------------------------------------------ http -- */

/*
| A line whose per-unit share is not a whole number of toman.
|
| `/returns/create` answered **500** for any invoice holding one. The screen divided
| `line_total` by `quantity` in rial and handed the result to `Money::toArray()`, which
| refuses a figure that is not a whole toman rather than silently rounding it — so a line
| of two at 10,652,010 rial, an ordinary discounted line and a perfectly valid one, took
| the whole screen down.
|
| Every other test here missed it because every fixture divided cleanly. This one does not,
| which is the only reason it exists.
*/
it('opens the return form for a line whose per-unit share is not a whole toman', function (): void {
    [$invoice] = soldBasket();

    ($this->inTenant)(function () use ($invoice): void {
        $charger = $invoice->items()->where('quantity', 4)->firstOrFail();

        // 10,652,010 / 2 = 5,326,005 rial — nine-tenths of a toman, and unrenderable.
        $charger->forceFill(['quantity' => 2, 'line_total' => 10_652_010])->save();
    });

    $this->actingAs($this->owner)
        ->get($this->url.'/sales/invoices/'.$invoice->id.'/returns/create')
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            // Rounded **up** to a whole toman. ADR 0009's amendment fixes the direction: a
            // refund is the shop paying, so rounding down would flatter the shop.
            ->where('items.1.unit_refund.value', 5_326_010)
            // The exact figure stays on the wire, because a whole line coming back is
            // refunded to the rial rather than as quantity x a rounded unit.
            ->where('items.1.line_total.value', 10_652_010)
        );
});

it('records a return through the form and shows it on the invoice', function (): void {
    [$invoice] = soldBasket();

    $charger = ($this->inTenant)(fn () => $invoice->items()->where('quantity', 4)->firstOrFail());
    $phone = ($this->inTenant)(fn () => $invoice->items()->whereNotNull('product_unit_id')->firstOrFail());

    $this->actingAs($this->owner)
        ->get($this->url.'/sales/invoices/'.$invoice->id.'/returns/create')
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page->where('items.1.returnable_quantity', 4));

    $this->actingAs($this->owner)
        ->post($this->url.'/sales/invoices/'.$invoice->id.'/returns', [
            'unit' => 'rial',
            'reason' => 'شارژر خراب بود',
            'lines' => [
                // The untouched line submits a zero and is dropped rather than refused —
                // leaving lines alone is the normal shape of a partial return.
                ['item_id' => $phone->id, 'quantity' => 0],
                ['item_id' => $charger->id, 'quantity' => 1],
            ],
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    ($this->inTenant)(function (): void {
        expect(SalesReturn::query()->count())->toBe(1);

        $return = SalesReturn::query()->firstOrFail();

        expect($return->total)->toBe(500_000)
            ->and($return->items()->count())->toBe(1);
    });

    $this->actingAs($this->owner)
        ->get($this->url.'/sales/invoices/'.$invoice->id)
        ->assertInertia(fn ($page) => $page
            ->where('invoice.returns.0.number', 'RET-000001')
            ->where('invoice.items.1.returned_quantity', 1)
        );
});

it('will not let another shop return against this one', function (): void {
    [$invoice] = soldBasket();

    $other = Tenant::factory()->withDomain()->create();
    subscribe($other, 'pro');
    app(SubscriptionResolver::class)->forget();
    app(TenantProvisioner::class)->seedRoles($other);

    $intruder = inTenantContext($other, function (): User {
        $user = User::factory()->create();
        $user->assignRole('Owner');

        return $user;
    });

    $this->actingAs($intruder)
        ->get(appUrl().'/sales/invoices/'.$invoice->id.'/returns/create')
        ->assertNotFound();

    $this->actingAs($intruder)
        ->post(appUrl().'/sales/invoices/'.$invoice->id.'/returns', [
            'unit' => 'rial',
            'lines' => [['item_id' => 1, 'quantity' => 1]],
        ])
        ->assertNotFound();
});
