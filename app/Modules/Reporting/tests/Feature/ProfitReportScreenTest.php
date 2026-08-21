<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Brand;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\CRM\Models\Account;
use App\Modules\CRM\Models\Party;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Enums\MovementType;
use App\Modules\Inventory\Models\ProductUnit;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockLedger;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Platform\Services\PlanCatalogueSeeder;
use App\Modules\Platform\Services\SubscriptionResolver;
use App\Modules\Platform\Services\TenantProvisioner;
use App\Support\Tenancy\TenantContext;

/**
 * The profit report — «از چی سود کردیم» — and who is allowed to ask.
 *
 * ## The fixture is built so that best-selling and best-earning disagree
 *
 * | what   | qty | price each  | cost each  | revenue     | margin      |
 * |--------|-----|-------------|------------|-------------|-------------|
 * | گلس    | 10  |  30,000,000 | 10,000,000 | 300,000,000 | 200,000,000 |
 * | باتری  |  1  | 250,000,000 | 60,000,000 | 250,000,000 | 190,000,000 |
 *
 * Glass wins on both here — deliberately not the interesting case. The interesting one is
 * the handset below: sold for 400,000,000 having cost 380,000,000, it is the **largest**
 * revenue line in the shop and the **smallest** margin. A report that ranked by revenue
 * and called itself a profit report would put it first; this one puts it last, and that
 * ordering is the assertion this file exists for.
 *
 * ## Nothing here re-derives a figure the sales report already owns
 *
 * The summary is `SalesReports::summary()` — same call, same numbers. Two screens quoting
 * different profit for one month is a support call that opens with "which one is right".
 */
beforeEach(function (): void {
    app(PlanCatalogueSeeder::class)->sync();

    $this->tenant = Tenant::factory()->withDomain()->create();
    $this->url = appUrl();

    subscribe($this->tenant, 'pro');
    app(SubscriptionResolver::class)->forget();
    app(TenantProvisioner::class)->seedRoles($this->tenant);

    /** @var array{User, User, Warehouse, ProductVariant, ProductVariant, ProductUnit, Party} $fixtures */
    $fixtures = app(TenantContext::class)->runFor($this->tenant, function (): array {
        $owner = User::factory()->create(['name' => 'مالک']);
        $owner->assignRole('Owner');

        $cashier = User::factory()->create();
        $cashier->assignRole('Cashier');

        $warehouse = Warehouse::factory()->create(['is_sellable' => true, 'is_default' => true]);

        Account::factory()->create(['type' => Account::TYPE_CASH, 'is_default' => true]);
        Account::factory()->create(['type' => Account::TYPE_SALES]);

        $apple = Brand::factory()->create(['name' => 'Apple', 'name_fa' => 'اپل']);
        $samsung = Brand::factory()->create(['name' => 'Samsung', 'name_fa' => 'سامسونگ']);

        $glass = ProductVariant::factory()
            ->for(Product::factory()->create(['name' => 'گلس', 'type' => 'standard', 'brand_id' => $samsung->id]))
            ->create();

        $battery = ProductVariant::factory()
            ->for(Product::factory()->create(['name' => 'باتری', 'type' => 'standard', 'brand_id' => $samsung->id]))
            ->create();

        // The handset: its own cost lives on the unit, and the line that sells it
        // snapshots that cost. 20,000,000 of margin on a 400,000,000 sale.
        $phoneVariant = ProductVariant::factory()
            ->for(Product::factory()->create(['name' => 'آیفون ۱۵', 'type' => 'serialized', 'brand_id' => $apple->id]))
            ->create();

        $unit = ProductUnit::query()->create([
            'product_variant_id' => $phoneVariant->id,
            'warehouse_id' => $warehouse->id,
            'imei1' => '354977061234563',
            'status' => 'in_stock',
            'condition' => 'new',
            'cost' => 380_000_000,
        ]);

        $ledger = app(StockLedger::class);
        $ledger->record($glass->id, $warehouse->id, 50, MovementType::Purchase, unitCost: 10_000_000);
        $ledger->record($battery->id, $warehouse->id, 50, MovementType::Purchase, unitCost: 60_000_000);

        return [$owner, $cashier, $warehouse, $glass, $battery, $unit, Party::factory()->create(['name' => 'سمیرا احمدی'])];
    });

    [$this->owner, $this->cashier, $this->warehouse, $this->glass, $this->battery, $this->unit, $this->party] = $fixtures;

    sellProfitLine(['variant_id' => $this->glass->id, 'quantity' => 10, 'unit_price' => 30_000_000]);
    sellProfitLine(['variant_id' => $this->battery->id, 'quantity' => 1, 'unit_price' => 250_000_000]);
    sellProfitLine(['unit_id' => $this->unit->id, 'quantity' => 1, 'unit_price' => 400_000_000]);
});

afterEach(fn () => app(TenantContext::class)->forget());

/**
 * One finalised invoice through the real POS endpoint, on credit.
 *
 * @param  array<string, mixed>  $line
 */
function sellProfitLine(array $line): void
{
    /** @var Warehouse $warehouse */
    $warehouse = test()->warehouse;
    /** @var User $owner */
    $owner = test()->owner;
    /** @var Party $party */
    $party = test()->party;
    /** @var string $url */
    $url = test()->url;

    test()->actingAs($owner)->post($url.'/sales/pos', [
        'branch_id' => $warehouse->branch_id,
        'party_id' => $party->id,
        'salesperson_id' => $owner->id,
        'unit' => 'rial',
        'action' => 'finalise',
        'vat_applied' => false,
        'discount_amount' => 0,
        'shipping_amount' => 0,
        'notes' => null,
        'lines' => [[
            'unit_id' => null,
            'variant_id' => null,
            'discount_amount' => 0,
            ...$line,
        ]],
        'payments' => [],
    ])->assertSessionHasNoErrors()->assertRedirect();
}

/* -------------------------------------------------------------- the cuts -- */

it('ranks products by profit and not by how many went out of the door', function (): void {
    /*
    | The assertion the report exists for. By revenue the phone leads at 400,000,000; by
    | profit it is last at 20,000,000. A profit report that sorted by revenue would look
    | entirely plausible and answer the wrong question — which is exactly why it is
    | asserted as an ORDER rather than as three independent figures.
    */
    $this->actingAs($this->owner)
        ->get($this->url.'/reporting/profit?cut=product')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Reporting::Reports/Profit')
            ->has('rows', 3)
            ->where('rows.0.label', 'گلس')
            ->where('rows.0.margin.value', 200_000_000)
            ->where('rows.1.label', 'باتری')
            ->where('rows.1.margin.value', 190_000_000)
            // Biggest sale in the shop, smallest profit, and therefore last.
            ->where('rows.2.label', 'آیفون ۱۵')
            ->where('rows.2.revenue.value', 400_000_000)
            ->where('rows.2.margin.value', 20_000_000)
            ->etc()
        );
});

it('ranks brands by profit', function (): void {
    $this->actingAs($this->owner)
        ->get($this->url.'/reporting/profit?cut=brand')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('rows', 2)
            // سامسونگ: 200,000,000 + 190,000,000. اپل: 20,000,000.
            ->where('rows.0.label', 'سامسونگ')
            ->where('rows.0.margin.value', 390_000_000)
            ->where('rows.1.label', 'اپل')
            ->where('rows.1.margin.value', 20_000_000)
            ->etc()
        );
});

it('reports one row per handset, with the cost it was sold at', function (): void {
    /*
    | The cut only a serialized-inventory product can offer. The margin is exact — this
    | device's own cost against this device's own sale price — not an average and not an
    | allocation, which is why a shopkeeper can use it to decide what to pay for the next
    | trade-in.
    */
    $this->actingAs($this->owner)
        ->get($this->url.'/reporting/profit?cut=imei')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            // Only the handset: the glass and the battery have no unit behind them.
            ->has('rows', 1)
            ->where('rows.0.label', '354977061234563')
            ->where('rows.0.product', 'اپل آیفون ۱۵')
            ->where('rows.0.customer', 'سمیرا احمدی')
            ->where('rows.0.revenue.value', 400_000_000)
            ->where('rows.0.cost.value', 380_000_000)
            ->where('rows.0.margin.value', 20_000_000)
            ->etc()
        );
});

it('agrees with the sales report about the period total', function (): void {
    /*
    | Both screens ask `SalesReports::summary()`. Asserted rather than assumed, because
    | the day somebody "optimises" one of them into its own query is the day the two
    | disagree, and the symptom is a shopkeeper reading two profits for one month.
    */
    $profit = $this->actingAs($this->owner)->get($this->url.'/reporting/profit')->assertOk();
    $sales = $this->actingAs($this->owner)->get($this->url.'/reporting/sales')->assertOk();

    $profitFigure = $profit->viewData('page')['props']['summary']['profit']['value'];
    $salesFigure = $sales->viewData('page')['props']['summary']['profit']['value'];

    // 200,000,000 + 190,000,000 + 20,000,000
    expect($profitFigure)->toBe(410_000_000)->and($salesFigure)->toBe($profitFigure);
});

/* ------------------------------------------------------------- boundary -- */

it('refuses the whole screen to somebody who may not see margin', function (): void {
    /*
    | Not "hides the columns" — refuses. A profit report with the profit removed is an
    | empty table under a heading that promises otherwise, and the Cashier has
    | `reporting.view` precisely so they can read the takings on the sales report.
    */
    $this->actingAs($this->cashier)
        ->get($this->url.'/reporting/profit')
        ->assertForbidden();

    $this->actingAs($this->cashier)
        ->get($this->url.'/reporting/profit/export')
        ->assertForbidden();
});

it('keeps the profit rows off the index for the same person', function (): void {
    /*
    | The index and the screen must agree. A listed row that 403s when clicked is worse
    | than no row: it tells the reader the product is broken rather than that the figure
    | is not theirs to see.
    */
    $this->actingAs($this->cashier)
        ->get($this->url.'/reporting')
        ->assertOk()
        ->assertInertia(function ($page): void {
            /** @var array<int, array{reports: array<int, array{key: string}>}> $groups */
            $groups = $page->toArray()['props']['groups'];

            $keys = [];

            foreach ($groups as $group) {
                foreach ($group['reports'] as $report) {
                    $keys[] = $report['key'];
                }
            }

            expect($keys)->toContain('sales.daily')
                ->and($keys)->not->toContain('profit.by_product')
                ->and($keys)->not->toContain('profit.per_imei');
        });
});

it('lists the profit rows for the owner', function (): void {
    $this->actingAs($this->owner)
        ->get($this->url.'/reporting')
        ->assertOk()
        // Five sales cuts plus three profit cuts, all in «فروش و سود».
        ->assertInertia(fn ($page) => $page->has('groups.0.reports', 8));
});

it('downloads a workbook for somebody who may export', function (): void {
    $response = $this->actingAs($this->owner)->get($this->url.'/reporting/profit/export?cut=imei');

    $response->assertOk();

    expect($response->headers->get('content-disposition'))->toContain('.xlsx');
});

/* ------------------------------------------------------------ isolation -- */

it('reports a shop its own profit and not the shop next door', function (): void {
    $other = Tenant::factory()->withDomain()->create();
    subscribe($other, 'pro');
    app(SubscriptionResolver::class)->forget();
    app(TenantProvisioner::class)->seedRoles($other);

    $neighbour = app(TenantContext::class)->runFor($other, function (): User {
        $user = User::factory()->create();
        $user->assignRole('Owner');

        return $user;
    });

    // 410,000,000 of profit next door, including a handset with a real IMEI on it.
    $this->actingAs($neighbour)
        ->get(appUrl().'/reporting/profit?cut=imei')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('summary.profit.value', 0)
            ->where('rows', [])
            ->etc()
        );
})->group('isolation');
