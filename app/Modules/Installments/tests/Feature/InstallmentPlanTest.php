<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\CRM\Models\Account;
use App\Modules\CRM\Models\Party;
use App\Modules\CRM\Services\LedgerService;
use App\Modules\Identity\Models\User;
use App\Modules\Installments\Models\InstallmentPlan;
use App\Modules\Inventory\Enums\UnitStatus;
use App\Modules\Inventory\Models\ProductUnit;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Platform\Services\PlanCatalogueSeeder;
use App\Modules\Platform\Services\SubscriptionResolver;
use App\Modules\Platform\Services\TenantProvisioner;
use App\Modules\Sales\Models\SalesInvoice;
use App\Support\Jalali;
use App\Support\Tenancy\TenantContext;

/**
 * فروش اقساطی, from a real sale.
 *
 * `InstallmentSchedulerTest` already proves the arithmetic against exact rial. These
 * cover what only the whole stack can: that the plan matches the invoice it hangs off,
 * that the customer's balance ends up equal to what the contract says they owe, and that
 * the principal is not counted twice.
 */
beforeEach(function (): void {
    app(PlanCatalogueSeeder::class)->sync();

    $this->tenant = Tenant::factory()->withDomain()->create();
    $this->url = appUrl();

    subscribe($this->tenant, 'pro');
    app(SubscriptionResolver::class)->forget();
    app(TenantProvisioner::class)->seedRoles($this->tenant);

    /** @var array{User, Warehouse, Account, Party, ProductUnit} $fixtures */
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

        return [$owner, $warehouse, $cash, Party::factory()->create(), $unit];
    });

    [$this->owner, $this->warehouse, $this->cash, $this->party, $this->unit] = $fixtures;

    $this->inTenant = fn (Closure $callback): mixed => inTenantContext($this->tenant, $callback);
});

afterEach(fn () => app(TenantContext::class)->forget());

/**
 * Sell the handset for 60,000,000 with a down payment, leaving a balance to finance.
 */
function saleWithDownPayment(int $downPayment): SalesInvoice
{
    /** @var Tenant $tenant */
    $tenant = test()->tenant;
    /** @var string $url */
    $url = test()->url;
    /** @var User $owner */
    $owner = test()->owner;
    /** @var Warehouse $warehouse */
    $warehouse = test()->warehouse;
    /** @var Party $party */
    $party = test()->party;
    /** @var ProductUnit $unit */
    $unit = test()->unit;
    /** @var Account $cash */
    $cash = test()->cash;

    test()->actingAs($owner)->post($url.'/sales/pos', [
        'branch_id' => $warehouse->branch_id,
        'party_id' => $party->id,
        'salesperson_id' => null,
        'unit' => 'rial',
        'action' => 'finalise',
        'vat_applied' => false,
        'discount_amount' => 0,
        'shipping_amount' => 0,
        'notes' => null,
        'lines' => [
            ['unit_id' => $unit->id, 'variant_id' => null, 'quantity' => 1, 'unit_price' => 60_000_000, 'discount_amount' => 0],
        ],
        'payments' => $downPayment === 0 ? [] : [
            ['method' => 'cash', 'amount' => $downPayment, 'account_id' => $cash->id],
        ],
    ])->assertSessionHasNoErrors();

    /** @var SalesInvoice $invoice */
    $invoice = inTenantContext($tenant, fn () => SalesInvoice::query()->latest('id')->firstOrFail());

    return $invoice;
}

/* ------------------------------------------------------------ happy path -- */

it('finances what is left after the down payment, not the whole invoice', function (): void {
    $invoice = saleWithDownPayment(12_000_000);

    $this->actingAs($this->owner)
        ->post($this->url.'/installments/invoices/'.$invoice->id.'/plan', [
            'count' => 6,
            'profit_percent' => 20,
            'interval_months' => 1,
            'first_due' => '1405/06/15',
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    ($this->inTenant)(function (): void {
        $plan = InstallmentPlan::query()->firstOrFail();

        expect($plan->number)->toBe('INS-000001')
            // 60,000,000 sold, 12,000,000 paid at the till.
            ->and($plan->down_payment)->toBe(12_000_000)
            ->and($plan->principal)->toBe(48_000_000)
            ->and($plan->profit_amount)->toBe(9_600_000)
            ->and($plan->total_payable)->toBe(57_600_000)
            ->and($plan->contractTotal())->toBe(69_600_000);

        expect($plan->rows)->toHaveCount(6);

        // The rows are the plan: they must add up to the figure on the contract.
        expect($plan->rows->sum('amount'))->toBe($plan->total_payable);
    });
});

it('leaves the customer owing exactly what the contract says', function (): void {
    $invoice = saleWithDownPayment(12_000_000);

    $this->actingAs($this->owner)
        ->post($this->url.'/installments/invoices/'.$invoice->id.'/plan', [
            'count' => 6,
            'profit_percent' => 20,
            'interval_months' => 1,
            'first_due' => '1405/06/15',
        ]);

    ($this->inTenant)(function (): void {
        $plan = InstallmentPlan::query()->firstOrFail();

        // The sale already put 48,000,000 on the customer. The plan adds only the
        // profit — posting the principal again would double what the shop is owed.
        expect(app(LedgerService::class)->partyBalance($this->party))->toBe(57_600_000)
            ->and(app(LedgerService::class)->partyBalance($this->party))->toBe($plan->total_payable);
    });
});

it('spaces the due dates by Jalali months from the chosen first date', function (): void {
    $invoice = saleWithDownPayment(0);

    $this->actingAs($this->owner)
        ->post($this->url.'/installments/invoices/'.$invoice->id.'/plan', [
            'count' => 3,
            'profit_percent' => 0,
            'interval_months' => 1,
            'first_due' => '1405/02/15',
        ])
        ->assertSessionHasNoErrors();

    ($this->inTenant)(function (): void {
        $plan = InstallmentPlan::query()->with('rows')->firstOrFail();

        $dates = $plan->rows->map(fn ($row): string => Jalali::format($row->due_at, Jalali::DATE, false))->all();

        expect($dates)->toBe(['1405/02/15', '1405/03/15', '1405/04/15']);
    });
});

it('records the ضامن when one is named', function (): void {
    $invoice = saleWithDownPayment(0);
    $guarantor = ($this->inTenant)(fn () => Party::factory()->create(['name' => 'رضا امینی']));

    $this->actingAs($this->owner)
        ->post($this->url.'/installments/invoices/'.$invoice->id.'/plan', [
            'count' => 4,
            'profit_percent' => 0,
            'interval_months' => 1,
            'first_due' => '1405/06/15',
            'guarantor_party_id' => $guarantor->id,
        ])
        ->assertSessionHasNoErrors();

    ($this->inTenant)(fn () => expect(
        InstallmentPlan::query()->firstOrFail()->guarantor_party_id
    )->toBe($guarantor->id));
});

/* -------------------------------------------------------------- the form -- */

it('previews the whole schedule before anything is written', function (): void {
    $invoice = saleWithDownPayment(0);

    $response = $this->actingAs($this->owner)->getJson(
        $this->url.'/installments/invoices/'.$invoice->id.'/plan/preview'
        .'?count=3&profit_percent=0&interval_months=1&first_due=1405/06/15'
    );

    $response->assertSuccessful();

    expect($response->json('rows'))->toHaveCount(3)
        ->and($response->json('total_payable.value'))->toBe(60_000_000)
        ->and($response->json('rows.0.due_at_jalali'))->toBe('1405/06/15');

    // Nothing was written by looking.
    ($this->inTenant)(fn () => expect(InstallmentPlan::query()->count())->toBe(0));
});

it('accepts a first-due date typed on a Persian keypad', function (): void {
    $invoice = saleWithDownPayment(0);

    $this->actingAs($this->owner)
        ->post($this->url.'/installments/invoices/'.$invoice->id.'/plan', [
            'count' => 3,
            'profit_percent' => 0,
            'interval_months' => 1,
            // The same date a salesperson types with a Persian keyboard.
            'first_due' => '۱۴۰۵/۰۶/۱۵',
        ])
        ->assertSessionHasNoErrors();

    ($this->inTenant)(function (): void {
        $plan = InstallmentPlan::query()->with('rows')->firstOrFail();

        expect(Jalali::format($plan->rows->first()?->due_at, Jalali::DATE, false))->toBe('1405/06/15');
    });
});

it('refuses a malformed date with a Persian message, not the date library shouting', function (): void {
    $invoice = saleWithDownPayment(0);

    $this->actingAs($this->owner)
        ->post($this->url.'/installments/invoices/'.$invoice->id.'/plan', [
            'count' => 3,
            'profit_percent' => 0,
            'interval_months' => 1,
            'first_due' => 'دیروز',
        ])
        ->assertSessionHasErrors('first_due');

    ($this->inTenant)(fn () => expect(InstallmentPlan::query()->count())->toBe(0));
});

/* -------------------------------------------------------------- refusals -- */

it('refuses a second plan against the same invoice', function (): void {
    $invoice = saleWithDownPayment(0);

    $body = ['count' => 6, 'profit_percent' => 0, 'interval_months' => 1, 'first_due' => '1405/06/15'];

    $this->actingAs($this->owner)
        ->post($this->url.'/installments/invoices/'.$invoice->id.'/plan', $body)
        ->assertSessionHasNoErrors();

    // Two schedules against one sale would let the shop chase the same debt twice, with
    // neither plan aware of the other.
    $this->actingAs($this->owner)
        ->post($this->url.'/installments/invoices/'.$invoice->id.'/plan', $body)
        ->assertSessionHasErrors('count');

    ($this->inTenant)(fn () => expect(InstallmentPlan::query()->count())->toBe(1));
});

it('refuses a plan on an invoice that is already settled', function (): void {
    $invoice = saleWithDownPayment(60_000_000);

    $this->actingAs($this->owner)
        ->post($this->url.'/installments/invoices/'.$invoice->id.'/plan', [
            'count' => 6, 'profit_percent' => 0, 'interval_months' => 1, 'first_due' => '1405/06/15',
        ])
        ->assertSessionHasErrors('count');
});

/* ------------------------------------------------------------- isolation -- */

it('will not let another shop write or read a plan here', function (): void {
    $invoice = saleWithDownPayment(0);

    $this->actingAs($this->owner)->post($this->url.'/installments/invoices/'.$invoice->id.'/plan', [
        'count' => 6, 'profit_percent' => 0, 'interval_months' => 1, 'first_due' => '1405/06/15',
    ]);

    $plan = ($this->inTenant)(fn () => InstallmentPlan::query()->firstOrFail());

    $other = Tenant::factory()->withDomain()->create();
    subscribe($other, 'pro');
    app(SubscriptionResolver::class)->forget();
    app(TenantProvisioner::class)->seedRoles($other);

    $intruder = inTenantContext($other, function (): User {
        $user = User::factory()->create();
        $user->assignRole('Owner');

        return $user;
    });

    $otherUrl = appUrl();

    $this->actingAs($intruder)->get($otherUrl.'/installments/plans/'.$plan->id)->assertNotFound();
    $this->actingAs($intruder)->get($otherUrl.'/installments/plans/'.$plan->id.'/print')->assertNotFound();

    $this->actingAs($intruder)
        ->post($otherUrl.'/installments/invoices/'.$invoice->id.'/plan', [
            'count' => 6, 'profit_percent' => 0, 'interval_months' => 1, 'first_due' => '1405/06/15',
        ])
        ->assertNotFound();
});

/* ----------------------------------------------------------------- print -- */

it('prints a contract whose schedule matches the stored rows', function (): void {
    $invoice = saleWithDownPayment(0);

    $this->actingAs($this->owner)->post($this->url.'/installments/invoices/'.$invoice->id.'/plan', [
        'count' => 3, 'profit_percent' => 10, 'interval_months' => 1, 'first_due' => '1405/06/15',
    ]);

    $plan = ($this->inTenant)(fn () => InstallmentPlan::query()->with('rows')->firstOrFail());

    $this->actingAs($this->owner)
        ->get($this->url.'/installments/plans/'.$plan->id.'/print')
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->where('plan.number', $plan->number)
            ->where('plan.rows.0.amount.value', $plan->rows->first()?->amount)
            ->where('plan.total_payable.value', $plan->total_payable)
            // The shop's own name, never a hostname (golden rule 1b).
            ->where('shop.name', $this->tenant->name)
        );
});
