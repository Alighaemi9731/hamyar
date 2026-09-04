<?php

declare(strict_types=1);

/**
 * The repairs board renders a workshop that spans branches.
 *
 * `board()` builds each card with `row()`, which reads `$ticket->branch->name`, and lazy
 * loading is disabled across the application — so a board query that does not eager-load
 * `branch` throws on the first card. It did, for every shop with tickets in more than one
 * branch, from the day the board was written until 2026-09-04: the demo tenant never had a
 * ticket, no test ever put two branches' tickets on the board, and the first thing that
 * did was the screenshot pipeline photographing a seeded workshop. This is that shop.
 */

use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Platform\Services\PlanCatalogueSeeder;
use App\Modules\Platform\Services\SubscriptionResolver;
use App\Modules\Platform\Services\TenantProvisioner;
use App\Modules\Repairs\Enums\TicketStatus;
use App\Modules\Repairs\Models\RepairTicket;
use App\Support\Tenancy\TenantContext;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    app(PlanCatalogueSeeder::class)->sync();

    $this->tenant = Tenant::factory()->withDomain()->create();
    $this->url = appUrl();

    subscribe($this->tenant, 'pro');
    app(SubscriptionResolver::class)->forget();
    app(TenantProvisioner::class)->seedRoles($this->tenant);

    $fixtures = inTenantContext($this->tenant, function (): array {
        $owner = User::factory()->create();
        $owner->assignRole('Owner');

        // Two warehouses, therefore two branches — the factory makes a branch per warehouse.
        return [$owner, Warehouse::factory()->create(), Warehouse::factory()->create()];
    });

    /** @var array{User, Warehouse, Warehouse} $fixtures */
    [$this->owner, $this->main, $this->second] = $fixtures;
});

afterEach(fn () => app(TenantContext::class)->forget());

it('renders every column with tickets from more than one branch', function (): void {
    inTenantContext($this->tenant, function (): void {
        foreach ([
            [$this->main->branch_id, TicketStatus::Queued],
            [$this->second->branch_id, TicketStatus::Queued],
            [$this->main->branch_id, TicketStatus::Repairing],
            [$this->second->branch_id, TicketStatus::Ready],
        ] as [$branchId, $status]) {
            RepairTicket::factory()->create([
                'branch_id' => $branchId,
                'status' => $status,
                'technician_id' => $this->owner->id,
            ]);
        }
    });

    $this->actingAs($this->owner)
        ->get($this->url.'/repairs/board')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Repairs::Tickets/Board')
            ->where('counts.queued', 2)
            ->where('counts.repairing', 1)
            ->where('counts.ready', 1));
});

it('still renders with nothing on it', function (): void {
    $this->actingAs($this->owner)
        ->get($this->url.'/repairs/board')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Repairs::Tickets/Board'));
});
