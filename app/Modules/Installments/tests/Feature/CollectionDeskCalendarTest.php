<?php

declare(strict_types=1);

use App\Modules\CRM\Models\Party;
use App\Modules\Identity\Models\User;
use App\Modules\Installments\Models\InstallmentPlan;
use App\Modules\Installments\Models\InstallmentRow;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Platform\Services\PlanCatalogueSeeder;
use App\Modules\Platform\Services\SubscriptionResolver;
use App\Modules\Platform\Services\TenantProvisioner;
use App\Support\Jalali;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;

/**
 * "Overdue" and "days late" on the collection desk and the dashboard card, on the shop's
 * calendar.
 *
 * A real schedule stores each row as the instant its Tehran day begins —
 * `Jalali::startOfDay()` — which is 20:30 UTC the evening before. Both screens asked their
 * questions of the UTC date of that value, so from 03:30 Tehran on the day an instalment
 * fell due it was already on the late list, one day late, with a day's fee beside it.
 *
 * Every instant below is a Tehran day of 1405/06/22 (2026-09-13) or the day after, read at
 * two hours: just after Tehran midnight, when the UTC date is still the day before, and
 * mid-afternoon, when both clocks agree.
 */
beforeEach(function (): void {
    app(PlanCatalogueSeeder::class)->sync();

    $this->tenant = Tenant::factory()->withDomain()->create();
    $this->url = appUrl();

    subscribe($this->tenant, 'pro');
    app(SubscriptionResolver::class)->forget();
    app(TenantProvisioner::class)->seedRoles($this->tenant);

    /** @var array{User, Party, Warehouse} $fixtures */
    $fixtures = inTenantContext($this->tenant, function (): array {
        $owner = User::factory()->create();
        $owner->assignRole('Owner');

        return [$owner, Party::factory()->create(['name' => 'حسن رضایی']), Warehouse::factory()->create()];
    });

    [$this->owner, $this->customer, $this->warehouse] = $fixtures;
});

afterEach(fn () => app(TenantContext::class)->forget());

/**
 * A one-row plan due on a Jalali day, stored the way the plan wizard stores it.
 */
function planWithOneRowDueOn(Party $customer, Warehouse $warehouse, string $jalaliDay, string $number): InstallmentRow
{
    /** @var InstallmentPlan $plan */
    $plan = InstallmentPlan::query()->create([
        'branch_id' => $warehouse->branch_id,
        'party_id' => $customer->id,
        'number' => $number,
        'down_payment' => 0,
        'principal' => 12_000_000,
        'profit_percent' => 0,
        'profit_amount' => 0,
        'total_payable' => 12_000_000,
        'installment_count' => 1,
        'interval_months' => 1,
        'first_due_at' => Jalali::startOfDay($jalaliDay),
        'status' => 'active',
    ]);

    /** @var InstallmentRow $row */
    $row = InstallmentRow::query()->create([
        'installment_plan_id' => $plan->id,
        'sequence' => 1,
        'due_at' => Jalali::startOfDay($jalaliDay),
        'amount' => 12_000_000,
        'status' => InstallmentRow::STATUS_PENDING,
    ]);

    return $row;
}

/**
 * @return array{overdue: list<array{plan_number: string, days_late: int}>, due: list<array{plan_number: string, days_late: int}>}
 */
function collectionDeskAt(string $now): array
{
    test()->travelTo(CarbonImmutable::parse($now));

    /** @var array{overdue: list<array{plan_number: string, days_late: int}>, due: list<array{plan_number: string, days_late: int}>} $desk */
    $desk = ['overdue' => [], 'due' => []];

    test()->actingAs(test()->owner)
        ->get(appUrl('/installments/collections'))
        ->assertOk()
        ->assertInertia(function ($page) use (&$desk): void {
            $props = propsOf($page);

            /** @var array{overdue: list<array{plan_number: string, days_late: int}>, due: list<array{plan_number: string, days_late: int}>} $desk */
            $desk = ['overdue' => $props['overdue'], 'due' => $props['due']];
        });

    return $desk;
}

it('keeps an instalment off the late list on the day it falls due', function (string $now): void {
    inTenantContext($this->tenant, fn () => planWithOneRowDueOn($this->customer, $this->warehouse, '1405/06/22', 'INS-DUE-TODAY'));

    $desk = collectionDeskAt($now);

    expect($desk['overdue'])->toBe([])
        ->and(array_column($desk['due'], 'plan_number'))->toBe(['INS-DUE-TODAY'])
        ->and($desk['due'][0]['days_late'])->toBe(0);
})->with([
    'just after Tehran midnight' => '2026-09-12 21:00:00',
    // The old desk put the row on the late list here, one day late.
    'mid-afternoon' => '2026-09-13 10:00:00',
]);

it('counts an instalment one day late on the day after', function (string $now): void {
    inTenantContext($this->tenant, fn () => planWithOneRowDueOn($this->customer, $this->warehouse, '1405/06/22', 'INS-ONE-DAY'));

    $desk = collectionDeskAt($now);

    expect(array_column($desk['overdue'], 'plan_number'))->toBe(['INS-ONE-DAY'])
        ->and($desk['overdue'][0]['days_late'])->toBe(1);
})->with([
    'just after Tehran midnight' => '2026-09-13 21:00:00',
    // The old desk said two days here.
    'mid-afternoon' => '2026-09-14 10:00:00',
]);

it('counts the dashboard card on the same calendar as the desk', function (string $now, int $count, ?int $daysLate): void {
    inTenantContext($this->tenant, fn () => planWithOneRowDueOn($this->customer, $this->warehouse, '1405/06/22', 'INS-CARD'));

    $this->travelTo(CarbonImmutable::parse($now));

    $this->actingAs($this->owner)
        ->get($this->url.'/dashboard')
        ->assertOk()
        ->assertInertia(function ($page) use ($count, $daysLate): void {
            /** @var array{count: int, worst: list<array{days_late: int}>} $card */
            $card = propsOf($page)['installments'];

            expect($card['count'])->toBe($count)
                ->and($card['worst'][0]['days_late'] ?? null)->toBe($daysLate);
        });
})->with([
    'due day, just after Tehran midnight' => ['2026-09-12 21:00:00', 0, null],
    // The old card counted it overdue here, one day late.
    'due day, mid-afternoon' => ['2026-09-13 10:00:00', 0, null],
    'a day late, just after Tehran midnight' => ['2026-09-13 21:00:00', 1, 1],
    // The old card said two days here.
    'a day late, mid-afternoon' => ['2026-09-14 10:00:00', 1, 1],
]);

it('never puts another shop instalment on the desk', function (): void {
    $other = Tenant::factory()->withDomain()->create();
    subscribe($other, 'pro');
    app(TenantProvisioner::class)->seedRoles($other);
    app(SubscriptionResolver::class)->forget();

    inTenantContext($other, fn () => planWithOneRowDueOn(
        Party::factory()->create(),
        Warehouse::factory()->create(),
        '1405/06/20',
        'INS-NEIGHBOUR',
    ));

    inTenantContext($this->tenant, fn () => planWithOneRowDueOn($this->customer, $this->warehouse, '1405/06/20', 'INS-OURS'));

    $desk = collectionDeskAt('2026-09-13 10:00:00');

    expect(array_column($desk['overdue'], 'plan_number'))->toBe(['INS-OURS']);
})->group('isolation');
