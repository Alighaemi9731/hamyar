<?php

declare(strict_types=1);

use App\Modules\CRM\Models\Party;
use App\Modules\CRM\Models\PartyContact;
use App\Modules\CRM\Models\PartyFollowUp;
use App\Modules\Identity\Models\User;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Platform\Services\SubscriptionResolver;
use App\Modules\Platform\Services\TenantProvisioner;
use App\Support\Money;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;

/**
 * The meter, at the two places a shop meets it in CRM: the counter and the spreadsheet.
 *
 * ## Why this file exists separately from the guard's own suite
 *
 * `Platform/tests/Feature/Quota/*` proves the guard is correct — it counts, it refuses at
 * the ceiling, it is atomic under concurrency — and it does all of that against a
 * synthetic `quota.widgets` metric so those tests break when the guard breaks rather than
 * when CRM renames something. The cost of that isolation is that none of them touch a
 * route, and a guard that is perfect and never called is, from the shop floor,
 * indistinguishable from no guard at all.
 *
 * So this file drives the real endpoints and asserts the pairing that `consume()`-inside-
 * the-transaction exists to buy: a spent credit always has a row behind it, and a row
 * always has a spent credit behind it.
 *
 * ## CRM has two enforcement sites and they are not the same shape
 *
 * `POST /crm/parties` and `POST /crm/parties/{party}/follow-ups` each spend one credit and
 * consume **before** the write, so a failure downstream unwinds the credit with it.
 *
 * `POST /crm/import` is the interesting one. It spends **N at once**, and it consumes
 * *after* the walk rather than before it — the create count does not exist until the file
 * has been read. That ordering is only safe because the consume is still inside the same
 * transaction as the inserts, and the claim worth testing is precisely the one that
 * ordering makes: a sheet of forty that only has room for twelve imports **none of them**,
 * not the twelve that fit. A half-imported customer list is worse than no import, because
 * nobody can tell which half.
 */
beforeEach(function (): void {
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
 * One customer added at the counter, through the real form.
 *
 * Deliberately asserts nothing about the response: every test here wants to say something
 * different about it, and a helper that asserted success could not be used by the tests
 * about refusal.
 *
 * @param  array<string, mixed>  $overrides
 * @return Illuminate\Testing\TestResponse<Illuminate\Http\Response>
 */
function addPartyAtCounter(string $name = 'مشتری تازه', array $overrides = []): Illuminate\Testing\TestResponse
{
    /** @var User $owner */
    $owner = test()->owner;
    /** @var string $url */
    $url = test()->url;

    return test()->actingAs($owner)->post($url.'/crm/parties', array_merge([
        'name' => $name,
        'kind' => 'customer',
        'unit' => Money::UNIT_RIAL,
        'contacts' => [],
    ], $overrides));
}

function crmPartyCount(): int
{
    /** @var Tenant $tenant */
    $tenant = test()->tenant;

    /** @var int $count */
    $count = inTenantContext($tenant, fn (): int => Party::query()->count());

    return $count;
}

/**
 * Step one of the wizard: hand the file over and get back the token and column mapping.
 *
 * @return array{token: string, headers: list<string>, mapping: array<string, int|null>}
 */
function uploadPartyList(string $contents): array
{
    /** @var User $owner */
    $owner = test()->owner;
    /** @var string $url */
    $url = test()->url;

    /** @var array{token: string, headers: list<string>, mapping: array<string, int|null>} $payload */
    $payload = test()->actingAs($owner)
        ->post($url.'/crm/import/analyse', [
            'file' => UploadedFile::fake()->createWithContent('customers.csv', $contents),
        ])
        ->assertOk()
        ->json();

    return $payload;
}

/**
 * Step three: commit the sheet the token names.
 *
 * @param  array{token: string, mapping: array<string, int|null>}  $payload
 * @return Illuminate\Testing\TestResponse<Illuminate\Http\Response>
 */
function commitPartyList(array $payload): Illuminate\Testing\TestResponse
{
    /** @var User $owner */
    $owner = test()->owner;
    /** @var string $url */
    $url = test()->url;

    return test()->actingAs($owner)->post($url.'/crm/import', [
        'token' => $payload['token'],
        'kind' => 'customer',
        'unit' => Money::UNIT_RIAL,
        'mapping' => $payload['mapping'],
    ]);
}

/**
 * A sheet of `$count` new people, none of whom the shop already has.
 */
function partySheet(int $count): string
{
    $rows = "نام,شماره همراه\n";

    for ($i = 1; $i <= $count; $i++) {
        $rows .= 'مشتری شماره '.$i.',0912'.str_pad((string) $i, 7, '0', STR_PAD_LEFT)."\n";
    }

    return $rows;
}

/* --------------------------------------------------------------- counter -- */

it('spends one party credit for one customer added at the counter', function (): void {
    addPartyAtCounter()->assertSessionHasNoErrors()->assertRedirect();

    expect(quotaUsed($this->tenant, 'crm.parties'))->toBe(1);
});

it('refuses the party that would cross the ceiling, and writes no row', function (): void {
    capQuota($this->tenant, 'crm.parties', 1);

    addPartyAtCounter('اولی')->assertSessionHasNoErrors();
    expect(crmPartyCount())->toBe(1);

    // The second one is the whole test. A shop at its cap must be *told*, not handed a
    // form that silently does nothing — see CLAUDE.md on the operator pressing submit
    // twice with a customer standing at the counter.
    addPartyAtCounter('دومی')->assertSessionHasErrors('quota');

    expect(crmPartyCount())->toBe(1)
        ->and(quotaUsed($this->tenant, 'crm.parties'))->toBe(1);
});

it('hands the operator something to render, not just an error string', function (): void {
    capQuota($this->tenant, 'crm.parties', 0);

    addPartyAtCounter();

    /** @var array<string, mixed> $block */
    $block = session('quota_block') ?? [];

    // These are the keys `quota-block.tsx` reads. A refusal that reached the browser
    // without them would render an empty card, which is worse than a 500 because nobody
    // would report it.
    expect($block)->toHaveKeys(['metric', 'label', 'message', 'used', 'limit', 'resets_at', 'next_plan'])
        ->and($block['metric'])->toBe('crm.parties')
        // Persian, not the exception's English. `QuotaExceeded` stopped extending
        // `RuntimeException` precisely because a dozen controllers converted it into a
        // field message carrying exactly that English string.
        ->and($block['message'])->toBeString()->not->toContain('Quota exceeded');
});

/*
| The fourth claim of the shared brief — "a write that fails for its own reasons spends
| nothing" — is asserted below in the only form CRM can honestly make it.
|
| The brief asks for a genuine failure *inside* the transaction, after the credit has been
| reserved, the way Sales has one: a phone another till sold a second earlier makes
| `FinaliseInvoice` throw with the credit already consumed, and the rollback is the claim.
| CRM has no equivalent. Both of its single-credit paths do nothing between `consume()` and
| the insert except the insert, every column the form can reach is validated to within the
| column it lands in (`name` 180 ≤ 255, `economic_code` 20 = 20, `national_id` digits:10 ≤
| 11, both money fields well inside bigint after the toman conversion), and the one unique
| index a shopkeeper can collide with — `parties_national_id_unique` — is mirrored by a
| FormRequest rule that catches the collision before the transaction ever opens. Inventing
| a failure to fill the gap would have tested a code path the product does not have.
|
| So this asserts the reachable half, and says what it proves: the guard is behind
| validation, not in front of it. That is a real regression to hold — moving `consume()`
| into the controller body ahead of the FormRequest, or into `prepareForValidation`, would
| charge a shop a credit for a typo — and `quotaRowExists()` is the assertion that sees it,
| because a credit reserved and rolled back leaves a counter row reading zero while a
| credit never reserved leaves no row at all.
*/
it('spends nothing when the create is refused before the transaction opens', function (): void {
    inTenantContext($this->tenant, fn () => Party::factory()->create([
        'name' => 'اولی',
        'national_id' => '0012345678',
    ]));

    // The same human entered twice splits their balance in half and makes both statements
    // wrong, so the shop is stopped at the field rather than at the database.
    addPartyAtCounter('دومی', ['national_id' => '0012345678'])
        ->assertSessionHasErrors('national_id');

    expect(crmPartyCount())->toBe(1)
        // No row at all, rather than a row reading zero: the two are different claims and
        // only the first one says the guard was never reached.
        ->and(quotaRowExists($this->tenant, 'crm.parties'))->toBeFalse();
});

/* ------------------------------------------------------------- follow-ups -- */

it('meters a follow-up against follow-ups, never against parties', function (): void {
    // Created straight through the model, so the party credit is untouched going in and
    // the assertion below is about the follow-up alone.
    /** @var Party $customer */
    $customer = inTenantContext($this->tenant, fn (): Party => Party::factory()->create(['name' => 'مشتری']));

    $this->actingAs($this->owner)
        ->post($this->url.'/crm/parties/'.$customer->id.'/follow-ups', [
            'title' => 'تماس برای گارانتی',
            'due_at' => now()->addDays(3)->toIso8601String(),
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    expect(quotaUsed($this->tenant, 'crm.follow_ups'))->toBe(1)
        // Promising to call someone back is not acquiring a customer. Charging it to the
        // party credit would make a shop pay twice for one relationship — and a reminder
        // is the thing a shop writes ten of against a single «طرف حساب».
        ->and(quotaRowExists($this->tenant, 'crm.parties'))->toBeFalse();
});

it('refuses the follow-up at its own ceiling and names its own metric', function (): void {
    /** @var Party $customer */
    $customer = inTenantContext($this->tenant, fn (): Party => Party::factory()->create(['name' => 'مشتری']));

    capQuota($this->tenant, 'crm.follow_ups', 0);

    $this->actingAs($this->owner)
        ->post($this->url.'/crm/parties/'.$customer->id.'/follow-ups', [
            'title' => 'تماس برای گارانتی',
            'due_at' => now()->addDays(3)->toIso8601String(),
        ])
        ->assertSessionHasErrors('quota');

    /** @var array<string, mixed> $block */
    $block = session('quota_block') ?? [];

    // The metric on the block is what the upgrade button is aimed at. A refusal that
    // named `crm.parties` here would sell the shop a plan that does not fix what stopped
    // them, which is how an upsell becomes a refund.
    expect($block['metric'] ?? null)->toBe('crm.follow_ups')
        ->and(inTenantContext($this->tenant, fn (): int => PartyFollowUp::query()->count()))->toBe(0)
        ->and(quotaRowExists($this->tenant, 'crm.parties'))->toBeFalse();
});

/* ----------------------------------------------------------------- import -- */

it('spends one credit per new customer in a single import', function (): void {
    $payload = uploadPartyList(partySheet(3));

    commitPartyList($payload)->assertSessionHasNoErrors()->assertRedirect();

    // Three rows, three credits, one `consume()` call. The batch is charged as a batch —
    // an importer that consumed per row would refuse halfway through a file instead of
    // before it, which is the failure the whole ordering in `PartyImporter::import()`
    // exists to avoid.
    expect(quotaUsed($this->tenant, 'crm.parties'))->toBe(3)
        ->and(crmPartyCount())->toBe(3);
});

it('refuses the whole import when the batch would cross the ceiling', function (): void {
    // Room for two, a sheet of three. The tempting behaviour is to import the two that
    // fit; the correct one is to import nothing, because a shop that uploads its customer
    // list and gets an arbitrary prefix of it has no way to tell which rows landed.
    capQuota($this->tenant, 'crm.parties', 2);

    $payload = uploadPartyList(partySheet(3));

    commitPartyList($payload)->assertSessionHasErrors('quota');

    expect(crmPartyCount())->toBe(0)
        // Not two, not one: none. And no counter row at all — the credit reservation
        // rolled back with the inserts rather than leaving a partial charge behind.
        ->and(quotaRowExists($this->tenant, 'crm.parties'))->toBeFalse();

    /** @var array<string, mixed> $block */
    $block = session('quota_block') ?? [];

    // A bulk refusal is a different sentence from a single one: the operator is holding a
    // spreadsheet and needs to know how much of it would fit, so the block carries the
    // size of the batch it turned down rather than just «سهمیه تمام شد».
    expect($block['metric'] ?? null)->toBe('crm.parties')
        ->and($block['requested'] ?? null)->toBe(3)
        ->and($block['limit'] ?? null)->toBe(2)
        ->and($block['message'] ?? null)->toBeString()->not->toContain('Quota exceeded');
});

it('spends nothing for an import that only fills in customers the shop already has', function (): void {
    inTenantContext($this->tenant, function (): void {
        $party = Party::factory()->create(['name' => 'نام درست']);

        $party->contacts()->create([
            'type' => PartyContact::TYPE_MOBILE,
            'value' => '0912'.str_pad('1', 7, '0', STR_PAD_LEFT),
            'is_primary' => true,
        ]);
    });

    $payload = uploadPartyList(partySheet(1));

    commitPartyList($payload)->assertSessionHasNoErrors()->assertRedirect();

    // Matched by mobile, so the row is an update rather than a create — and updates are
    // free by design. A shop re-uploading last month's export to fill in the columns it
    // has since typed in must not be charged again for customers it already owns.
    expect(crmPartyCount())->toBe(1)
        ->and(quotaRowExists($this->tenant, 'crm.parties'))->toBeFalse();
});
