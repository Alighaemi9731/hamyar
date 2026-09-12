<?php

declare(strict_types=1);

use App\Modules\Cheques\Models\Cheque;
use App\Modules\CRM\Models\Party;
use App\Modules\Identity\Models\User;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Platform\Services\SubscriptionResolver;
use App\Modules\Platform\Services\TenantProvisioner;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;

/**
 * A cheque's due date is the day printed on the paper — the shop's day, not UTC's.
 *
 * ## The defect this file exists for
 *
 * `<JDatePicker>` sends ۱۴۰۵/۰۶/۲۲ as `2026-09-12T20:30:00.000Z`, the UTC instant of Tehran
 * midnight. `ChequeRequest` parsed it and handed it to the `date` cast, which kept its UTC
 * date. The shopkeeper picked the 22nd, the list said the 21st, and the reminder went out
 * a day late. The existing register test posted `2026-12-06T00:00:00Z` — UTC midnight, a
 * value no browser sends — so it could never see it.
 *
 * The second half is the calendar: from midnight to 03:30 Tehran the UTC date is still
 * yesterday's, and a comparison against it kept yesterday's cheque off the overdue list.
 */
beforeEach(function (): void {
    $this->tenant = Tenant::factory()->withDomain()->create();
    $this->url = appUrl();

    subscribe($this->tenant, 'pro');
    app(SubscriptionResolver::class)->forget();
    app(TenantProvisioner::class)->seedRoles($this->tenant);

    /** @var array{User, Party} $fixtures */
    $fixtures = inTenantContext($this->tenant, function (): array {
        $owner = User::factory()->create();
        $owner->assignRole('Owner');

        return [$owner, Party::factory()->create(['name' => 'حسن رضایی'])];
    });

    [$this->owner, $this->party] = $fixtures;
});

afterEach(fn () => app(TenantContext::class)->forget());

/**
 * An open received cheque, written straight to the table — the calendar tests are about
 * reading it, not about the register form.
 */
function openChequeDueOn(Party $party, string $dueDate, string $serial): Cheque
{
    /** @var Cheque $cheque */
    $cheque = Cheque::query()->create([
        'direction' => 'received',
        'status' => 'in_hand',
        'party_id' => $party->id,
        'amount' => 10_000_000,
        'bank_name' => 'ملت',
        'serial' => $serial,
        'due_date' => $dueDate,
    ]);

    return $cheque;
}

/* ------------------------------------------------------------ the write -- */

it('stores the due date the shopkeeper picked, not the UTC date of its midnight', function (): void {
    /** @var Party $party */
    $party = $this->party;

    $this->actingAs($this->owner)
        ->post($this->url.'/cheques', [
            'direction' => 'received',
            'party_id' => $party->id,
            'amount' => 250_000_000,
            'serial' => '123456',
            'bank_name' => 'بانک ملی',
            // Exactly what the picker sends for ۱۴۰۵/۰۶/۲۲.
            'due_date' => jDatePickerValue('1405/06/22'),
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    /** @var string|null $stored */
    $stored = inTenantContext($this->tenant, fn (): mixed => Cheque::query()->first()?->getRawOriginal('due_date'));

    expect(jDatePickerValue('1405/06/22'))->toBe('2026-09-12T20:30:00.000Z')
        ->and($stored)->toBe('2026-09-13');

    // And the list renders it as the day that was picked.
    $this->actingAs($this->owner)
        ->get($this->url.'/cheques?direction=received')
        ->assertOk()
        ->assertInertia(function ($page): void {
            $rows = paginatedOf($page, 'cheques');

            expect($rows)->toHaveCount(1)
                ->and(jalaliDayOnScreen($rows[0]['due_date'] ?? null))->toBe('1405/06/22');
        });
});

/* --------------------------------------------------------- the calendar -- */

it('reads overdue and the horizon on the shop calendar at any hour', function (string $now): void {
    inTenantContext($this->tenant, function (): void {
        /** @var Party $party */
        $party = $this->party;

        // Tehran's today is 1405/06/22 (2026-09-13) at both instants in the dataset.
        openChequeDueOn($party, '2026-09-12', 'YESTERDAY');
        openChequeDueOn($party, '2026-09-13', 'TODAY');
        // The index looks fourteen days ahead: the 27th is the last day inside it.
        openChequeDueOn($party, '2026-09-27', 'HORIZON');
        openChequeDueOn($party, '2026-09-28', 'BEYOND');
    });

    $this->travelTo(CarbonImmutable::parse($now));

    $this->actingAs($this->owner)
        ->get($this->url.'/cheques?direction=received')
        ->assertOk()
        ->assertInertia(function ($page): void {
            $props = propsOf($page);

            /** @var list<array{serial: string}> $overdue */
            $overdue = $props['overdue'];
            /** @var list<array{serial: string}> $due */
            $due = $props['due'];

            expect(array_column($overdue, 'serial'))->toBe(['YESTERDAY'])
                ->and(array_column($due, 'serial'))->toBe(['TODAY', 'HORIZON']);
        });
})->with([
    // 00:30 in Tehran. The UTC date is still the 12th: the old comparison left yesterday's
    // cheque in "due" and dropped the 27th off the end of the horizon.
    'just after Tehran midnight' => '2026-09-12 21:00:00',
    // 13:30 in Tehran, when both clocks agree on the date — unchanged behaviour.
    'mid-afternoon' => '2026-09-13 10:00:00',
]);

it('flags a cheque overdue on the dashboard from the first minute of the shop day', function (): void {
    inTenantContext($this->tenant, function (): void {
        /** @var Party $party */
        $party = $this->party;

        openChequeDueOn($party, '2026-09-12', 'YESTERDAY');
        openChequeDueOn($party, '2026-09-13', 'TODAY');
    });

    $this->travelTo(CarbonImmutable::parse('2026-09-12 21:00:00'));

    $this->actingAs($this->owner)
        ->get($this->url.'/dashboard')
        ->assertOk()
        ->assertInertia(function ($page): void {
            /** @var array{soonest: list<array{due_date: string, overdue: bool}>} $cheques */
            $cheques = propsOf($page)['cheques'];

            $flags = [];

            foreach ($cheques['soonest'] as $row) {
                $flags[jalaliDayOnScreen($row['due_date'])] = $row['overdue'];
            }

            expect($flags)->toBe(['1405/06/21' => true, '1405/06/22' => false]);
        });
});

it('opens the cheque calendar report on the first day of the range, not the day before', function (): void {
    inTenantContext($this->tenant, function (): void {
        /** @var Party $party */
        $party = $this->party;

        openChequeDueOn($party, '2026-09-12', 'BEFORE');
        openChequeDueOn($party, '2026-09-13', 'FIRST');
        openChequeDueOn($party, '2026-09-14', 'LAST');
        openChequeDueOn($party, '2026-09-15', 'AFTER');
    });

    /*
    | «۲۲ تا ۲۳ شهریور». The range's lower bound is 20:30 UTC on the 21st, and taking its
    | UTC date put the 21st's cheque at the top of a report that was asked to start on the
    | 22nd.
    */
    $this->actingAs($this->owner)
        ->get($this->url.'/reporting/financial?'.http_build_query(['cut' => 'cheques', 'from' => '1405/06/22', 'to' => '1405/06/23']))
        ->assertOk()
        ->assertInertia(function ($page): void {
            $days = array_map(
                fn (array $row): mixed => $row['due_date'] ?? null,
                rowsOf($page),
            );

            expect($days)->toBe(['۱۴۰۵/۰۶/۲۲', '۱۴۰۵/۰۶/۲۳']);
        });
});

/* ------------------------------------------------------------ isolation -- */

it('never lists another shop cheque as overdue', function (): void {
    $other = Tenant::factory()->withDomain()->create();
    subscribe($other, 'pro');
    app(TenantProvisioner::class)->seedRoles($other);
    app(SubscriptionResolver::class)->forget();

    inTenantContext($other, function (): void {
        openChequeDueOn(Party::factory()->create(), '2026-09-12', 'NEIGHBOUR');
    });

    inTenantContext($this->tenant, function (): void {
        /** @var Party $party */
        $party = $this->party;

        openChequeDueOn($party, '2026-09-12', 'OURS');
    });

    $this->travelTo(CarbonImmutable::parse('2026-09-12 21:00:00'));

    $this->actingAs($this->owner)
        ->get($this->url.'/cheques?direction=received')
        ->assertOk()
        ->assertInertia(function ($page): void {
            /** @var list<array{serial: string}> $overdue */
            $overdue = propsOf($page)['overdue'];

            expect(array_column($overdue, 'serial'))->toBe(['OURS']);
        });
})->group('isolation');
