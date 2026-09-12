<?php

declare(strict_types=1);

use App\Modules\CRM\Models\Party;
use App\Modules\Identity\Models\User;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Platform\Services\PlanCatalogueSeeder;
use App\Modules\Platform\Services\SubscriptionResolver;
use App\Modules\Platform\Services\TenantProvisioner;
use App\Support\Money;
use App\Support\Tenancy\TenantContext;

/**
 * A birthday is a day on the customer's calendar, not an instant.
 *
 * `<JDatePicker>` sends ۱۳۷۰/۰۵/۲۳ as the UTC instant of Tehran midnight — 20:30 UTC the
 * evening before. `PartyRequest` passed that string straight to the `date` cast, which kept
 * its UTC date: the party page showed the day before the one picked, and the birthday
 * greeting went out a day late, every year.
 *
 * These tests post exactly the picker's string and read the day back the way the screen
 * does, through the ISO prop.
 */
beforeEach(function (): void {
    app(PlanCatalogueSeeder::class)->sync();

    $this->tenant = Tenant::factory()->withDomain()->create();
    $this->url = appUrl();

    subscribe($this->tenant, 'pro');
    app(SubscriptionResolver::class)->forget();
    app(TenantProvisioner::class)->seedRoles($this->tenant);

    $this->owner = inTenantContext($this->tenant, function (): User {
        $owner = User::factory()->create();
        $owner->assignRole('Owner');

        return $owner;
    });
});

afterEach(fn () => app(TenantContext::class)->forget());

/**
 * The body the party form posts, with the birthday as given.
 *
 * @return array<string, mixed>
 */
function partyFormWithBirthday(string $name, ?string $birthday): array
{
    return [
        'name' => $name,
        'kind' => 'customer',
        'unit' => Money::UNIT_RIAL,
        'birthday' => $birthday,
        'contacts' => [],
    ];
}

/**
 * The `party.birthday` prop a party page was rendered with — the ISO string the screen
 * formats and the edit form's picker is seeded from.
 */
function birthdayPropOf(object $page): mixed
{
    /** @var array{birthday?: mixed} $party */
    $party = propsOf($page)['party'] ?? [];

    return $party['birthday'] ?? null;
}

function storedBirthdayOf(Tenant $tenant, string $name): ?string
{
    /** @var string|null $raw */
    $raw = inTenantContext($tenant, fn (): mixed => Party::query()->where('name', $name)->first()?->getRawOriginal('birthday'));

    return $raw;
}

it('stores the birthday the shopkeeper picked, not the UTC date of its midnight', function (): void {
    // 1370/05/23 is 1991-08-14 — a summer in which Tehran was on UTC+04:30, which the
    // picker's fixed +03:30 does not know about. Still the right day.
    $this->actingAs($this->owner)
        ->post($this->url.'/crm/parties', partyFormWithBirthday('مشتری تولد', jDatePickerValue('1370/05/23')))
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    expect(jDatePickerValue('1370/05/23'))->toBe('1991-08-13T20:30:00.000Z')
        ->and(storedBirthdayOf($this->tenant, 'مشتری تولد'))->toBe('1991-08-14');

    /** @var Party $party */
    $party = inTenantContext($this->tenant, fn (): Party => Party::query()->where('name', 'مشتری تولد')->firstOrFail());

    // The party page and the edit form both render the day that was picked.
    $this->actingAs($this->owner)
        ->get($this->url.'/crm/parties/'.$party->id)
        ->assertOk()
        ->assertInertia(fn ($page) => expect(jalaliDayOnScreen(birthdayPropOf($page)))->toBe('1370/05/23'));

    $this->actingAs($this->owner)
        ->get($this->url.'/crm/parties/'.$party->id.'/edit')
        ->assertOk()
        ->assertInertia(fn ($page) => expect(jalaliDayOnScreen(birthdayPropOf($page)))->toBe('1370/05/23'));
});

it('keeps the birthday when the edit form posts it back untouched, and moves it when picked again', function (): void {
    $this->actingAs($this->owner)
        ->post($this->url.'/crm/parties', partyFormWithBirthday('مشتری ویرایش', jDatePickerValue('1405/06/22')))
        ->assertRedirect();

    /** @var Party $party */
    $party = inTenantContext($this->tenant, fn (): Party => Party::query()->where('name', 'مشتری ویرایش')->firstOrFail());

    /** @var mixed $sentToForm */
    $sentToForm = null;

    $this->actingAs($this->owner)
        ->get($this->url.'/crm/parties/'.$party->id.'/edit')
        ->assertOk()
        ->assertInertia(function ($page) use (&$sentToForm): void {
            $sentToForm = birthdayPropOf($page);
        });

    // The form's field holds the prop as it arrived; an untouched save posts it back.
    expect($sentToForm)->toBeString();

    /** @var string $sentToForm */
    $this->actingAs($this->owner)
        ->put($this->url.'/crm/parties/'.$party->id, partyFormWithBirthday('مشتری ویرایش', $sentToForm))
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    expect(storedBirthdayOf($this->tenant, 'مشتری ویرایش'))->toBe('2026-09-13');

    // Picked again, through the picker: ۱۴۰۵/۰۶/۲۳.
    $this->actingAs($this->owner)
        ->put($this->url.'/crm/parties/'.$party->id, partyFormWithBirthday('مشتری ویرایش', jDatePickerValue('1405/06/23')))
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    expect(storedBirthdayOf($this->tenant, 'مشتری ویرایش'))->toBe('2026-09-14');
});

it('never lets one shop read or rewrite another shop customer birthday', function (): void {
    $other = Tenant::factory()->withDomain()->create();
    subscribe($other, 'pro');
    app(TenantProvisioner::class)->seedRoles($other);
    app(SubscriptionResolver::class)->forget();

    /** @var Party $foreign */
    $foreign = inTenantContext($other, fn (): Party => Party::factory()->create([
        'name' => 'مشتری همسایه',
        'birthday' => '1991-08-14',
    ]));

    $this->actingAs($this->owner)
        ->get($this->url.'/crm/parties/'.$foreign->id.'/edit')
        ->assertNotFound();

    $this->actingAs($this->owner)
        ->put($this->url.'/crm/parties/'.$foreign->id, partyFormWithBirthday('مشتری همسایه', jDatePickerValue('1405/06/22')))
        ->assertNotFound();

    expect(storedBirthdayOf($other, 'مشتری همسایه'))->toBe('1991-08-14');
})->group('isolation');
