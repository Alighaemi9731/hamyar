<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Brand;
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
use App\Support\Jalali;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;

/**
 * The report index and the sales report viewer, against a month with known figures.
 *
 * ## Why this file seeds its own sales instead of using the crazy month
 *
 * `CrazyMonthSeeder` — the Phase 7 reconciliation scenario the golden numbers are pinned
 * against — contains **no sales invoices at all**. It seeds a chart of accounts, banking,
 * overheads and cheques, because what it was built to prove is that the ledger closes.
 *
 * That matters here because a sales report run against it returns zero rows, and a test
 * that asserts "the rows sum to the summary" then compares nothing to nothing and passes.
 * Two assertions in `GoldenNumbersTest` were doing exactly that; their docblocks now say
 * so, and the arithmetic they appeared to cover is pinned below instead.
 *
 * So the fixture here is small, deliberate and stated in round numbers:
 *
 * | who    | what        | qty | price each   | cost each   |
 * |--------|-------------|-----|--------------|-------------|
 * | owner  | باتری       |  2  | 100,000,000  | 60,000,000  |
 * | seller | گلس         |  3  |  30,000,000  | 20,000,000  |
 *
 * Revenue 290,000,000 · cost 180,000,000 · profit 110,000,000, in rial, no VAT. Every
 * figure asserted below is one of those or a sum of them, which is what makes a failure
 * here readable rather than a puzzle.
 */
beforeEach(function (): void {
    app(PlanCatalogueSeeder::class)->sync();

    $this->tenant = Tenant::factory()->withDomain()->create();
    $this->url = tenantUrl($this->tenant);

    subscribe($this->tenant, 'pro');
    app(SubscriptionResolver::class)->forget();
    app(TenantProvisioner::class)->seedRoles($this->tenant);

    /** @var array{User, User, User, Warehouse, ProductVariant, ProductVariant, Party} $fixtures */
    $fixtures = app(TenantContext::class)->runFor($this->tenant, function (): array {
        $owner = User::factory()->create(['name' => 'مالک']);
        $owner->assignRole('Owner');

        $seller = User::factory()->create(['name' => 'فروشنده']);
        $seller->assignRole('Salesperson');

        $cashier = User::factory()->create();
        $cashier->assignRole('Cashier');

        $warehouse = Warehouse::factory()->create(['is_sellable' => true, 'is_default' => true]);

        Account::factory()->create(['type' => Account::TYPE_CASH, 'is_default' => true]);
        Account::factory()->create(['type' => Account::TYPE_SALES]);

        // Named brands, not the factory's random ones: the brand cut asserts these
        // strings, and «سامسونگ ۴۱۷» would make the test read like a puzzle.
        $apple = Brand::factory()->create(['name' => 'Apple', 'name_fa' => 'اپل']);
        $samsung = Brand::factory()->create(['name' => 'Samsung', 'name_fa' => 'سامسونگ']);

        $battery = ProductVariant::factory()
            ->for(Product::factory()->create(['name' => 'باتری', 'type' => 'standard', 'brand_id' => $apple->id]))
            ->create();

        $glass = ProductVariant::factory()
            ->for(Product::factory()->create(['name' => 'گلس', 'type' => 'standard', 'brand_id' => $samsung->id]))
            ->create();

        $ledger = app(StockLedger::class);
        $ledger->record($battery->id, $warehouse->id, 50, MovementType::Purchase, unitCost: 60_000_000);
        $ledger->record($glass->id, $warehouse->id, 50, MovementType::Purchase, unitCost: 20_000_000);

        return [$owner, $seller, $cashier, $warehouse, $battery, $glass, Party::factory()->create()];
    });

    [$this->owner, $this->seller, $this->cashier, $this->warehouse, $this->battery, $this->glass, $this->party] = $fixtures;

    sellLine($this->battery->id, quantity: 2, price: 100_000_000, salespersonId: $this->owner->id);
    sellLine($this->glass->id, quantity: 3, price: 30_000_000, salespersonId: $this->seller->id);
});

afterEach(fn () => app(TenantContext::class)->forget());

/**
 * One finalised invoice, through the real POS endpoint.
 *
 * On credit rather than paid in cash: a payment would have to match a rounded total
 * exactly, and predicting the rounding is not what this file is testing.
 */
function sellLine(int $variantId, int $quantity, int $price, int $salespersonId): void
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
        'salesperson_id' => $salespersonId,
        'unit' => 'rial',
        'action' => 'finalise',
        'vat_applied' => false,
        'discount_amount' => 0,
        'shipping_amount' => 0,
        'notes' => null,
        'lines' => [
            ['unit_id' => null, 'variant_id' => $variantId, 'quantity' => $quantity, 'unit_price' => $price, 'discount_amount' => 0],
        ],
        'payments' => [],
    ])->assertSessionHasNoErrors()->assertRedirect();
}

/* ---------------------------------------------------------------- index -- */

it('lists only the reports this user may open', function (): void {
    $this->actingAs($this->owner)
        ->get($this->url.'/reporting')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Reporting::Reports/Index')
            ->has('groups.0.reports', 5)
            ->where('groups.0.key', 'sales')
        );
});

it('shows a warehouse keeper the index and a technician nothing at all', function (): void {
    /** @var array{User, User} $users */
    $users = app(TenantContext::class)->runFor($this->tenant, function (): array {
        $keeper = User::factory()->create();
        $keeper->assignRole('Warehousekeeper');

        $technician = User::factory()->create();
        $technician->assignRole('Technician');

        return [$keeper, $technician];
    });

    [$keeper, $technician] = $users;

    // Warehousekeeper holds `reporting.view`; Technician does not, and gets an index
    // that says so rather than a 403 — the module IS on their plan.
    $this->actingAs($keeper)
        ->get($this->url.'/reporting')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('groups.0.reports', 5));

    $this->actingAs($technician)
        ->get($this->url.'/reporting')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('groups', []));
});

/* --------------------------------------------------------- the arithmetic -- */

it('reports the month the shop actually had', function (): void {
    // Defaults to the current Jalali month, which is where the fixture's sales are.
    $this->actingAs($this->owner)
        ->get($this->url.'/reporting/sales')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Reporting::Reports/Sales')
            ->where('cut', 'daily')
            ->where('summary.revenue.value', 290_000_000)
            ->where('summary.cost.value', 180_000_000)
            ->where('summary.profit.value', 110_000_000)
            ->where('summary.invoice_count', 2)
            ->where('summary.returned_revenue.value', 0)
            // One trading day, two invoices on it.
            ->has('rows', 1)
            ->where('rows.0.count', 2)
            ->where('rows.0.revenue.value', 290_000_000)
            ->etc()
        );
});

it('cuts the same month by product, biggest first', function (): void {
    $this->actingAs($this->owner)
        ->get($this->url.'/reporting/sales?cut=product')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('rows', 2)
            // Two batteries out-sell three glasses; the ordering is the report's job.
            ->where('rows.0.label', 'باتری')
            ->where('rows.0.count', 2)
            ->where('rows.0.revenue.value', 200_000_000)
            ->where('rows.0.margin.value', 80_000_000)
            ->where('rows.1.label', 'گلس')
            ->where('rows.1.count', 3)
            ->where('rows.1.revenue.value', 90_000_000)
            ->where('rows.1.margin.value', 30_000_000)
            ->etc()
        );
});

it('cuts the same month by brand', function (): void {
    $this->actingAs($this->owner)
        ->get($this->url.'/reporting/sales?cut=brand')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('rows', 2)
            // The Persian name, not the Latin one — this is a Persian report.
            ->where('rows.0.label', 'اپل')
            ->where('rows.0.revenue.value', 200_000_000)
            ->where('rows.1.label', 'سامسونگ')
            ->where('rows.1.revenue.value', 90_000_000)
            ->etc()
        );
});

it('rolls the month up into a single Jalali month row', function (): void {
    /*
    | Both sales happened today, so a year-wide range must fold to exactly one row —
    | and it must be named for the *Jalali* month. `date_trunc('month', …)` in Postgres
    | would answer with the Gregorian one, which straddles two Jalali months and is
    | therefore an answer to a question nobody asked.
    */
    $thisMonth = Jalali::format(now(), 'F Y');

    $this->actingAs($this->owner)
        ->get($this->url.'/reporting/sales?'.http_build_query([
            'cut' => 'monthly',
            'from' => Jalali::format(now()->subMonths(6), 'Y/m/d', persianDigits: false),
            'to' => Jalali::format(now(), 'Y/m/d', persianDigits: false),
        ]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('cut', 'monthly')
            ->has('rows', 1)
            ->where('rows.0.label', $thisMonth)
            // Invoices, not items: a month row counts baskets.
            ->where('rows.0.count', 2)
            ->where('rows.0.revenue.value', 290_000_000)
            ->where('rows.0.margin.value', 110_000_000)
            ->etc()
        );
});

it('buckets a sale by the shop day, not the server day', function (): void {
    /*
    | 00:30 Tehran is 21:00 UTC the day before. Grouping on `date(issued_at)` would file
    | this sale under yesterday — and eleven times a year, under last month. A phone shop
    | that stays open late is not a hypothetical, and neither is the owner who compares
    | the daily report against the till.
    |
    | The invoice's timestamp is moved directly rather than sold at that hour: what is
    | under test is the report's bucketing, not the POS clock.
    */
    $tehranMidnightish = CarbonImmutable::now('Asia/Tehran')->startOfDay()->addMinutes(30);

    app(TenantContext::class)->runFor($this->tenant, function () use ($tehranMidnightish): void {
        SalesInvoice::query()->latest('id')->limit(1)->update([
            'issued_at' => $tehranMidnightish->utc(),
        ]);
    });

    $today = Jalali::format($tehranMidnightish);

    $this->actingAs($this->owner)
        ->get($this->url.'/reporting/sales?cut=daily')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            // Still one row, still today: the 00:30 sale did not fall off the back.
            ->has('rows', 1)
            ->where('rows.0.label', $today)
            ->where('rows.0.count', 2)
            ->etc()
        );
});

it('cuts the same month by salesperson', function (): void {
    $this->actingAs($this->owner)
        ->get($this->url.'/reporting/sales?cut=salesperson')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('rows', 2)
            ->where('rows.0.label', 'مالک')
            ->where('rows.0.revenue.value', 200_000_000)
            ->where('rows.1.label', 'فروشنده')
            ->where('rows.1.revenue.value', 90_000_000)
            ->etc()
        );
});

it('sums every cut to the same revenue', function (): void {
    /*
    | The invariant a grouping bug breaks while every individual row still looks
    | plausible: a join that fans out duplicates revenue, and a GROUP BY that drops a
    | null dimension loses it. Neither is visible one row at a time.
    */
    foreach (['daily', 'monthly', 'product', 'brand', 'salesperson'] as $cut) {
        $response = $this->actingAs($this->owner)
            ->get($this->url.'/reporting/sales?cut='.$cut)
            ->assertOk();

        $response->assertInertia(function ($page) use ($cut): void {
            /** @var array<int, array{revenue: array{value: int}}> $rows */
            $rows = $page->toArray()['props']['rows'];

            $total = 0;

            foreach ($rows as $row) {
                $total += $row['revenue']['value'];
            }

            expect($rows)->not->toBeEmpty("the {$cut} cut returned no rows")
                ->and($total)->toBe(290_000_000);
        });
    }
});

/* ------------------------------------------------------------ the range -- */

it('runs the report over the Jalali range the URL asked for', function (): void {
    // A month with nothing in it. The report must say so rather than quietly widening.
    $this->actingAs($this->owner)
        ->get($this->url.'/reporting/sales?'.http_build_query(['from' => '1404/01/01', 'to' => '1404/01/31']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('summary.revenue.value', 0)
            ->where('rows', [])
            ->where('period.from_jalali', '۱۴۰۴/۰۱/۰۱')
            ->etc()
        );
});

it('keeps the range when the cut changes', function (): void {
    $query = ['from' => '1404/01/01', 'to' => '1404/01/31'];

    // Same period, different grouping. A cut that reset the range would make comparing
    // two views of one month a retyping exercise.
    $this->actingAs($this->owner)
        ->get($this->url.'/reporting/sales?'.http_build_query([...$query, 'cut' => 'product']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('cut', 'product')
            ->where('period.from_jalali', '۱۴۰۴/۰۱/۰۱')
            ->where('period.to_jalali', '۱۴۰۴/۰۱/۳۱')
            ->etc()
        );
});

it('falls back to a known cut rather than refusing a stale bookmark', function (): void {
    $this->actingAs($this->owner)
        ->get($this->url.'/reporting/sales?cut=by-phase-of-moon')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('cut', 'daily')->etc());
});

/* ------------------------------------------------------------- boundary -- */

it('never sends cost to somebody who may not see it, by either door', function (): void {
    /*
    | A Cashier has `reporting.view` and neither `reporting.view_financial` nor
    | `sales.view_profit`. They may read the shop's takings — they take them — but not
    | what the shop paid. Asserted as absence, not as zero: a key present and null is
    | still a key, and the next `?? 0` in a template turns it into a figure on screen.
    */
    $this->actingAs($this->cashier)
        ->get($this->url.'/reporting/sales')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('shows_margin', false)
            ->where('summary.revenue.value', 290_000_000)
            ->missing('summary.cost')
            ->missing('summary.profit')
            ->missing('rows.0.cost')
            ->missing('rows.0.margin')
            ->etc()
        );

    // Door two: the spreadsheet. `reporting.export` is not on the Cashier role either.
    $this->actingAs($this->cashier)
        ->get($this->url.'/reporting/sales/export')
        ->assertForbidden();
});

it('refuses the report to somebody with no reporting permission', function (): void {
    $technician = app(TenantContext::class)->runFor($this->tenant, function (): User {
        $user = User::factory()->create();
        $user->assignRole('Technician');

        return $user;
    });

    $this->actingAs($technician)
        ->get($this->url.'/reporting/sales')
        ->assertForbidden();
});

it('downloads a workbook for somebody who may export', function (): void {
    $response = $this->actingAs($this->owner)->get($this->url.'/reporting/sales/export');

    $response->assertOk();

    expect($response->headers->get('content-disposition'))->toContain('.xlsx');
});

/* ------------------------------------------------------------ isolation -- */

it('reports a shop its own sales and not the shop next door', function (): void {
    $other = Tenant::factory()->withDomain()->create();
    subscribe($other, 'pro');
    app(SubscriptionResolver::class)->forget();
    app(TenantProvisioner::class)->seedRoles($other);

    $neighbour = app(TenantContext::class)->runFor($other, function (): User {
        $user = User::factory()->create();
        $user->assignRole('Owner');

        return $user;
    });

    // 290,000,000 rial of trading happened next door. This shop sold nothing, and the
    // report says nothing rather than reporting somebody else's takings.
    $this->actingAs($neighbour)
        ->get(tenantUrl($other).'/reporting/sales')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('summary.revenue.value', 0)
            ->where('rows', [])
            ->etc()
        );
})->group('isolation');
