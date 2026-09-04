<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\CRM\Models\Party;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Enums\UnitStatus;
use App\Modules\Inventory\Models\ProductUnit;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\UnitStateMachine;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Platform\Services\PlanCatalogueSeeder;
use App\Modules\Platform\Services\SubscriptionResolver;
use App\Modules\Platform\Services\TenantProvisioner;
use App\Modules\Purchasing\Models\PurchaseInvoice;
use App\Support\Tenancy\TenantContext;

/**
 * The IMEI passport — the screen the product is sold on.
 *
 * What is tested here is what the screen promises: the story is complete and in order,
 * every line names the document that caused it, and the purchase cost does not reach a
 * Salesperson.
 */
beforeEach(function (): void {
    app(PlanCatalogueSeeder::class)->sync();

    $this->tenant = Tenant::factory()->withDomain()->create();
    $this->url = appUrl();

    subscribe($this->tenant, 'pro');
    app(SubscriptionResolver::class)->forget();

    app(TenantProvisioner::class)->seedRoles($this->tenant);

    [$this->keeper, $this->seller] = app(TenantContext::class)->runFor($this->tenant, function (): array {
        $keeper = User::factory()->create(['name' => 'انباردار فروشگاه']);
        $keeper->assignRole('Warehousekeeper');

        $seller = User::factory()->create();
        $seller->assignRole('Salesperson');

        return [$keeper, $seller];
    });
});

afterEach(fn () => app(TenantContext::class)->forget());

/**
 * A handset bought on a purchase invoice, with the first line of its passport written.
 *
 * `actorId` is passed explicitly rather than left to `auth()->id()`: the fixture runs
 * outside a request, and a history row with no actor is exactly the gap the passport
 * is supposed to close.
 *
 * @return array{0: ProductUnit, 1: PurchaseInvoice, 2: Party}
 */
function acquiredUnit(
    Tenant $tenant,
    string $productName = 'آیفون ۱۵ پرو مکس',
    ?int $actorId = null,
    ?string $invoiceNumber = null,
): array {
    return app(TenantContext::class)->runFor($tenant, function () use ($productName, $actorId, $invoiceNumber): array {
        $supplier = Party::factory()->supplier()->create([
            'name' => 'پخش قطعات جنوب شرق تهران — شرکت تجارت الکترونیک آریا',
        ]);

        $invoice = PurchaseInvoice::factory()->received()->create([
            'party_id' => $supplier->id,
            // Invoice numbers are unique per shop, so a fixture that hardcodes one can
            // only ever build a single invoice.
            'number' => $invoiceNumber ?? 'PUR-'.fake()->unique()->numerify('1405-####'),
        ]);

        $product = Product::factory()->serialized()->create(['name' => $productName]);
        $variant = ProductVariant::factory()->for($product)->create();

        $unit = ProductUnit::factory()->for($variant, 'variant')->create([
            'acquired_from_party_id' => $supplier->id,
            'cost' => 780_000_000,
        ]);

        app(UnitStateMachine::class)
            ->recordAcquisition($unit, $invoice, 'ورود با فاکتور خرید', $actorId);

        return [$unit, $invoice, $supplier];
    });
}

/* --------------------------------------------------------------- passport -- */

it('tells the whole story, oldest first, with the acquisition marked', function (): void {
    [$unit] = acquiredUnit($this->tenant);

    app(TenantContext::class)->runFor($this->tenant, function () use ($unit): void {
        $machine = app(UnitStateMachine::class);

        $machine->transition($unit, UnitStatus::Reserved, null, 'برای آقای رضایی');
        $machine->transition($unit, UnitStatus::InStock, null, 'مشتری منصرف شد');
        $machine->transition($unit, UnitStatus::InRepair, null, 'بررسی دوربین');
        $machine->transition($unit, UnitStatus::InStock);
    });

    $this->actingAs($this->keeper)
        ->get($this->url.'/inventory/units/'.$unit->id)
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Inventory::Units/Show')
            ->has('timeline', 5)
            // The first line is the acquisition: there is no `from`, because the
            // device did not exist here before.
            ->where('timeline.0.is_acquisition', true)
            ->where('timeline.0.from_status', null)
            ->where('timeline.0.to_status', 'in_stock')
            ->where('timeline.1.from_status', 'in_stock')
            ->where('timeline.1.to_status', 'reserved')
            ->where('timeline.4.to_status', 'in_stock')
        );
});

it('offers the next actions to somebody who may take them, and the brand for the intake handover', function (): void {
    [$unit] = acquiredUnit($this->tenant);

    $owner = app(TenantContext::class)->runFor($this->tenant, function (): User {
        $user = User::factory()->create();
        $user->assignRole('Owner');

        return $user;
    });

    $this->actingAs($owner)
        ->get($this->url.'/inventory/units/'.$unit->id)
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Inventory::Units/Show')
            ->where('can.sell', true)
            ->where('can.repair', true)
            ->where('can.label', true)
            ->has('unit.brand_name')
        );
});

it('withholds a door from somebody who cannot walk through it', function (): void {
    [$unit] = acquiredUnit($this->tenant);

    // A warehousekeeper does not sell: the till link would 403, so it is not offered.
    $this->actingAs($this->keeper)
        ->get($this->url.'/inventory/units/'.$unit->id)
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('can.sell', false));
});

it('names the document that caused each line, without Inventory knowing the class', function (): void {
    [$unit] = acquiredUnit($this->tenant, 'آیفون ۱۵ پرو مکس', $this->keeper->id, 'PUR-1405-0012');

    // The label comes from the resolver Purchasing registered in the shared
    // DocumentRegistry. Inventory never imports PurchaseInvoice (ADR 0003).
    $this->actingAs($this->keeper)
        ->get($this->url.'/inventory/units/'.$unit->id)
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('timeline.0.reference.label', 'فاکتور خرید PUR-1405-0012')
            ->where('timeline.0.actor', 'انباردار فروشگاه')
            ->where('timeline.0.note', 'ورود با فاکتور خرید')
        );
});

it('answers "bought from whom" with the supplier name, however long it is', function (): void {
    [$unit, , $supplier] = acquiredUnit($this->tenant);

    $this->actingAs($this->keeper)
        ->get($this->url.'/inventory/units/'.$unit->id)
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('unit.acquired_from.label', $supplier->name));
});

it('holds up with a long history without losing an entry', function (): void {
    [$unit] = acquiredUnit($this->tenant);

    app(TenantContext::class)->runFor($this->tenant, function () use ($unit): void {
        $machine = app(UnitStateMachine::class);

        // Eight round trips: the device is reserved, released, repaired and returned
        // over and over — a real used-phone life, and the case a timeline has to
        // survive without collapsing into unreadability.
        for ($i = 0; $i < 4; $i++) {
            $machine->transition($unit, UnitStatus::Reserved);
            $machine->transition($unit, UnitStatus::InStock);
            $machine->transition($unit, UnitStatus::InRepair);
            $machine->transition($unit, UnitStatus::InStock);
        }
    });

    $this->actingAs($this->keeper)
        ->get($this->url.'/inventory/units/'.$unit->id)
        ->assertOk()
        // 1 acquisition + 16 transitions. Every one of them is a line; the passport is
        // only as good as its worst gap.
        ->assertInertia(fn ($page) => $page->has('timeline', 17));
});

it('withholds the purchase cost from a Salesperson', function (): void {
    [$unit] = acquiredUnit($this->tenant);

    $this->actingAs($this->seller)
        ->get($this->url.'/inventory/units/'.$unit->id)
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('unit.cost', null)
            ->where('can.view_cost', false)
        );

    $this->actingAs($this->keeper)
        ->get($this->url.'/inventory/units/'.$unit->id)
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('unit.cost.value', 780_000_000));
});

/* --------------------------------------------------------------- register -- */

it('lists serialized units and filters them by status', function (): void {
    [$sold] = acquiredUnit($this->tenant, 'گلکسی S24');
    acquiredUnit($this->tenant, 'ردمی نوت ۱۳');

    app(TenantContext::class)->runFor(
        $this->tenant,
        fn () => app(UnitStateMachine::class)->transition($sold, UnitStatus::Sold)
    );

    $this->actingAs($this->keeper)
        ->get($this->url.'/inventory/units')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Inventory::Units/Index')->has('units.rows', 2));

    $this->actingAs($this->keeper)
        ->get($this->url.'/inventory/units?status=sold')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('units.rows', 1)
            ->where('units.rows.0.product_name', 'گلکسی S24')
        );
});

it('finds a device in the register by an IMEI typed with Persian digits', function (): void {
    [$unit] = acquiredUnit($this->tenant);

    $persian = strtr((string) $unit->imei1, ['0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴',
        '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹']);

    $this->actingAs($this->keeper)
        ->get($this->url.'/inventory/units?q='.urlencode($persian))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('units.rows', 1));
});

it('drops the cost column entirely for staff who may not see it', function (): void {
    acquiredUnit($this->tenant);

    $this->actingAs($this->seller)
        ->get($this->url.'/inventory/units')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('can.view_cost', false)
            ->where('units.rows.0.cost', null)
        );
});

/* -------------------------------------------------------------- isolation -- */

it('does not open another shop passport', function (): void {
    $other = Tenant::factory()->withDomain()->create();

    [$foreign] = acquiredUnit($other);

    $this->actingAs($this->keeper)
        ->get($this->url.'/inventory/units/'.$foreign->id)
        ->assertNotFound();
})->group('isolation');

it('keeps another shop devices out of the register', function (): void {
    $other = Tenant::factory()->withDomain()->create();

    acquiredUnit($other, 'دستگاه فروشگاه دیگر');
    acquiredUnit($this->tenant, 'دستگاه خودمان');

    $this->actingAs($this->keeper)
        ->get($this->url.'/inventory/units')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('units.rows', 1)
            ->where('units.rows.0.product_name', 'دستگاه خودمان')
        );
})->group('isolation');

it('offers only this shop warehouses as filters', function (): void {
    $other = Tenant::factory()->withDomain()->create();

    app(TenantContext::class)->runFor(
        $other,
        fn () => Warehouse::factory()->create(['name' => 'انبار فروشگاه دیگر'])
    );

    $this->actingAs($this->keeper)
        ->get($this->url.'/inventory/units')
        ->assertOk()
        ->assertInertia(function ($page): void {
            /** @var list<array{label: string}> $warehouses */
            $warehouses = $page->toArray()['props']['warehouses'];

            foreach ($warehouses as $warehouse) {
                expect($warehouse['label'])->not->toBe('انبار فروشگاه دیگر');
            }
        });
})->group('isolation');
