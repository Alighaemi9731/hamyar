<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\CRM\Models\Account;
use App\Modules\CRM\Models\LedgerEntry;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Enums\UnitStatus;
use App\Modules\Inventory\Models\ProductUnit;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Platform\Services\PlanCatalogueSeeder;
use App\Modules\Platform\Services\SubscriptionResolver;
use App\Modules\Platform\Services\TenantProvisioner;
use App\Modules\Sales\Enums\InvoiceStatus;
use App\Modules\Sales\Models\SalesInvoice;
use App\Support\Tenancy\TenantContext;

/**
 * پیش‌فاکتور — a priced promise, and what happens when the customer says yes.
 */
beforeEach(function (): void {
    app(PlanCatalogueSeeder::class)->sync();

    $this->tenant = Tenant::factory()->withDomain()->create();
    $this->url = tenantUrl($this->tenant);

    subscribe($this->tenant, 'pro');
    app(SubscriptionResolver::class)->forget();
    app(TenantProvisioner::class)->seedRoles($this->tenant);

    /** @var array{User, Warehouse, Account, ProductUnit} $fixtures */
    $fixtures = inTenantContext($this->tenant, function (): array {
        $owner = User::factory()->create();
        $owner->assignRole('Owner');

        $warehouse = Warehouse::factory()->create(['is_sellable' => true, 'is_default' => true]);

        $cash = Account::factory()->create(['type' => Account::TYPE_CASH, 'is_default' => true]);
        Account::factory()->create(['type' => Account::TYPE_SALES]);

        $product = Product::factory()->serialized()->create(['name' => 'آیفون ۱۵ پرو']);
        $variant = ProductVariant::factory()->for($product)->create();

        $unit = ProductUnit::factory()->for($variant, 'variant')->create([
            'warehouse_id' => $warehouse->id,
            'status' => UnitStatus::InStock,
            'imei1' => '356938035643809',
            'cost' => 40_000_000,
        ]);

        return [$owner, $warehouse, $cash, $unit];
    });

    [$this->owner, $this->warehouse, $this->cash, $this->unit] = $fixtures;

    $this->inTenant = fn (Closure $callback): mixed => inTenantContext($this->tenant, $callback);
});

afterEach(fn () => app(TenantContext::class)->forget());

/**
 * The basket the POS posts when somebody presses «پیش‌فاکتور».
 *
 * @return array<string, mixed>
 */
function quotePayload(int $branchId, int $unitId): array
{
    return [
        'branch_id' => $branchId,
        'party_id' => null,
        'salesperson_id' => null,
        'unit' => 'rial',
        'action' => 'quote',
        'vat_applied' => false,
        'discount_amount' => 0,
        'shipping_amount' => 0,
        'notes' => null,
        'lines' => [
            ['unit_id' => $unitId, 'variant_id' => null, 'quantity' => 1, 'unit_price' => 60_000_000, 'discount_amount' => 0],
        ],
        'payments' => [],
    ];
}

it('issues a numbered quote that moves nothing', function (): void {
    $this->actingAs($this->owner)
        ->post($this->url.'/sales/pos', quotePayload($this->warehouse->branch_id, $this->unit->id))
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    ($this->inTenant)(function (): void {
        $quote = SalesInvoice::query()->latest('id')->firstOrFail();

        // Numbered on creation, unlike a parked draft: this one gets handed over, and
        // the customer quotes the number back a week later.
        expect($quote->type)->toBe(SalesInvoice::TYPE_QUOTE)
            ->and($quote->number)->toBe('QUO-000001')
            ->and($quote->status)->toBe(InvoiceStatus::Draft)
            ->and($quote->total)->toBe(60_000_000);

        // A promise about price is not a reservation of goods.
        expect($this->unit->refresh()->status)->toBe(UnitStatus::InStock)
            ->and(LedgerEntry::query()->count())->toBe(0);
    });
});

it('drops payments from a quote rather than inventing money', function (): void {
    $payload = quotePayload($this->warehouse->branch_id, $this->unit->id);
    $payload['payments'] = [
        ['method' => 'cash', 'amount' => 60_000_000, 'account_id' => $this->cash->id],
    ];

    $this->actingAs($this->owner)
        ->post($this->url.'/sales/pos', $payload)
        ->assertSessionHasNoErrors();

    ($this->inTenant)(function (): void {
        $quote = SalesInvoice::query()->latest('id')->firstOrFail();

        // Nobody has paid anything against a promise. Dropped rather than refused: the
        // payment box may simply have been half-filled when «پیش‌فاکتور» was chosen.
        expect($quote->payments()->count())->toBe(0)
            ->and($quote->paid_total)->toBe(0);
    });
});

it('converts to a new invoice and leaves the quote standing', function (): void {
    $this->actingAs($this->owner)
        ->post($this->url.'/sales/pos', quotePayload($this->warehouse->branch_id, $this->unit->id));

    $quote = ($this->inTenant)(fn () => SalesInvoice::query()->latest('id')->firstOrFail());

    $this->actingAs($this->owner)
        ->post($this->url.'/sales/quotes/'.$quote->id.'/convert')
        ->assertSessionHasNoErrors()
        // Straight into the till: a converted quote is a basket about to be paid for.
        ->assertRedirect();

    ($this->inTenant)(function () use ($quote): void {
        $invoice = SalesInvoice::query()->where('type', SalesInvoice::TYPE_INVOICE)->latest('id')->firstOrFail();

        expect($invoice->status)->toBe(InvoiceStatus::Draft)
            ->and($invoice->total)->toBe(60_000_000)
            ->and($invoice->items()->count())->toBe(1);

        // The customer is holding a piece of paper with QUO-000001 on it. That document
        // survives, and says what it became.
        expect($quote->refresh()->number)->toBe('QUO-000001')
            ->and($quote->type)->toBe(SalesInvoice::TYPE_QUOTE)
            ->and($quote->converted_to_id)->toBe($invoice->id);
    });
});

it('refuses to convert the same quote twice', function (): void {
    $this->actingAs($this->owner)
        ->post($this->url.'/sales/pos', quotePayload($this->warehouse->branch_id, $this->unit->id));

    $quote = ($this->inTenant)(fn () => SalesInvoice::query()->latest('id')->firstOrFail());

    $this->actingAs($this->owner)->post($this->url.'/sales/quotes/'.$quote->id.'/convert');

    $this->actingAs($this->owner)
        ->post($this->url.'/sales/quotes/'.$quote->id.'/convert')
        ->assertSessionHasErrors('quote');

    ($this->inTenant)(fn () => expect(
        SalesInvoice::query()->where('type', SalesInvoice::TYPE_INVOICE)->count()
    )->toBe(1));
});

it('refuses to finalise a quote directly', function (): void {
    $this->actingAs($this->owner)
        ->post($this->url.'/sales/pos', quotePayload($this->warehouse->branch_id, $this->unit->id));

    $quote = ($this->inTenant)(fn () => SalesInvoice::query()->latest('id')->firstOrFail());

    // A quote has to become an invoice first — otherwise a QUO- number ends up on a
    // document that moved stock and posted revenue.
    $this->actingAs($this->owner)
        ->post($this->url.'/sales/invoices/'.$quote->id.'/finalise')
        ->assertSessionHasErrors('invoice');
});

it('lists quotes and marks the converted ones', function (): void {
    $this->actingAs($this->owner)
        ->post($this->url.'/sales/pos', quotePayload($this->warehouse->branch_id, $this->unit->id));

    $quote = ($this->inTenant)(fn () => SalesInvoice::query()->latest('id')->firstOrFail());

    $this->actingAs($this->owner)
        ->get($this->url.'/sales/quotes')
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->where('quotes.rows.0.number', 'QUO-000001')
            ->where('quotes.rows.0.converted_to', null)
        );

    $this->actingAs($this->owner)->post($this->url.'/sales/quotes/'.$quote->id.'/convert');

    $this->actingAs($this->owner)
        ->get($this->url.'/sales/quotes')
        ->assertInertia(fn ($page) => $page->where('quotes.rows.0.converted_to.number', null));

    // The quote list is quotes only — a converted quote's invoice must not appear twice.
    $this->actingAs($this->owner)
        ->get($this->url.'/sales/quotes')
        ->assertInertia(fn ($page) => $page->where('quotes.total', 1));
});

it('will not let another shop convert this one', function (): void {
    $this->actingAs($this->owner)
        ->post($this->url.'/sales/pos', quotePayload($this->warehouse->branch_id, $this->unit->id));

    $quote = ($this->inTenant)(fn () => SalesInvoice::query()->latest('id')->firstOrFail());

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
        ->post(tenantUrl($other).'/sales/quotes/'.$quote->id.'/convert')
        ->assertNotFound();

    ($this->inTenant)(fn () => expect($quote->refresh()->converted_to_id)->toBeNull());
});
