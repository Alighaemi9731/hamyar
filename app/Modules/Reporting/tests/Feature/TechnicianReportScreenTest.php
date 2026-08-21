<?php

declare(strict_types=1);

use App\Modules\CRM\Models\Party;
use App\Modules\Identity\Models\User;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Platform\Services\PlanCatalogueSeeder;
use App\Modules\Platform\Services\SubscriptionResolver;
use App\Modules\Platform\Services\TenantProvisioner;
use App\Modules\Repairs\Enums\TicketStatus;
use App\Modules\Repairs\Models\RepairTicket;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;

/**
 * Technician performance, against a bench with known figures.
 *
 * ## The fixture, stated
 *
 * | technician | delivered in range | turnaround | still open |
 * |------------|--------------------|------------|------------|
 * | رضا        | 2                  | 24h, 72h   | 1          |
 * | مینا       | 1                  | 12h        | 0          |
 *
 * So رضا averages 48 hours and leads on volume; مینا is faster and quieter. Both facts
 * matter and neither is derivable from the other, which is why both columns exist.
 *
 * ## What is deliberately outside the range
 *
 * One of رضا's tickets was delivered a year ago and must not appear; one is still open
 * and must appear **only** in the open column. Those two rows are what stop the report
 * being a count of everything with a technician on it.
 */
beforeEach(function (): void {
    app(PlanCatalogueSeeder::class)->sync();

    $this->tenant = Tenant::factory()->withDomain()->create();
    $this->url = appUrl();

    subscribe($this->tenant, 'pro');
    app(SubscriptionResolver::class)->forget();
    app(TenantProvisioner::class)->seedRoles($this->tenant);

    /** @var array{User, User, User, User} $fixtures */
    $fixtures = app(TenantContext::class)->runFor($this->tenant, function (): array {
        $owner = User::factory()->create(['name' => 'مالک']);
        $owner->assignRole('Owner');

        $cashier = User::factory()->create();
        $cashier->assignRole('Cashier');

        $reza = User::factory()->create(['name' => 'رضا']);
        $reza->assignRole('Technician');

        $mina = User::factory()->create(['name' => 'مینا']);
        $mina->assignRole('Technician');

        $party = Party::factory()->create();
        $now = CarbonImmutable::now();

        $deliver = function (User $technician, int $hours, CarbonImmutable $deliveredAt) use ($party): void {
            RepairTicket::factory()->create([
                'party_id' => $party->id,
                'technician_id' => $technician->id,
                'status' => TicketStatus::Delivered,
                'created_at' => $deliveredAt->subHours($hours),
                'delivered_at' => $deliveredAt,
            ]);
        };

        $deliver($reza, 24, $now->subDays(3));
        $deliver($reza, 72, $now->subDays(2));
        $deliver($mina, 12, $now->subDay());

        // A year ago: outside every range this test asks for.
        $deliver($reza, 200, $now->subYear());

        // On the bench right now, and therefore in the open column and nowhere else.
        RepairTicket::factory()->create([
            'party_id' => $party->id,
            'technician_id' => $reza->id,
            'status' => TicketStatus::Repairing,
        ]);

        return [$owner, $cashier, $reza, $mina];
    });

    [$this->owner, $this->cashier, $this->reza, $this->mina] = $fixtures;
});

afterEach(fn () => app(TenantContext::class)->forget());

it('counts what each technician finished and how long it took', function (): void {
    $this->actingAs($this->owner)
        ->get($this->url.'/reporting/technicians?'.benchRange())
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Reporting::Reports/Technicians')
            ->has('rows', 2)
            // Most finished first.
            ->where('rows.0.technician', 'رضا')
            ->where('rows.0.delivered', 2)
            // (24 + 72) / 2. The year-old ticket is outside the range and excluded, which
            // is the whole reason this figure is 48 and not something larger.
            ->where('rows.0.avg_turnaround_hours', 48)
            // The open ticket counts here and nowhere else.
            ->where('rows.0.open', 1)
            ->where('rows.1.technician', 'مینا')
            ->where('rows.1.delivered', 1)
            ->where('rows.1.avg_turnaround_hours', 12)
            ->where('rows.1.open', 0)
            ->etc()
        );
});

it('counts the bench as it is today, not as it was in the range', function (): void {
    /*
    | A range with nothing delivered in it. رضا's open ticket is still open — "open" has
    | no date to be inside — but he has finished nothing, so he has no row at all. The
    | report is of finished work; the open column is context beside it.
    */
    $this->actingAs($this->owner)
        ->get($this->url.'/reporting/technicians?'.http_build_query(['from' => '1404/01/01', 'to' => '1404/01/31']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('rows', [])->etc());
});

it('keeps parts cost from somebody who may not see what the shop paid', function (): void {
    /*
    | The sales report's shape, not the profit report's: a Cashier still gets a usable
    | report — how many jobs were finished and how long they took are operational facts —
    | and the money column is absent rather than blank.
    */
    $this->actingAs($this->cashier)
        ->get($this->url.'/reporting/technicians?'.benchRange())
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('shows_cost', false)
            ->where('rows.0.delivered', 2)
            ->missing('rows.0.parts_cost')
            ->etc()
        );

    $this->actingAs($this->cashier)
        ->get($this->url.'/reporting/technicians/export')
        ->assertForbidden();
});

it('refuses the report to somebody with no reporting permission', function (): void {
    $nobody = app(TenantContext::class)->runFor($this->tenant, function (): User {
        $user = User::factory()->create();
        $user->assignRole('Salesperson');

        return $user;
    });

    // Salesperson holds no `reporting.view`.
    $this->actingAs($nobody)
        ->get($this->url.'/reporting/technicians')
        ->assertForbidden();
});

it('downloads a workbook for somebody who may export', function (): void {
    $response = $this->actingAs($this->owner)->get($this->url.'/reporting/technicians?'.benchRange());

    $response->assertOk();

    $download = $this->actingAs($this->owner)->get($this->url.'/reporting/technicians/export?'.benchRange());

    $download->assertOk();

    expect($download->headers->get('content-disposition'))->toContain('.xlsx');
});

it('lists the report on the index under تعمیرات', function (): void {
    $this->actingAs($this->owner)
        ->get($this->url.'/reporting')
        ->assertOk()
        ->assertInertia(function ($page): void {
            /** @var array<int, array{key: string, reports: array<int, array{key: string}>}> $groups */
            $groups = $page->toArray()['props']['groups'];

            $keys = [];

            foreach ($groups as $group) {
                if ($group['key'] !== 'repairs') {
                    continue;
                }

                foreach ($group['reports'] as $report) {
                    $keys[] = $report['key'];
                }
            }

            expect($keys)->toBe(['repairs.technicians']);
        });
});

/* ------------------------------------------------------------ isolation -- */

it('reports a shop its own bench and not the shop next door', function (): void {
    $other = Tenant::factory()->withDomain()->create();
    subscribe($other, 'pro');
    app(SubscriptionResolver::class)->forget();
    app(TenantProvisioner::class)->seedRoles($other);

    $neighbour = app(TenantContext::class)->runFor($other, function (): User {
        $user = User::factory()->create();
        $user->assignRole('Owner');

        return $user;
    });

    $this->actingAs($neighbour)
        ->get(appUrl().'/reporting/technicians?'.benchRange())
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('rows', [])->etc());
})->group('isolation');

/**
 * A Jalali range wide enough to hold the fixture's three recent deliveries and narrow
 * enough to exclude the year-old one.
 */
function benchRange(): string
{
    return http_build_query([
        'from' => App\Support\Jalali::format(CarbonImmutable::now()->subDays(10), 'Y/m/d', persianDigits: false),
        'to' => App\Support\Jalali::format(CarbonImmutable::now()->addDay(), 'Y/m/d', persianDigits: false),
    ]);
}
