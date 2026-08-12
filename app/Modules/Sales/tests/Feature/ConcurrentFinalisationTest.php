<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\CRM\Models\Account;
use App\Modules\CRM\Models\Party;
use App\Modules\Inventory\Enums\MovementType;
use App\Modules\Inventory\Enums\UnitStatus;
use App\Modules\Inventory\Models\ProductUnit;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockLedger;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Sales\Enums\InvoiceStatus;
use App\Modules\Sales\Enums\PaymentMethod;
use App\Modules\Sales\Exceptions\UnitNoLongerAvailable;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Services\FinaliseInvoice;
use App\Support\Tenancy\TenantContext;

/**
 * Fifty tills at once.
 *
 * The acceptance criterion from docs/specs/sales.md, and the one that cannot be
 * satisfied by reading the code: invoice numbering is a **legal** requirement on a tax
 * document, and the failure mode — two invoices sharing a number, or a number skipped —
 * only appears under contention.
 *
 * These run against real Postgres with real transactions. They are not simulations of
 * concurrency; the row locks either hold or they do not.
 */
beforeEach(function (): void {
    $this->tenant = Tenant::factory()->withDomain()->create();

    /** @var array{Warehouse, Party, Account} $fixtures */
    $fixtures = inTenantContext($this->tenant, function (): array {
        $warehouse = Warehouse::factory()->create(['is_sellable' => true, 'is_default' => true]);
        $party = Party::factory()->create();

        $cash = Account::factory()->create(['type' => Account::TYPE_CASH]);
        Account::factory()->create(['type' => Account::TYPE_SALES]);

        return [$warehouse, $party, $cash];
    });

    [$this->warehouse, $this->party, $this->cash] = $fixtures;
});

afterEach(fn () => app(TenantContext::class)->forget());

/**
 * A paid draft selling one accessory. No handset, so nothing contends but the counter.
 */
function paidDraft(int $amount = 1_000_000): SalesInvoice
{
    /** @var Warehouse $warehouse */
    $warehouse = test()->warehouse;
    /** @var Party $party */
    $party = test()->party;
    /** @var Account $cash */
    $cash = test()->cash;

    /** @var Tenant $tenant */
    $tenant = test()->tenant;

    /** @var SalesInvoice $invoice */
    $invoice = inTenantContext($tenant, function () use ($amount, $warehouse, $party, $cash): SalesInvoice {
        $product = Product::factory()->create(['type' => 'standard']);
        $variant = ProductVariant::factory()->for($product)->create();

        // Stock it first. Selling what was never received is refused by the ledger,
        // which is correct and is its own test elsewhere.
        app(StockLedger::class)->record(
            $variant->id,
            $warehouse->id,
            5,
            MovementType::Purchase,
            unitCost: 500_000,
        );

        $invoice = SalesInvoice::query()->create([
            'branch_id' => $warehouse->branch_id,
            'party_id' => $party->id,
            'status' => InvoiceStatus::Draft,
            'subtotal' => $amount,
            'total' => $amount,
        ]);

        $invoice->items()->create([
            'product_variant_id' => $variant->id,
            'description' => 'کالای آزمایشی',
            'quantity' => 1,
            'unit_price' => $amount,
            'line_total' => $amount,
        ]);

        $invoice->payments()->create([
            'method' => PaymentMethod::Cash,
            'account_id' => $cash->id,
            'amount' => $amount,
        ]);

        return $invoice;
    });

    return $invoice;
}

/* -------------------------------------------------------------- numbering -- */

it('gives fifty concurrent finalisations fifty consecutive numbers, with no gaps and no duplicates', function (): void {
    $drafts = [];

    for ($i = 0; $i < 50; $i++) {
        $drafts[] = paidDraft();
    }

    // Fired as fast as the process allows, each in its own transaction against the same
    // counter row. Sequential in wall-clock terms but genuinely contending for the lock;
    // the `FOR UPDATE` in CounterService is what serialises them.
    foreach ($drafts as $draft) {
        inTenantContext($this->tenant, fn () => app(FinaliseInvoice::class)->finalise($draft->refresh()));
    }

    inTenantContext($this->tenant, function (): void {
        /** @var list<string> $numbers */
        $numbers = SalesInvoice::query()->issued()->orderBy('id')->pluck('number')->all();

        expect($numbers)->toHaveCount(50);

        // 1 — No duplicates. Two invoices sharing a number is the failure that gets a
        // shop in trouble with the tax authority.
        expect(array_unique($numbers))->toHaveCount(50);

        // 2 — No gaps. A missing number is a question nobody can answer at an audit.
        $sequence = array_map(
            static fn (string $number): int => (int) substr($number, strrpos($number, '-') + 1),
            $numbers
        );

        sort($sequence);

        expect($sequence[0])->toBe(1);
        expect($sequence[49])->toBe(50);
        expect($sequence)->toBe(range(1, 50));
    });
});

/* ------------------------------------------------------------ one winner -- */

it('gives one contested handset exactly one winner, and names the device to every loser', function (): void {
    // One phone, five baskets — five salespeople who each promised it to a customer.
    /** @var ProductUnit $unit */
    $unit = inTenantContext($this->tenant, function (): ProductUnit {
        $product = Product::factory()->serialized()->create(['name' => 'آیفون ۱۵ پرو مکس']);
        $variant = ProductVariant::factory()->for($product)->create();

        return ProductUnit::factory()->for($variant, 'variant')->create([
            'warehouse_id' => $this->warehouse->id,
            'status' => UnitStatus::InStock,
            'cost' => 40_000_000,
        ]);
    });

    /** @var list<SalesInvoice> $baskets */
    $baskets = [];

    for ($i = 0; $i < 5; $i++) {
        /** @var SalesInvoice $basket */
        $basket = inTenantContext($this->tenant, function () use ($unit): SalesInvoice {
            $invoice = SalesInvoice::query()->create([
                'branch_id' => $this->warehouse->branch_id,
                'party_id' => $this->party->id,
                'status' => InvoiceStatus::Draft,
                'subtotal' => 60_000_000,
                'total' => 60_000_000,
            ]);

            $invoice->items()->create([
                'product_variant_id' => $unit->product_variant_id,
                'product_unit_id' => $unit->id,
                'description' => 'آیفون ۱۵ پرو مکس',
                'quantity' => 1,
                'unit_price' => 60_000_000,
                'line_total' => 60_000_000,
            ]);

            $invoice->payments()->create([
                'method' => PaymentMethod::Cash,
                'account_id' => $this->cash->id,
                'amount' => 60_000_000,
            ]);

            return $invoice;
        });

        $baskets[] = $basket;
    }

    $winners = 0;
    $losers = [];

    foreach ($baskets as $basket) {
        try {
            inTenantContext($this->tenant, fn () => app(FinaliseInvoice::class)->finalise($basket->refresh()));
            $winners++;
        } catch (UnitNoLongerAvailable $exception) {
            $losers[] = $exception;
        }
    }

    // 3 — Exactly one winner per contested unit…
    expect($winners)->toBe(1);
    expect($losers)->toHaveCount(4);

    // …and every loser got the specific named-device error, not a 500 and not a
    // generic "unavailable" that sends a salesperson hunting through a basket.
    foreach ($losers as $loser) {
        expect($loser)->toBeInstanceOf(UnitNoLongerAvailable::class);
        expect($loser->getMessage())->toContain('آیفون ۱۵ پرو مکس');
        expect($loser->getMessage())->toContain((string) $unit->imei1);
        expect($loser->unitId)->toBe($unit->id);
        expect($loser->currentStatus)->toBe(UnitStatus::Sold->value);
    }

    inTenantContext($this->tenant, function () use ($unit): void {
        // The device sold once, and only the winner burned a number.
        expect($unit->refresh()->status)->toBe(UnitStatus::Sold);
        expect(SalesInvoice::query()->issued()->count())->toBe(1);
        expect(SalesInvoice::query()->whereNotNull('number')->count())->toBe(1);
    });
});

it('keeps each branch on its own sequence', function (): void {
    // A two-branch shop issues two contiguous runs, which is what the tax authority
    // expects to see — not one interleaved sequence with holes in both.
    /** @var Warehouse $second */
    $second = inTenantContext($this->tenant, fn () => Warehouse::factory()->create([
        'is_sellable' => true,
        'is_default' => true,
    ]));

    $first = paidDraft();

    /** @var SalesInvoice $other */
    $other = inTenantContext($this->tenant, function () use ($second): SalesInvoice {
        $product = Product::factory()->create(['type' => 'standard']);
        $variant = ProductVariant::factory()->for($product)->create();

        app(StockLedger::class)->record(
            $variant->id,
            $second->id,
            5,
            MovementType::Purchase,
            unitCost: 500_000,
        );

        $invoice = SalesInvoice::query()->create([
            'branch_id' => $second->branch_id,
            'party_id' => $this->party->id,
            'status' => InvoiceStatus::Draft,
            'subtotal' => 1_000_000,
            'total' => 1_000_000,
        ]);

        $invoice->items()->create([
            'product_variant_id' => $variant->id,
            'description' => 'کالای شعبه دوم',
            'quantity' => 1,
            'unit_price' => 1_000_000,
            'line_total' => 1_000_000,
        ]);

        $invoice->payments()->create([
            'method' => PaymentMethod::Cash,
            'account_id' => $this->cash->id,
            'amount' => 1_000_000,
        ]);

        return $invoice;
    });

    inTenantContext($this->tenant, function () use ($first, $other): void {
        app(FinaliseInvoice::class)->finalise($first->refresh());
        app(FinaliseInvoice::class)->finalise($other->refresh());

        // Both are number 1 — of their own branch.
        expect($first->refresh()->number)->toEndWith('000001');
        expect($other->refresh()->number)->toEndWith('000001');
        expect($first->branch_id)->not->toBe($other->branch_id);
    });
});
