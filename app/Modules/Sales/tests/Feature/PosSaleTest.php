<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\PriceLevel;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Catalog\Services\PriceResolver;
use App\Modules\CRM\Models\Account;
use App\Modules\CRM\Models\LedgerEntry;
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
use App\Modules\Sales\Models\SalesInvoice;
use App\Support\Tenancy\TenantContext;

/**
 * The till, end to end through HTTP.
 *
 * `FinaliseInvoiceTest` already proves the transaction — the lock, the numbering, the
 * ledger. These tests cover the part only the endpoint can: that a basket built in a
 * browser arrives, is re-checked rather than trusted, and produces the same invoice.
 */
beforeEach(function (): void {
    app(PlanCatalogueSeeder::class)->sync();

    $this->tenant = Tenant::factory()->withDomain()->create();
    $this->url = tenantUrl($this->tenant);

    subscribe($this->tenant, 'pro');
    app(SubscriptionResolver::class)->forget();

    app(TenantProvisioner::class)->seedRoles($this->tenant);

    /** @var array{User, User, Warehouse, Account} $fixtures */
    $fixtures = inTenantContext($this->tenant, function (): array {
        $owner = User::factory()->create();
        $owner->assignRole('Owner');

        $seller = User::factory()->create();
        $seller->assignRole('Salesperson');

        $warehouse = Warehouse::factory()->create(['is_sellable' => true, 'is_default' => true]);

        $cash = Account::factory()->create(['type' => Account::TYPE_CASH, 'is_default' => true]);
        Account::factory()->create(['type' => Account::TYPE_SALES]);
        Account::factory()->create(['type' => Account::TYPE_INVENTORY]);

        return [$owner, $seller, $warehouse, $cash];
    });

    [$this->owner, $this->seller, $this->warehouse, $this->cash] = $fixtures;

    $this->inTenant = fn (Closure $callback): mixed => inTenantContext($this->tenant, $callback);
});

afterEach(fn () => app(TenantContext::class)->forget());

/**
 * One handset on the shelf, at a known cost.
 */
function shelvedHandset(Tenant $tenant, Warehouse $warehouse, string $imei, int $cost = 40_000_000): ProductUnit
{
    /** @var ProductUnit $unit */
    $unit = inTenantContext($tenant, function () use ($warehouse, $imei, $cost): ProductUnit {
        $product = Product::factory()->serialized()->create(['name' => 'آیفون ۱۵ پرو']);
        $variant = ProductVariant::factory()->for($product)->create();

        return ProductUnit::factory()->for($variant, 'variant')->create([
            'warehouse_id' => $warehouse->id,
            'status' => UnitStatus::InStock,
            'imei1' => $imei,
            'cost' => $cost,
        ]);
    });

    return $unit;
}

/**
 * A quantity line with stock behind it.
 *
 * @return array{ProductVariant, int}
 */
function shelvedGoods(Tenant $tenant, Warehouse $warehouse, int $quantity, int $cost): array
{
    /** @var array{ProductVariant, int} $made */
    $made = inTenantContext($tenant, function () use ($warehouse, $quantity, $cost): array {
        $product = Product::factory()->create(['name' => 'کابل شارژ', 'type' => 'standard']);
        $variant = ProductVariant::factory()->for($product)->create();

        app(StockLedger::class)->record(
            $variant->id,
            $warehouse->id,
            $quantity,
            MovementType::Purchase,
            unitCost: $cost,
        );

        return [$variant, $quantity];
    });

    return $made;
}

/**
 * The payload shape the POS posts.
 *
 * @param  list<array<string, mixed>>  $lines
 * @param  list<array<string, mixed>>  $payments
 * @return array<string, mixed>
 */
/**
 * @param  list<array<string, mixed>>  $lines
 * @param  list<array<string, mixed>>  $payments
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function posPayload(int $branchId, array $lines, array $payments = [], array $overrides = []): array
{
    return [
        'branch_id' => $branchId,
        'party_id' => null,
        'salesperson_id' => null,
        'unit' => 'rial',
        'action' => 'finalise',
        'vat_applied' => false,
        'discount_amount' => 0,
        'shipping_amount' => 0,
        'notes' => null,
        'lines' => $lines,
        'payments' => $payments,
        ...$overrides,
    ];
}

/* -------------------------------------------------------------- the scan -- */

it('resolves a scanned IMEI to the handset, with its price', function (): void {
    $unit = shelvedHandset($this->tenant, $this->warehouse, '356938035643809');

    ($this->inTenant)(function () use ($unit): void {
        $level = PriceLevel::factory()->create(['is_default' => true]);

        app(PriceResolver::class)->setPrice($unit->product_variant_id, $level->id, 62_000_000);
        app(PriceResolver::class)->forget();
    });

    $response = $this->actingAs($this->owner)
        ->getJson($this->url.'/sales/pos/scan?code=356938035643809');

    $response->assertSuccessful();

    expect($response->json('results.0.kind'))->toBe('unit')
        ->and($response->json('results.0.unit_id'))->toBe($unit->id)
        ->and($response->json('results.0.sellable'))->toBeTrue()
        ->and($response->json('results.0.unit_price.value'))->toBe(62_000_000);
});

it('normalises Persian digits so a typed IMEI finds the scanned handset', function (): void {
    shelvedHandset($this->tenant, $this->warehouse, '356938035643809');

    // The same number a salesperson would type on a Persian keypad.
    $response = $this->actingAs($this->owner)
        ->getJson($this->url.'/sales/pos/scan?code=۳۵۶۹۳۸۰۳۵۶۴۳۸۰۹');

    $response->assertSuccessful();

    expect($response->json('results'))->toHaveCount(1)
        ->and($response->json('results.0.imei'))->toBe('356938035643809');
});

it('returns a sold handset with the reason rather than saying nothing was found', function (): void {
    $unit = shelvedHandset($this->tenant, $this->warehouse, '356938035643809');

    ($this->inTenant)(fn () => $unit->forceFill(['status' => UnitStatus::Sold])->save());

    $response = $this->actingAs($this->owner)
        ->getJson($this->url.'/sales/pos/scan?code=356938035643809');

    // "Not found" would send somebody to the shelf to look for a phone that is gone.
    expect($response->json('results.0.sellable'))->toBeFalse()
        ->and($response->json('results.0.blocked_reason'))->toBe('این دستگاه قبلاً فروخته شده است.');
});

/* ------------------------------------------------------------ happy path -- */

it('sells a handset and a quantity line in one basket', function (): void {
    $unit = shelvedHandset($this->tenant, $this->warehouse, '356938035643809', cost: 40_000_000);
    [$variant] = shelvedGoods($this->tenant, $this->warehouse, quantity: 10, cost: 200_000);

    $this->actingAs($this->owner)
        ->post($this->url.'/sales/pos', posPayload($this->warehouse->branch_id, [
            ['unit_id' => $unit->id, 'variant_id' => null, 'quantity' => 1, 'unit_price' => 62_000_000, 'discount_amount' => 0],
            ['unit_id' => null, 'variant_id' => $variant->id, 'quantity' => 2, 'unit_price' => 500_000, 'discount_amount' => 0],
        ], [
            ['method' => 'cash', 'amount' => 63_000_000, 'account_id' => $this->cash->id],
        ]))
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    ($this->inTenant)(function () use ($unit, $variant): void {
        $invoice = SalesInvoice::query()->latest('id')->firstOrFail();

        expect($invoice->status)->toBe(InvoiceStatus::Final)
            ->and($invoice->number)->toBe('INV-000001')
            ->and($invoice->total)->toBe(63_000_000)
            ->and($invoice->paid_total)->toBe(63_000_000);

        // The handset moved by state, not by quantity — a device counted in both
        // registers is counted twice.
        expect($unit->refresh()->status)->toBe(UnitStatus::Sold);

        expect(app(StockLedger::class)->onHand($variant->id, $this->warehouse->id))->toBe(8);

        // Cost snapshots: the device's own cost, and the weighted average for the goods.
        $items = $invoice->items()->orderBy('id')->get();

        expect($items->firstOrFail()->cost_snapshot)->toBe(40_000_000)
            ->and($items->last()?->cost_snapshot)->toBe(200_000);
    });
});

it('keeps the change out of the drawer but records what was handed over', function (): void {
    [$variant] = shelvedGoods($this->tenant, $this->warehouse, quantity: 10, cost: 200_000);

    $this->actingAs($this->owner)
        ->post($this->url.'/sales/pos', posPayload($this->warehouse->branch_id, [
            ['unit_id' => null, 'variant_id' => $variant->id, 'quantity' => 1, 'unit_price' => 482_000, 'discount_amount' => 0],
        ], [
            // The customer hands over 500,000 for a 482,000 sale.
            ['method' => 'cash', 'amount' => 500_000, 'tendered_amount' => 500_000, 'account_id' => $this->cash->id],
        ]))
        ->assertRedirect();

    ($this->inTenant)(function (): void {
        $invoice = SalesInvoice::query()->latest('id')->firstOrFail();
        $payment = $invoice->payments()->firstOrFail();

        // Settled is what the drawer keeps; tendered is what the receipt has to reprint.
        expect($invoice->total)->toBe(482_000)
            ->and($payment->amount)->toBe(482_000)
            ->and($payment->tendered_amount)->toBe(500_000)
            ->and($payment->change())->toBe(18_000);

        // And the ledger sees only the settled figure — change is not revenue.
        $debit = LedgerEntry::query()
            ->where('account_id', $this->cash->id)
            ->sum('debit');

        expect((int) $debit)->toBe(482_000);
    });
});

it('splits one sale across cash, terminal and cheque', function (): void {
    $unit = shelvedHandset($this->tenant, $this->warehouse, '356938035643809');

    $terminal = ($this->inTenant)(fn () => Account::factory()->create(['type' => Account::TYPE_POS_TERMINAL]));
    $party = ($this->inTenant)(fn () => Party::factory()->create());

    $this->actingAs($this->owner)
        ->post($this->url.'/sales/pos', posPayload($this->warehouse->branch_id, [
            ['unit_id' => $unit->id, 'variant_id' => null, 'quantity' => 1, 'unit_price' => 60_000_000, 'discount_amount' => 0],
        ], [
            ['method' => 'cash', 'amount' => 10_000_000, 'account_id' => $this->cash->id],
            ['method' => 'pos_terminal', 'amount' => 20_000_000, 'account_id' => $terminal->id, 'reference' => '881234'],
            ['method' => 'cheque', 'amount' => 30_000_000, 'reference' => '۱۲۳۴۵۶'],
        ], ['party_id' => $party->id]))
        ->assertRedirect();

    ($this->inTenant)(function () use ($party, $terminal): void {
        $invoice = SalesInvoice::query()->latest('id')->firstOrFail();

        expect($invoice->payments)->toHaveCount(3)
            ->and($invoice->paid_total)->toBe(60_000_000)
            ->and($invoice->outstanding())->toBe(0);

        // A cheque is not cash on hand: it settles when it clears (Phase 7), so it
        // debits the customer today rather than a cash box. Treating it as money in the
        // drawer overstates the till every time one bounces.
        expect((int) LedgerEntry::query()->where('account_id', $this->cash->id)->sum('debit'))->toBe(10_000_000)
            ->and((int) LedgerEntry::query()->where('account_id', $terminal->id)->sum('debit'))->toBe(20_000_000)
            ->and(app(LedgerService::class)->partyBalance($party))->toBe(30_000_000);
    });
});

it('posts the unpaid remainder to the customer', function (): void {
    $unit = shelvedHandset($this->tenant, $this->warehouse, '356938035643809');
    $party = ($this->inTenant)(fn () => Party::factory()->create());

    $this->actingAs($this->owner)
        ->post($this->url.'/sales/pos', posPayload($this->warehouse->branch_id, [
            ['unit_id' => $unit->id, 'variant_id' => null, 'quantity' => 1, 'unit_price' => 60_000_000, 'discount_amount' => 0],
        ], [
            ['method' => 'cash', 'amount' => 20_000_000, 'account_id' => $this->cash->id],
        ], ['party_id' => $party->id]))
        ->assertRedirect();

    ($this->inTenant)(function () use ($party): void {
        expect(app(LedgerService::class)->partyBalance($party))->toBe(40_000_000);
    });
});

/* --------------------------------------------------------------- refusals -- */

it('refuses a credit sale with nobody to owe it', function (): void {
    $unit = shelvedHandset($this->tenant, $this->warehouse, '356938035643809');

    $this->actingAs($this->owner)
        ->post($this->url.'/sales/pos', posPayload($this->warehouse->branch_id, [
            ['unit_id' => $unit->id, 'variant_id' => null, 'quantity' => 1, 'unit_price' => 60_000_000, 'discount_amount' => 0],
        ]))
        ->assertSessionHasErrors('lines');

    // And the doomed draft is not left behind for somebody to find later.
    ($this->inTenant)(fn () => expect(SalesInvoice::withTrashed()->count())->toBe(0));
});

it('refuses a handset that was sold while the basket was open', function (): void {
    $unit = shelvedHandset($this->tenant, $this->warehouse, '356938035643809');

    ($this->inTenant)(fn () => $unit->forceFill(['status' => UnitStatus::Sold])->save());

    $this->actingAs($this->owner)
        ->post($this->url.'/sales/pos', posPayload($this->warehouse->branch_id, [
            ['unit_id' => $unit->id, 'variant_id' => null, 'quantity' => 1, 'unit_price' => 60_000_000, 'discount_amount' => 0],
        ], [
            ['method' => 'cash', 'amount' => 60_000_000, 'account_id' => $this->cash->id],
        ]))
        // A Persian sentence on the basket, not a 500, while a customer is standing there.
        ->assertSessionHasErrors('lines');
});

it('refuses the same handset twice in one basket', function (): void {
    $unit = shelvedHandset($this->tenant, $this->warehouse, '356938035643809');

    $this->actingAs($this->owner)
        ->post($this->url.'/sales/pos', posPayload($this->warehouse->branch_id, [
            ['unit_id' => $unit->id, 'variant_id' => null, 'quantity' => 1, 'unit_price' => 60_000_000, 'discount_amount' => 0],
            ['unit_id' => $unit->id, 'variant_id' => null, 'quantity' => 1, 'unit_price' => 60_000_000, 'discount_amount' => 0],
        ], [
            ['method' => 'cash', 'amount' => 120_000_000, 'account_id' => $this->cash->id],
        ]))
        ->assertSessionHasErrors('lines');
});

it('refuses a quantity above one on a serialized line', function (): void {
    $unit = shelvedHandset($this->tenant, $this->warehouse, '356938035643809');

    $this->actingAs($this->owner)
        ->post($this->url.'/sales/pos', posPayload($this->warehouse->branch_id, [
            ['unit_id' => $unit->id, 'variant_id' => null, 'quantity' => 2, 'unit_price' => 60_000_000, 'discount_amount' => 0],
        ], [
            ['method' => 'cash', 'amount' => 120_000_000, 'account_id' => $this->cash->id],
        ]))
        ->assertSessionHasErrors('lines.0.quantity');
});

it('refuses a settling payment with no account to settle into', function (): void {
    [$variant] = shelvedGoods($this->tenant, $this->warehouse, quantity: 10, cost: 200_000);

    $this->actingAs($this->owner)
        ->post($this->url.'/sales/pos', posPayload($this->warehouse->branch_id, [
            ['unit_id' => null, 'variant_id' => $variant->id, 'quantity' => 1, 'unit_price' => 500_000, 'discount_amount' => 0],
        ], [
            ['method' => 'cash', 'amount' => 500_000, 'account_id' => null],
        ]))
        ->assertSessionHasErrors('payments.0.account_id');
});

/* ----------------------------------------------------------------- drafts -- */

it('parks a basket as a draft without touching stock or the ledger', function (): void {
    $unit = shelvedHandset($this->tenant, $this->warehouse, '356938035643809');

    $this->actingAs($this->owner)
        ->post($this->url.'/sales/pos', posPayload($this->warehouse->branch_id, [
            ['unit_id' => $unit->id, 'variant_id' => null, 'quantity' => 1, 'unit_price' => 60_000_000, 'discount_amount' => 0],
        ], [], ['action' => 'park']))
        ->assertRedirect();

    ($this->inTenant)(function () use ($unit): void {
        $invoice = SalesInvoice::query()->latest('id')->firstOrFail();

        expect($invoice->status)->toBe(InvoiceStatus::Draft)
            // A draft has no number to burn.
            ->and($invoice->number)->toBeNull()
            ->and($invoice->total)->toBe(60_000_000);

        expect($unit->refresh()->status)->toBe(UnitStatus::InStock)
            ->and(LedgerEntry::query()->count())->toBe(0);
    });
});

it('issues a parked draft through the same door as the till', function (): void {
    $unit = shelvedHandset($this->tenant, $this->warehouse, '356938035643809');

    $this->actingAs($this->owner)
        ->post($this->url.'/sales/pos', posPayload($this->warehouse->branch_id, [
            ['unit_id' => $unit->id, 'variant_id' => null, 'quantity' => 1, 'unit_price' => 60_000_000, 'discount_amount' => 0],
        ], [
            ['method' => 'cash', 'amount' => 60_000_000, 'account_id' => $this->cash->id],
        ], ['action' => 'park']))
        ->assertRedirect();

    $invoice = ($this->inTenant)(fn () => SalesInvoice::query()->latest('id')->firstOrFail());

    $this->actingAs($this->owner)
        ->post($this->url.'/sales/invoices/'.$invoice->id.'/finalise')
        ->assertRedirect();

    ($this->inTenant)(function () use ($invoice, $unit): void {
        expect($invoice->refresh()->status)->toBe(InvoiceStatus::Final)
            ->and($invoice->number)->toBe('INV-000001')
            ->and($unit->refresh()->status)->toBe(UnitStatus::Sold);
    });
});

/* -------------------------------------------------------------- rounding -- */

it('rounds the grand total once, at the end, and records what it moved', function (): void {
    [$variant] = shelvedGoods($this->tenant, $this->warehouse, quantity: 10, cost: 200_000);

    // Written straight onto the shop's settings document, then the context dropped so
    // the next request reads the new value rather than the one it cached.
    $this->tenant->forceFill(['settings' => ['rounding' => ['step' => 10_000]]])->save();
    app(TenantContext::class)->forget();

    $this->actingAs($this->owner)
        ->post($this->url.'/sales/pos', posPayload($this->warehouse->branch_id, [
            ['unit_id' => null, 'variant_id' => $variant->id, 'quantity' => 1, 'unit_price' => 12_847_300, 'discount_amount' => 0],
        ], [
            ['method' => 'cash', 'amount' => 12_850_000, 'account_id' => $this->cash->id],
        ]))
        ->assertRedirect();

    ($this->inTenant)(function (): void {
        $invoice = SalesInvoice::query()->latest('id')->firstOrFail();

        // Nearest, per ADR 0009. The adjustment is stored rather than absorbed so the
        // gap between the lines and the total has something explaining it.
        expect($invoice->total)->toBe(12_850_000)
            ->and($invoice->rounding_adjustment)->toBe(2_700)
            ->and($invoice->subtotal)->toBe(12_847_300);
    });
});

/* ------------------------------------------------------------- permissions -- */

it('hides the profit figure from a salesperson and shows it to an owner', function (): void {
    $unit = shelvedHandset($this->tenant, $this->warehouse, '356938035643809', cost: 40_000_000);

    $this->actingAs($this->owner)
        ->post($this->url.'/sales/pos', posPayload($this->warehouse->branch_id, [
            ['unit_id' => $unit->id, 'variant_id' => null, 'quantity' => 1, 'unit_price' => 60_000_000, 'discount_amount' => 0],
        ], [
            ['method' => 'cash', 'amount' => 60_000_000, 'account_id' => $this->cash->id],
        ]));

    $invoice = ($this->inTenant)(fn () => SalesInvoice::query()->latest('id')->firstOrFail());

    $this->actingAs($this->owner)
        ->get($this->url.'/sales/invoices/'.$invoice->id)
        ->assertInertia(fn ($page) => $page
            ->where('profit.profit.value', 20_000_000)
            ->where('profit.margin_percent', 33)
        );

    // Withheld entirely, not zeroed: a margin of ۰ on every line teaches a salesperson
    // that the field exists and that they are not trusted with it (Gate 1).
    $this->actingAs($this->seller)
        ->get($this->url.'/sales/invoices/'.$invoice->id)
        ->assertInertia(fn ($page) => $page->where('profit', null));
});

/* -------------------------------------------------------------- isolation -- */

it('will not let another shop scan, open or sell against this one', function (): void {
    $unit = shelvedHandset($this->tenant, $this->warehouse, '356938035643809');

    $this->actingAs($this->owner)
        ->post($this->url.'/sales/pos', posPayload($this->warehouse->branch_id, [
            ['unit_id' => $unit->id, 'variant_id' => null, 'quantity' => 1, 'unit_price' => 60_000_000, 'discount_amount' => 0],
        ], [
            ['method' => 'cash', 'amount' => 60_000_000, 'account_id' => $this->cash->id],
        ]));

    $invoice = ($this->inTenant)(fn () => SalesInvoice::query()->latest('id')->firstOrFail());

    // A second shop, with its own owner and its own subscription.
    $other = Tenant::factory()->withDomain()->create();
    subscribe($other, 'pro');
    app(SubscriptionResolver::class)->forget();
    app(TenantProvisioner::class)->seedRoles($other);

    $intruder = inTenantContext($other, function (): User {
        $user = User::factory()->create();
        $user->assignRole('Owner');

        // A real shop of its own, with somewhere to sell from — otherwise the scan below
        // would be refused for having no branch and would prove nothing about isolation.
        Warehouse::factory()->create(['is_sellable' => true, 'is_default' => true]);

        return $user;
    });

    $otherUrl = tenantUrl($other);

    // Tenant A's invoice id, requested on tenant B's hostname by tenant B's owner.
    $this->actingAs($intruder)
        ->get($otherUrl.'/sales/invoices/'.$invoice->id)
        ->assertNotFound();

    $this->actingAs($intruder)
        ->post($otherUrl.'/sales/invoices/'.$invoice->id.'/void', ['reason' => 'تلاش برای ابطال فاکتور شعبه دیگر'])
        ->assertNotFound();

    // And the scan endpoint does not confirm the existence of another shop's handset.
    $response = $this->actingAs($intruder)->getJson($otherUrl.'/sales/pos/scan?code=356938035643809');

    $response->assertSuccessful();
    expect($response->json('results'))->toBe([]);
});
