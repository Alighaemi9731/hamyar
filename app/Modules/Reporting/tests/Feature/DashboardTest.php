<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Cheques\Enums\ChequeDirection;
use App\Modules\Cheques\Models\Cheque;
use App\Modules\CRM\Models\Party;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Enums\MovementType;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockLedger;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Platform\Services\PlanCatalogueSeeder;
use App\Modules\Platform\Services\SubscriptionResolver;
use App\Modules\Platform\Services\TenantProvisioner;
use App\Modules\Repairs\Enums\TicketStatus;
use App\Modules\Repairs\Models\RepairTicket;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The front page, and who is allowed to see what on it.
 *
 * ## The failure these tests exist for is silent
 *
 * Nothing crashes when a dashboard shows a salesperson the shop's margin. The page
 * renders, the number is correct, and the only symptom is that the most commercially
 * sensitive figure in the building is now on a screen that faces the shop floor. So the
 * assertions here are about **absence** — `profit` must not be in the payload — rather
 * than about a status code, because a status code cannot see this.
 *
 * `assertInertia`'s `missing()` is the right assertion for that and `where(..., null)`
 * is not: a key present and null is still a key, and the next person to write
 * `today.profit ?? 0` in a template turns it into a zero on screen.
 *
 * ## Two gates, tested apart
 *
 * A widget needs the plan AND the permission. The Basic-plan test below gives the Owner
 * every permission there is and still expects no cheques card, which is the only way to
 * prove the plan gate is doing anything — an Owner on Pro would pass either way.
 */
beforeEach(function (): void {
    app(PlanCatalogueSeeder::class)->sync();

    $this->tenant = Tenant::factory()->withDomain()->create();
    $this->url = appUrl();

    subscribe($this->tenant, 'pro');
    app(SubscriptionResolver::class)->forget();
    app(TenantProvisioner::class)->seedRoles($this->tenant);

    [$this->owner, $this->seller, $this->technician] = app(TenantContext::class)->runFor($this->tenant, function (): array {
        $owner = User::factory()->create();
        $owner->assignRole('Owner');

        $seller = User::factory()->create();
        $seller->assignRole('Salesperson');

        $technician = User::factory()->create();
        $technician->assignRole('Technician');

        return [$owner, $seller, $technician];
    });
});

afterEach(fn () => app(TenantContext::class)->forget());

/* ------------------------------------------------------------ role-aware -- */

it('gives the owner every widget the plan pays for', function (): void {
    $this->actingAs($this->owner)
        ->get($this->url.'/dashboard')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Reporting::Dashboard/Index')
            ->where('shows_profit', true)
            ->has('today.revenue')
            ->has('today.profit')
            ->has('trend')
            ->has('repairs')
            ->has('cheques')
            ->has('installments')
            ->has('low_stock')
            ->etc()
        );
});

it('shows a salesperson what they sold and never what it cost', function (): void {
    /*
    | Gate 1's boundary. The Salesperson role has `sales.view` but not
    | `sales.view_profit`, so the revenue card stays and the margin card is not merely
    | hidden by the template — it never leaves the server.
    */
    $this->actingAs($this->seller)
        ->get($this->url.'/dashboard')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('shows_profit', false)
            ->has('today.revenue')
            ->missing('today.profit')
            ->missing('today.margin_percent')
            ->missing('trend.0.profit')
            ->etc()
        );
});

it('gives a technician the bench and not the books', function (): void {
    // No `sales.view`, no `cheques.view`, no `installments.view`. Inventory it does have,
    // because a technician looks up parts.
    $this->actingAs($this->technician)
        ->get($this->url.'/dashboard')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('today', null)
            ->where('cheques', null)
            ->where('installments', null)
            ->has('repairs')
            ->has('abandoned')
            ->has('low_stock')
            ->etc()
        );
});

it('withholds a module the plan does not include, even from the owner', function (): void {
    $basic = Tenant::factory()->withDomain()->create();
    subscribe($basic, 'basic');
    app(SubscriptionResolver::class)->forget();
    app(TenantProvisioner::class)->seedRoles($basic);

    $owner = inTenantContext($basic, function (): User {
        $user = User::factory()->create();
        $user->assignRole('Owner');

        return $user;
    });

    // Every permission in the catalogue, and still no cheques card: Basic has no
    // Cheques module to have a card about.
    $this->actingAs($owner)
        ->get(appUrl().'/dashboard')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('cheques', null)
            ->where('installments', null)
            ->where('repairs', null)
            ->has('today')
            ->has('low_stock')
            ->etc()
        );
});

/* ----------------------------------------------------------------- data -- */

it('reports a quiet shop as zeros rather than as nothing', function (): void {
    /*
    | "No cheques due" is an answer. Sending the widget as null would make an empty
    | shop indistinguishable from one the viewer may not see, and the page renders
    | those two states very differently on purpose.
    */
    $this->actingAs($this->owner)
        ->get($this->url.'/dashboard')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('today.revenue.value', 0)
            ->where('repairs.total', 0)
            ->where('cheques.soonest', [])
            ->where('installments.count', 0)
            ->where('low_stock.count', 0)
            ->etc()
        );
});

it('counts the repair queue by status and leaves رسوبی out of it', function (): void {
    inTenantContext($this->tenant, function (): void {
        $party = Party::factory()->create();

        RepairTicket::factory()->count(2)->create(['status' => TicketStatus::Queued, 'party_id' => $party->id]);
        RepairTicket::factory()->create(['status' => TicketStatus::AwaitingParts, 'party_id' => $party->id]);
        RepairTicket::factory()->create([
            'status' => TicketStatus::Abandoned,
            'party_id' => $party->id,
            'abandoned_at' => CarbonImmutable::now()->subDays(40),
        ]);
    });

    $this->actingAs($this->owner)
        ->get($this->url.'/dashboard')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            // Three on the bench. The abandoned device is physically in the shop but
            // nobody is working on it, and counting it as "in progress" would report
            // work that is not happening.
            ->where('repairs.total', 3)
            ->where('abandoned.count', 1)
            ->etc()
        );
});

it('draws a point for every day in the trend, including the closed ones', function (): void {
    // A chart built only from days that had a sale compresses a shut week into one step.
    $this->actingAs($this->owner)
        ->get($this->url.'/dashboard')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('trend', 30)->etc());
});

it('counts a line as low only when somebody set a threshold', function (): void {
    inTenantContext($this->tenant, function (): void {
        $warehouse = Warehouse::factory()->create();

        $watched = Product::factory()->create(['type' => 'standard', 'low_stock_threshold' => 5]);
        $watchedVariant = ProductVariant::factory()->for($watched)->create();

        // Same quantity, no threshold: nobody decided, so it is not "low".
        $ignored = Product::factory()->create(['type' => 'standard', 'low_stock_threshold' => null]);
        $ignoredVariant = ProductVariant::factory()->for($ignored)->create();

        $ledger = app(StockLedger::class);
        $ledger->record($watchedVariant->id, $warehouse->id, 2, MovementType::Purchase);
        $ledger->record($ignoredVariant->id, $warehouse->id, 2, MovementType::Purchase);
    });

    $this->actingAs($this->owner)
        ->get($this->url.'/dashboard')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('low_stock.count', 1)
            ->where('low_stock.lines.0.on_hand', 2)
            ->where('low_stock.lines.0.threshold', 5)
            ->etc()
        );
});

/* ---------------------------------------------------------- performance -- */

it('renders in a bounded number of queries whatever is on the shelves', function (): void {
    /*
    | The N+1 guard, written as a budget rather than an exact count so that adding a
    | widget is not a test failure — but a *bounded* one, so a widget that queries per
    | row is.
    |
    | The seed below is deliberately wide: sixty variants and twenty tickets. If any
    | widget looped, the count would move with the data, and the budget is far below
    | where it would land.
    */
    inTenantContext($this->tenant, function (): void {
        $warehouse = Warehouse::factory()->create();
        $party = Party::factory()->create();
        $ledger = app(StockLedger::class);

        for ($i = 0; $i < 60; $i++) {
            $product = Product::factory()->create(['type' => 'standard', 'low_stock_threshold' => 5]);
            $variant = ProductVariant::factory()->for($product)->create();
            $ledger->record($variant->id, $warehouse->id, 1, MovementType::Purchase);
        }

        RepairTicket::factory()->count(20)->create(['status' => TicketStatus::Repairing, 'party_id' => $party->id]);

        for ($i = 0; $i < 10; $i++) {
            Cheque::query()->create([
                'direction' => ChequeDirection::Received,
                'party_id' => $party->id,
                'amount' => 5_000_000,
                'bank_name' => 'ملت',
                'serial' => (string) (900_000 + $i),
                'due_date' => CarbonImmutable::now()->addDays(3),
            ]);
        }
    });

    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    $this->actingAs($this->owner)->get($this->url.'/dashboard')->assertOk();

    expect($queries)->toBeLessThan(60);
});

/* ------------------------------------------------------------ isolation -- */

it('shows a shop its own figures and none of the shop next door', function (): void {
    $other = Tenant::factory()->withDomain()->create();
    subscribe($other, 'pro');
    app(SubscriptionResolver::class)->forget();
    app(TenantProvisioner::class)->seedRoles($other);

    $neighbour = inTenantContext($other, function (): User {
        $user = User::factory()->create();
        $user->assignRole('Owner');

        return $user;
    });

    inTenantContext($this->tenant, function (): void {
        RepairTicket::factory()->count(4)->create([
            'status' => TicketStatus::Repairing,
            'party_id' => Party::factory()->create()->id,
        ]);
    });

    // Same screen, same role, a different shop: four tickets next door are none here.
    $this->actingAs($neighbour)
        ->get(appUrl().'/dashboard')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('repairs.total', 0)->etc());
})->group('isolation');

it("refuses the neighbour's dashboard outright", function (): void {
    $other = Tenant::factory()->withDomain()->create();
    subscribe($other, 'pro');
    app(SubscriptionResolver::class)->forget();

    /*
    | The sibling above proves this shop's numbers stop at its own door. This proves
    | the door itself: a user of shop A asking for shop B's dashboard is not shown an
    | empty screen, they are turned away — `tenant.user` is what says so.
    |
    | Since ADR 0017 there is no shop B *address* to ask at, so the session says which
    | shop the request claims to be for. Sign in first (which pins the owner's own
    | tenant), forge second — reversed, `actingAs()` would overwrite `tenant_id` with
    | this shop's and the request would legitimately succeed.
    */
    $this->actingAs($this->owner);

    actingForTenant($other)
        ->get(appUrl().'/dashboard')
        ->assertRedirect(appUrl().'/login');
})->group('isolation');
