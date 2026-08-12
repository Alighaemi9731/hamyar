<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\CRM\Models\Account;
use App\Modules\CRM\Models\Party;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Enums\MovementType;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockLedger;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Platform\Services\PlanCatalogueSeeder;
use App\Modules\Platform\Services\SubscriptionResolver;
use App\Modules\Platform\Services\TenantProvisioner;
use App\Modules\Sales\Models\SalesInvoice;
use App\Support\Settings\CommissionSettings;
use App\Support\Tenancy\TenantContext;

/**
 * Commission — a share of margin, approved at DECISION GATE 3.
 *
 * The tests that matter are the ones about the incentive: paying on margin has to make
 * a discount cost the person who gave it, or the rule is just turnover with extra steps.
 */
beforeEach(function (): void {
    app(PlanCatalogueSeeder::class)->sync();

    $this->tenant = Tenant::factory()->withDomain()->create();
    $this->url = tenantUrl($this->tenant);

    subscribe($this->tenant, 'pro');
    app(SubscriptionResolver::class)->forget();
    app(TenantProvisioner::class)->seedRoles($this->tenant);

    // 5% of margin, set before any sale so the invoices snapshot it.
    $this->tenant->forceFill(['settings' => ['commission' => ['rate' => 5]]])->save();
    app(TenantContext::class)->forget();

    /** @var array{User, User, Warehouse, Account, ProductVariant, Party} $fixtures */
    $fixtures = inTenantContext($this->tenant, function (): array {
        $owner = User::factory()->create();
        $owner->assignRole('Owner');

        $seller = User::factory()->create();
        $seller->assignRole('Salesperson');

        $warehouse = Warehouse::factory()->create(['is_sellable' => true, 'is_default' => true]);

        $cash = Account::factory()->create(['type' => Account::TYPE_CASH, 'is_default' => true]);
        Account::factory()->create(['type' => Account::TYPE_SALES]);

        $product = Product::factory()->create(['name' => 'شارژر', 'type' => 'standard']);
        $variant = ProductVariant::factory()->for($product)->create();

        // Cost 60,000,000 rial a unit.
        app(StockLedger::class)->record($variant->id, $warehouse->id, 100, MovementType::Purchase, unitCost: 60_000_000);

        return [$owner, $seller, $warehouse, $cash, $variant, Party::factory()->create()];
    });

    [$this->owner, $this->seller, $this->warehouse, $this->cash, $this->variant, $this->party] = $fixtures;

    $this->inTenant = fn (Closure $callback): mixed => inTenantContext($this->tenant, $callback);
});

afterEach(fn () => app(TenantContext::class)->forget());

/**
 * Sell one unit at the given price, optionally with VAT.
 */
function sellAt(int $price, bool $vat = false): SalesInvoice
{
    /** @var Warehouse $warehouse */
    $warehouse = test()->warehouse;
    /** @var User $owner */
    $owner = test()->owner;
    /** @var ProductVariant $variant */
    $variant = test()->variant;
    /** @var Account $cash */
    $cash = test()->cash;
    /** @var string $url */
    $url = test()->url;
    /** @var Tenant $tenant */
    $tenant = test()->tenant;
    /** @var Party $party */
    $party = test()->party;

    // Sold on credit, so the helper does not have to predict a rounded total in order
    // to pay it exactly. Commission is a share of margin and does not care whether the
    // money has arrived.
    test()->actingAs($owner)->post($url.'/sales/pos', [
        'branch_id' => $warehouse->branch_id,
        'party_id' => $party->id,
        'salesperson_id' => $owner->id,
        'unit' => 'rial',
        'action' => 'finalise',
        'vat_applied' => $vat,
        'discount_amount' => 0,
        'shipping_amount' => 0,
        'notes' => null,
        'lines' => [
            ['unit_id' => null, 'variant_id' => $variant->id, 'quantity' => 1, 'unit_price' => $price, 'discount_amount' => 0],
        ],
        'payments' => [],
    ])->assertSessionHasNoErrors()->assertRedirect();

    /** @var SalesInvoice $invoice */
    $invoice = inTenantContext($tenant, fn () => SalesInvoice::query()->latest('id')->firstOrFail());

    return $invoice;
}

/* ------------------------------------------------------------- the maths -- */

it('pays a share of the margin, not of the sale', function (): void {
    $invoice = sellAt(100_000_000);

    // Margin 40,000,000 rial; 5% of it is 2,000,000. Five percent of the *sale* would
    // have been 5,000,000 — two and a half times as much for the same work.
    expect($invoice->commission_amount)->toBe(2_000_000)
        ->and($invoice->commission_rate)->toBe(5);
});

it('makes a discount cost the salesperson who gave it', function (): void {
    $full = sellAt(100_000_000);
    $discounted = sellAt(90_000_000);

    // The sale fell 10%; the margin fell 25%, and so did the commission. That
    // proportionality is the whole reason Gate 3 chose margin over turnover.
    expect($full->commission_amount)->toBe(2_000_000)
        ->and($discounted->commission_amount)->toBe(1_500_000);
});

it('does not pay commission on tax collected for the state', function (): void {
    $invoice = sellAt(100_000_000, vat: true);

    // The customer paid 110,000,000, of which 10,000,000 is the state's. Margin is
    // still 40,000,000, so the commission is unchanged — paying on the gross would hand
    // a salesperson a share of somebody else's money.
    expect($invoice->total)->toBe(110_000_000)
        ->and($invoice->commission_amount)->toBe(2_000_000);
});

it('accrues nothing when the shop has set no rate', function (): void {
    $this->tenant->forceFill(['settings' => []])->save();
    app(TenantContext::class)->forget();

    $invoice = sellAt(100_000_000);

    // Zero rather than a sensible-looking default: a shop that never opened the
    // settings screen has not agreed to owe anybody anything.
    expect($invoice->commission_amount)->toBe(0)
        ->and($invoice->commission_rate)->toBe(0);
});

it('keeps the rate the invoice was issued under when the shop changes it', function (): void {
    $invoice = sellAt(100_000_000);

    $this->tenant->forceFill(['settings' => ['commission' => ['rate' => 50]]])->save();
    app(TenantContext::class)->forget();

    // Re-read inside the shop's context: `forget()` above dropped it, and without a
    // pinned `app.tenant_id` the RLS policy denies the row rather than returning it.
    $reloaded = ($this->inTenant)(fn () => $invoice->fresh());

    // Payroll has already been run against the old figure. A rate changed in Mehr must
    // not restate what was earned in Shahrivar.
    expect($reloaded->commission_amount)->toBe(2_000_000)
        ->and($reloaded->commission_rate)->toBe(5);
});

it('earns nothing on a sale made at a loss, and never owes it back', function (): void {
    // Sold below the 60,000,000 cost.
    $invoice = sellAt(50_000_000);

    // Selling at a loss is a decision somebody above the salesperson made; clawing it
    // out of their commission is not what the word means.
    expect($invoice->commission_amount)->toBe(0);
});

it('floors to a whole toman like every other derived figure', function (): void {
    // Margin 3,333 rial → 5% is 166.65 rial, which is not a payable amount.
    expect((new CommissionSettings(5))->on(3_333))->toBe(160)
        ->and((new CommissionSettings(5))->on(3_333) % 10)->toBe(0);
});

/* ------------------------------------------------------------ permissions -- */

it('hides commission from the salesperson who earned it', function (): void {
    $invoice = sellAt(100_000_000);

    $this->actingAs($this->owner)
        ->get($this->url.'/sales/invoices/'.$invoice->id)
        ->assertInertia(fn ($page) => $page->where('commission.amount.value', 2_000_000));

    // Looks wrong until you do the arithmetic: commission is a known percentage of
    // margin, so telling somebody their commission tells them the margin — and Gate 1
    // was explicit that a Salesperson is blind to cost and profit. A shop that wants
    // otherwise grants `sales.view_profit`, which is the override Gate 1 allowed.
    $this->actingAs($this->seller)
        ->get($this->url.'/sales/invoices/'.$invoice->id)
        ->assertInertia(fn ($page) => $page->where('commission', null)->where('profit', null));
});
