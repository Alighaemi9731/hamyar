<?php

declare(strict_types=1);

use App\Modules\CRM\Models\Party;
use App\Modules\CRM\Models\PartyContact;
use App\Modules\CRM\Services\PartyImporter;
use App\Modules\Identity\Models\User;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Platform\Services\PlanCatalogueSeeder;
use App\Modules\Platform\Services\SubscriptionResolver;
use App\Modules\Platform\Services\TenantProvisioner;
use App\Support\Money;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Bulk customer import.
 *
 * The tests that matter are the ones about a sheet a real shop would send: Persian
 * digits, a semicolon delimiter from a Persian Windows Excel, a BOM, the same person
 * twice, and a row with no name.
 */
beforeEach(function (): void {
    app(PlanCatalogueSeeder::class)->sync();

    $this->tenant = Tenant::factory()->withDomain()->create();
    $this->url = tenantUrl($this->tenant);

    subscribe($this->tenant, 'pro');
    app(SubscriptionResolver::class)->forget();

    app(TenantProvisioner::class)->seedRoles($this->tenant);

    [$this->owner, $this->seller] = app(TenantContext::class)->runFor($this->tenant, function (): array {
        $owner = User::factory()->create();
        $owner->assignRole('Owner');

        $seller = User::factory()->create();
        $seller->assignRole('Salesperson');

        return [$owner, $seller];
    });

    $this->inTenant = fn (Closure $callback): mixed => inTenantContext($this->tenant, $callback);
});

afterEach(fn () => app(TenantContext::class)->forget());

/**
 * Upload a CSV and return the analyse response payload.
 *
 * @return array{token: string, headers: list<string>, mapping: array<string, int|null>}
 */
function uploadSheet(string $contents, string $name = 'customers.csv'): array
{
    /** @var string $url */
    $url = test()->url;

    /** @var array{token: string, headers: list<string>, mapping: array<string, int|null>} $payload */
    $payload = test()->actingAs(test()->owner)
        ->post($url.'/crm/import/analyse', [
            'file' => UploadedFile::fake()->createWithContent($name, $contents),
        ])
        ->assertOk()
        ->json();

    return $payload;
}

/* ---------------------------------------------------------------- reading -- */

it('reads the header row and guesses the mapping', function (): void {
    $payload = uploadSheet("نام,شماره همراه,مانده\nعلی رضایی,09121112233,120000\n");

    expect($payload['headers'])->toBe(['نام', 'شماره همراه', 'مانده']);
    expect($payload['mapping']['name'])->toBe(0);
    expect($payload['mapping']['mobile'])->toBe(1);
    expect($payload['mapping']['opening_balance'])->toBe(2);
});

it('survives a UTF-8 BOM and a semicolon delimiter', function (): void {
    // Both are what Excel on a Persian Windows produces, and both break a naive
    // reader: the BOM becomes part of the first header, and the whole file otherwise
    // reads as a single column.
    $payload = uploadSheet("\xEF\xBB\xBFنام;شماره همراه\nعلی رضایی;09121112233\n");

    expect($payload['headers'])->toBe(['نام', 'شماره همراه']);
    expect($payload['mapping']['name'])->toBe(0);
});

/* --------------------------------------------------------------- dry run -- */

it('reports what would happen and writes nothing', function (): void {
    $payload = uploadSheet(
        "نام,شماره همراه\n".
        "علی رضایی,09121112233\n".
        "مریم احمدی,09351234567\n"
    );

    $this->actingAs($this->owner)
        ->postJson($this->url.'/crm/import/dry-run', [
            'token' => $payload['token'],
            'kind' => 'customer',
            'unit' => Money::UNIT_TOMAN,
            'mapping' => $payload['mapping'],
        ])
        ->assertOk()
        ->assertJsonPath('counts.'.PartyImporter::OUTCOME_CREATE, 2);

    ($this->inTenant)(fn () => expect(Party::query()->count())->toBe(0));
});

it('flags a bad row and the same person twice in one file', function (): void {
    $payload = uploadSheet(
        "نام,شماره همراه\n".
        "علی رضایی,09121112233\n".
        ",09120000000\n".              // no name
        "علی رضایی دوباره,09121112233\n" // same mobile as line 2
    );

    $report = $this->actingAs($this->owner)
        ->postJson($this->url.'/crm/import/dry-run', [
            'token' => $payload['token'],
            'kind' => 'customer',
            'unit' => Money::UNIT_TOMAN,
            'mapping' => $payload['mapping'],
        ])
        ->assertOk()
        ->json();

    expect($report['counts'][PartyImporter::OUTCOME_CREATE])->toBe(1);
    expect($report['counts'][PartyImporter::OUTCOME_ERROR])->toBe(1);
    // Reported rather than silently merged: the shop's own list having someone twice
    // is something they should know about.
    expect($report['counts'][PartyImporter::OUTCOME_DUPLICATE])->toBe(1);
});

/* ---------------------------------------------------------------- commit -- */

it('imports a sheet with Persian digits and toman balances', function (): void {
    $payload = uploadSheet(
        "نام,شماره همراه,مانده\n".
        "علی رضایی,۰۹۱۲۱۱۱۲۲۳۳,۱۲۰٬۰۰۰\n".
        "مریم احمدی,09351234567,0\n"
    );

    $this->actingAs($this->owner)
        ->post($this->url.'/crm/import', [
            'token' => $payload['token'],
            'kind' => 'customer',
            'unit' => Money::UNIT_TOMAN,
            'mapping' => $payload['mapping'],
        ])
        ->assertRedirect();

    ($this->inTenant)(function (): void {
        expect(Party::query()->count())->toBe(2);

        $ali = Party::query()->where('name', 'علی رضایی')->firstOrFail();

        // 120,000 toman → 1,200,000 rial, and the digits normalised on the way in.
        expect($ali->opening_balance)->toBe(1_200_000)->toBeRial();
        expect($ali->contacts()->first()?->value)->toBe('09121112233');
    });
});

/*
| The ten-fold regression, at the level a shop would actually meet it.
|
| An Iranian sheet writes a decimal with a slash. The importer used to normalise money
| by stripping every non-digit, which CONCATENATED the fraction onto the amount:
| «۱۲۵۰۰۰۰۰/۰» toman was imported as 1,250,000,000 rial rather than 125,000,000 — ten
| times the balance, with nothing on screen to say so. The dot form landed a hundred
| times high. Both now route through `Money::parse()`.
*/
it('imports a balance written with a Persian decimal mark at its true value', function (string $cell): void {
    $payload = uploadSheet(
        "نام,شماره همراه,مانده\n".
        "علی رضایی,09121112233,{$cell}\n"
    );

    $this->actingAs($this->owner)
        ->post($this->url.'/crm/import', [
            'token' => $payload['token'],
            'kind' => 'customer',
            'unit' => Money::UNIT_TOMAN,
            'mapping' => $payload['mapping'],
        ])
        ->assertRedirect();

    ($this->inTenant)(function (): void {
        $ali = Party::query()->where('name', 'علی رضایی')->firstOrFail();

        // 12,500,000 toman is 125,000,000 rial. Exactly, for every spelling of it.
        expect($ali->opening_balance)->toBe(125_000_000)->toBeRial();
    });
})->with([
    'persian slash' => '12500000/0',
    'latin dot' => '12500000.00',
    'persian digits and slash' => '۱۲۵۰۰۰۰۰/۰',
    'no fraction' => '12500000',
]);

it('reports an unreadable balance as a row error instead of importing zero', function (): void {
    // The point of the whole change: a cell nobody can read must stop the row, not
    // become a zero balance that the shop discovers weeks later in a statement.
    $payload = uploadSheet(
        "نام,شماره همراه,مانده\n".
        "علی رضایی,09121112233,حدود دوازده میلیون\n"
    );

    $report = $this->actingAs($this->owner)
        ->postJson($this->url.'/crm/import/dry-run', [
            'token' => $payload['token'],
            'kind' => 'customer',
            'unit' => Money::UNIT_TOMAN,
            'mapping' => $payload['mapping'],
        ])
        ->assertOk()
        ->json();

    expect($report['counts'][PartyImporter::OUTCOME_ERROR])->toBe(1);
    expect($report['counts'][PartyImporter::OUTCOME_CREATE])->toBe(0);
    expect($report['rows'][0]['message'])->toContain('مانده اولیه');
});

it('refuses a cell whose currency word contradicts the chosen unit', function (): void {
    // Worth ten times every amount in the file, so it is an error rather than a word
    // to strip: the operator picked ریال and the sheet says تومان.
    $payload = uploadSheet(
        "نام,شماره همراه,مانده\n".
        "علی رضایی,09121112233,۱۲۵۰۰۰۰۰ تومان\n"
    );

    $report = $this->actingAs($this->owner)
        ->postJson($this->url.'/crm/import/dry-run', [
            'token' => $payload['token'],
            'kind' => 'customer',
            'unit' => Money::UNIT_RIAL,
            'mapping' => $payload['mapping'],
        ])
        ->assertOk()
        ->json();

    expect($report['counts'][PartyImporter::OUTCOME_ERROR])->toBe(1);
});

it('accepts a currency word that agrees with the chosen unit', function (): void {
    $payload = uploadSheet(
        "نام,شماره همراه,مانده\n".
        "علی رضایی,09121112233,۱۲۵۰۰۰۰۰ تومان\n"
    );

    $this->actingAs($this->owner)
        ->post($this->url.'/crm/import', [
            'token' => $payload['token'],
            'kind' => 'customer',
            'unit' => Money::UNIT_TOMAN,
            'mapping' => $payload['mapping'],
        ])
        ->assertRedirect();

    ($this->inTenant)(function (): void {
        expect(Party::query()->where('name', 'علی رضایی')->firstOrFail()->opening_balance)
            ->toBe(125_000_000)->toBeRial();
    });
});

it('matches an existing customer by mobile and fills gaps without overwriting', function (): void {
    $existing = ($this->inTenant)(function (): Party {
        $party = Party::factory()->create(['name' => 'نام درست', 'company_name' => null]);

        $party->contacts()->create([
            'type' => PartyContact::TYPE_MOBILE,
            'value' => '09121112233',
            'is_primary' => true,
        ]);

        return $party;
    });

    $payload = uploadSheet(
        "نام,شماره همراه,نام شرکت\n".
        "نام اشتباه از فایل قدیمی,09121112233,فروشگاه رضایی\n"
    );

    $this->actingAs($this->owner)
        ->post($this->url.'/crm/import', [
            'token' => $payload['token'],
            'kind' => 'customer',
            'unit' => Money::UNIT_TOMAN,
            'mapping' => $payload['mapping'],
        ])
        ->assertRedirect();

    ($this->inTenant)(function () use ($existing): void {
        expect(Party::query()->count())->toBe(1);

        $existing->refresh();

        // The sheet is an import, not a source of truth: an empty column is filled,
        // a name corrected in the app last week is not undone by a stale export.
        expect($existing->name)->toBe('نام درست');
        expect($existing->company_name)->toBe('فروشگاه رضایی');
    });
});

it('imports 500 rows cleanly', function (): void {
    // The Phase 4 DoD figure, run for real rather than asserted from a smaller one:
    // per-row queries and per-row transactions both only hurt at this size.
    $rows = "نام,شماره همراه\n";

    for ($i = 1; $i <= 500; $i++) {
        $mobile = '0912'.str_pad((string) $i, 7, '0', STR_PAD_LEFT);
        $rows .= "مشتری شماره {$i},{$mobile}\n";
    }

    $payload = uploadSheet($rows, 'big.csv');

    $this->actingAs($this->owner)
        ->post($this->url.'/crm/import', [
            'token' => $payload['token'],
            'kind' => 'customer',
            'unit' => Money::UNIT_TOMAN,
            'mapping' => $payload['mapping'],
        ])
        ->assertRedirect();

    ($this->inTenant)(function (): void {
        expect(Party::query()->count())->toBe(500);
        expect(PartyContact::query()->count())->toBe(500);
    });
});

it('deletes the uploaded file once it has been imported', function (): void {
    $payload = uploadSheet("نام\nعلی رضایی\n");

    $this->actingAs($this->owner)->post($this->url.'/crm/import', [
        'token' => $payload['token'],
        'kind' => 'customer',
        'unit' => Money::UNIT_TOMAN,
        'mapping' => $payload['mapping'],
    ]);

    // Customer lists left on disk are a liability nobody remembers to clean up.
    expect(Storage::disk('local')->exists('imports/'.$this->tenant->id.'/'.$payload['token']))
        ->toBeFalse();
});

/* ----------------------------------------------------------- authorization -- */

it('refuses the import to staff without crm.import', function (): void {
    // One form adds a customer someone is looking at; the other writes five hundred
    // rows nobody has read.
    $this->actingAs($this->seller)
        ->get($this->url.'/crm/import')
        ->assertForbidden();

    $this->actingAs($this->seller)
        ->post($this->url.'/crm/import/analyse', [
            'file' => UploadedFile::fake()->createWithContent('customers.csv', "نام\nعلی\n"),
        ])
        ->assertForbidden();
});

/* -------------------------------------------------------------- isolation -- */

it('cannot be pointed at another shop upload', function (): void {
    $payload = uploadSheet("نام\nعلی رضایی\n");

    $other = Tenant::factory()->withDomain()->create();
    subscribe($other, 'pro');
    app(TenantProvisioner::class)->seedRoles($other);
    app(SubscriptionResolver::class)->forget();

    $stranger = inTenantContext($other, function (): User {
        $user = User::factory()->create();
        $user->assignRole('Owner');

        return $user;
    });

    // The token names a file inside the caller's own tenant directory, so another
    // shop's token simply does not exist here — and a traversal attempt is stripped
    // by `basename` before the lookup.
    $this->actingAs($stranger)
        ->postJson(tenantUrl($other).'/crm/import/dry-run', [
            'token' => $payload['token'],
            'kind' => 'customer',
            'unit' => Money::UNIT_TOMAN,
            'mapping' => ['name' => 0],
        ])
        ->assertNotFound();

    $this->actingAs($stranger)
        ->postJson(tenantUrl($other).'/crm/import/dry-run', [
            'token' => '../'.$this->tenant->id.'/'.$payload['token'],
            'kind' => 'customer',
            'unit' => Money::UNIT_TOMAN,
            'mapping' => ['name' => 0],
        ])
        ->assertNotFound();
})->group('isolation');
