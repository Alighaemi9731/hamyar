<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Enums\MovementType;
use App\Modules\Inventory\Models\ProductUnit;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockLedger;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Platform\Services\PlanCatalogueSeeder;
use App\Modules\Platform\Services\SubscriptionResolver;
use App\Modules\Platform\Services\TenantProvisioner;
use App\Support\Jalali;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;

/**
 * Stock valuation and dead stock, against a shelf with known figures.
 *
 * ## The fixture is built around the mistake this report exists to avoid
 *
 * A mobile shop keeps stock in two places, on purpose: standard goods are a SUM over
 * `stock_movements`, and handsets are rows in `product_units` with **no** movement written
 * for them (Phase 3.6 — a phone counted in both registers is counted twice).
 *
 * So a valuation that reads movements alone values the phones at **zero**, which is most of
 * what this shop owns. The fixture makes that failure loud:
 *
 * | what             | where          | qty | cost each     | value         |
 * |------------------|----------------|-----|---------------|---------------|
 * | گلس (standard)   | movements      | 20  |  10,000,000   | 200,000,000   |
 * | باتری (standard) | movements      | 10  |  56,666,670   | 566,666,700   |
 * | آیفون (device)   | product_units  |   2 | 380,000,000   | 760,000,000   |
 *
 * Total 1,526,666,700, of which 760,000,000 — **half** — is invisible to a movements-only
 * query. The total is asserted, and so is the split.
 *
 * ## The weighted average is not a mean of prices
 *
 * The battery is bought twice at different prices, 10 at 50,000,000 and 2 at 90,000,000.
 * The average is 56,666,670 (680,000,000 ÷ 12, raised to a whole toman), not 70,000,000.
 * A naive mean of the two price tags gives the second, and the difference is 160,000,000
 * on a twelve-unit line.
 */
beforeEach(function (): void {
    app(PlanCatalogueSeeder::class)->sync();

    $this->tenant = Tenant::factory()->withDomain()->create();
    $this->url = appUrl();

    subscribe($this->tenant, 'pro');
    app(SubscriptionResolver::class)->forget();
    app(TenantProvisioner::class)->seedRoles($this->tenant);

    /** @var array{User, User, User} $fixtures */
    $fixtures = app(TenantContext::class)->runFor($this->tenant, function (): array {
        $owner = User::factory()->create(['name' => 'مالک']);
        $owner->assignRole('Owner');

        $keeper = User::factory()->create(['name' => 'انباردار']);
        $keeper->assignRole('Warehousekeeper');

        $seller = User::factory()->create();
        $seller->assignRole('Salesperson');

        $warehouse = Warehouse::factory()->create(['is_sellable' => true, 'is_default' => true]);
        $ledger = app(StockLedger::class);

        $glass = ProductVariant::factory()
            ->for(Product::factory()->create(['name' => 'گلس', 'type' => 'standard']))
            ->create();

        $battery = ProductVariant::factory()
            ->for(Product::factory()->create(['name' => 'باتری', 'type' => 'standard']))
            ->create();

        // 20 glass at 10,000,000 — bought long ago and nothing has left since, so it is
        // the dead-stock row.
        $ledger->record($glass->id, $warehouse->id, 20, MovementType::Purchase, unitCost: 10_000_000,
            occurredAt: CarbonImmutable::now()->subDays(200));

        // The battery is bought twice at different prices and sold from recently, so it
        // is alive: 12 in, 2 out, 10 on hand.
        $ledger->record($battery->id, $warehouse->id, 10, MovementType::Purchase, unitCost: 50_000_000,
            occurredAt: CarbonImmutable::now()->subDays(120));
        $ledger->record($battery->id, $warehouse->id, 2, MovementType::Purchase, unitCost: 90_000_000,
            occurredAt: CarbonImmutable::now()->subDays(60));
        $ledger->record($battery->id, $warehouse->id, -2, MovementType::Sale,
            occurredAt: CarbonImmutable::now()->subDays(5));

        $phone = ProductVariant::factory()
            ->for(Product::factory()->create(['name' => 'آیفون ۱۵', 'type' => 'serialized']))
            ->create();

        foreach (['354977061234563', '354977061234571'] as $index => $imei) {
            ProductUnit::query()->create([
                'product_variant_id' => $phone->id,
                'warehouse_id' => $warehouse->id,
                'imei1' => $imei,
                'status' => 'in_stock',
                'condition' => 'new',
                'cost' => 380_000_000,
                // One arrived long ago (dead), one this week (alive).
                'acquired_at' => CarbonImmutable::now()->subDays($index === 0 ? 200 : 3),
            ]);
        }

        return [$owner, $keeper, $seller];
    });

    [$this->owner, $this->keeper, $this->seller] = $fixtures;
});

afterEach(fn () => app(TenantContext::class)->forget());

/* ----------------------------------------------------------- valuation -- */

it('values both registers, not just the one with movements', function (): void {
    /*
    | The assertion this file exists for. Devices are 760,000,000 of the 1,526,666,700
    | total; a movements-only valuation would report 766,666,700 and look entirely
    | plausible while missing half of what the shop owns.
    */
    $this->actingAs($this->owner)
        ->get($this->url.'/reporting/inventory?cut=valuation')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Reporting::Reports/Inventory')
            ->where('totals.devices', 2)
            ->where('totals.device_value.value', 760_000_000)
            // 20 × 10,000,000 glass + 10 × 56,666,670 battery + 760,000,000 devices.
            ->where('totals.value.value', 200_000_000 + 566_666_700 + 760_000_000)
            ->where('totals.items', 30)
            ->etc()
        );
});

it('values standard goods at the quantity-weighted average, not a mean of prices', function (): void {
    /*
    | 10 at 50,000,000 plus 2 at 90,000,000 is 680,000,000 over 12 units = 56,666,666.67,
    | truncated to 56,666,666 rial and then raised to the whole toman it has to be to be
    | renderable at all: 56,666,670. A mean of the two price tags would say 70,000,000 —
    | a 133,333,300 error on ten units, in the direction that overstates what the shop
    | is worth.
    |
    | The sale of 2 does NOT move it: selling stock does not change what the remaining
    | stock cost.
    */
    $this->actingAs($this->owner)
        ->get($this->url.'/reporting/inventory?cut=valuation')
        ->assertOk()
        ->assertInertia(function ($page): void {
            /** @var array<int, array{label: string, quantity: int, unit_cost: array{value: int}, value: array{value: int}}> $rows */
            $rows = $page->toArray()['props']['rows'];

            $battery = [];

            foreach ($rows as $row) {
                if ($row['label'] === 'باتری') {
                    $battery = $row;
                }
            }

            expect($battery)->not->toBe([]);
            expect($battery['quantity'] ?? 0)->toBe(10)
                ->and($battery['unit_cost']['value'] ?? 0)->toBe(56_666_670)
                ->and($battery['value']['value'] ?? 0)->toBe(566_666_700);
        });
});

it('values the shelf as it was on a past date', function (): void {
    /*
    | The question an accountant asks at year end, and the one a stored total could never
    | answer. Ninety days ago the second battery purchase had not happened and neither had
    | the recent handset, so the figure is smaller and made of different parts.
    */
    $asOf = Jalali::format(CarbonImmutable::now()->subDays(90), 'Y/m/d', persianDigits: false);

    $this->actingAs($this->owner)
        ->get($this->url.'/reporting/inventory?'.http_build_query(['cut' => 'valuation', 'as_of' => $asOf]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            // Only the handset that arrived 200 days ago.
            ->where('totals.devices', 1)
            ->where('totals.device_value.value', 380_000_000)
            ->etc()
        );
});

/* ---------------------------------------------------------- dead stock -- */

it('lists what has not left the shelf, dated from the last time something did', function (): void {
    $this->actingAs($this->owner)
        ->get($this->url.'/reporting/inventory?'.http_build_query(['cut' => 'dead', 'days' => 90]))
        ->assertOk()
        ->assertInertia(function ($page): void {
            /** @var array<int, array{label: string, kind: string, quantity: int}> $rows */
            $rows = $page->toArray()['props']['rows'];

            $labels = array_map(static fn (array $row): string => $row['label'], $rows);

            // Glass: bought 200 days ago, never sold. The old handset: in stock 200 days.
            expect($labels)->toContain('گلس');
            expect(implode(' ', $labels))->toContain('354977061234563');

            // The battery sold five days ago, so it is alive however long it has been
            // sitting — dead stock is about the last time something LEFT.
            expect($labels)->not->toContain('باتری');

            // The handset that arrived three days ago is not dead either.
            expect(implode(' ', $labels))->not->toContain('354977061234571');
        });
});

it('narrows as the threshold grows', function (): void {
    // At 365 days nothing qualifies: the oldest thing on the shelf is 200 days old.
    $this->actingAs($this->owner)
        ->get($this->url.'/reporting/inventory?'.http_build_query(['cut' => 'dead', 'days' => 365]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('rows', [])->etc());
});

it('falls back to ninety days rather than refusing a stale bookmark', function (): void {
    $this->actingAs($this->owner)
        ->get($this->url.'/reporting/inventory?'.http_build_query(['cut' => 'dead', 'days' => 45]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('days', 90)->etc());
});

/* ------------------------------------------------------------- boundary -- */

it('lets the warehouse keeper price a stocktake', function (): void {
    /*
    | `inventory.view_cost` is the permission a Warehousekeeper holds precisely so they
    | can value what they count. Asking only for `reporting.view_financial` here would
    | hand the stocktake to the accountant and leave the person doing it guessing.
    */
    $this->actingAs($this->keeper)
        ->get($this->url.'/reporting/inventory')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('totals.devices', 2)->etc());
});

it('refuses the valuation to somebody who may not see cost', function (): void {
    // Salesperson: no `reporting.view` at all, and Gate 1 keeps them blind to cost.
    $this->actingAs($this->seller)
        ->get($this->url.'/reporting/inventory')
        ->assertForbidden();
});

it('lists both cuts on the index for the owner and neither for a seller', function (): void {
    $this->actingAs($this->owner)
        ->get($this->url.'/reporting')
        ->assertOk()
        ->assertInertia(function ($page): void {
            expect(reportKeys($page, 'inventory'))->toBe(['inventory.valuation', 'inventory.dead_stock']);
        });

    $this->actingAs($this->seller)
        ->get($this->url.'/reporting')
        ->assertOk()
        ->assertInertia(function ($page): void {
            expect(reportKeys($page, 'inventory'))->toBe([]);
        });
});

it('downloads a workbook for somebody who may export', function (): void {
    $response = $this->actingAs($this->owner)->get($this->url.'/reporting/inventory/export?cut=dead');

    $response->assertOk();

    expect($response->headers->get('content-disposition'))->toContain('.xlsx');
});

/* ------------------------------------------------------------ isolation -- */

it('values a shop its own shelf and not the shop next door', function (): void {
    $other = Tenant::factory()->withDomain()->create();
    subscribe($other, 'pro');
    app(SubscriptionResolver::class)->forget();
    app(TenantProvisioner::class)->seedRoles($other);

    $neighbour = app(TenantContext::class)->runFor($other, function (): User {
        $user = User::factory()->create();
        $user->assignRole('Owner');

        return $user;
    });

    // Over a billion rial of stock next door, including two handsets.
    $this->actingAs($neighbour)
        ->get(appUrl().'/reporting/inventory')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('totals.value.value', 0)
            ->where('totals.devices', 0)
            ->where('rows', [])
            ->etc()
        );
})->group('isolation');
