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
use App\Support\Spreadsheet\SpreadsheetReaders;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Facades\Excel;

/**
 * The same import, through a real `.xlsx`.
 *
 * The file a shop actually sends is not a tidy fixture: numbers typed with Persian
 * digits, a half-space inside a name, a mobile number Excel decided was numeric, and
 * the same customer entered twice by two people. Every one of those is here, because
 * every one of them silently produces a wrong customer list rather than an error.
 */
beforeEach(function (): void {
    app(PlanCatalogueSeeder::class)->sync();

    $this->tenant = Tenant::factory()->withDomain()->create();
    $this->url = tenantUrl($this->tenant);

    subscribe($this->tenant, 'pro');
    app(SubscriptionResolver::class)->forget();

    app(TenantProvisioner::class)->seedRoles($this->tenant);

    $this->owner = inTenantContext($this->tenant, function (): User {
        $user = User::factory()->create();
        $user->assignRole('Owner');

        return $user;
    });

    $this->inTenant = fn (Closure $callback): mixed => inTenantContext($this->tenant, $callback);
});

afterEach(fn () => app(TenantContext::class)->forget());

/**
 * Write a real workbook and hand it back as an upload.
 *
 * Built with the same package that reads it, so the test exercises the actual file
 * format rather than a hand-rolled approximation of one.
 *
 * @param  list<list<string|int|float|null>>  $rows
 */
function xlsxUpload(array $rows, string $name = 'customers.xlsx'): UploadedFile
{
    $export = new class($rows) implements FromArray
    {
        /**
         * @param  list<list<string|int|float|null>>  $rows
         */
        public function __construct(private readonly array $rows) {}

        /**
         * @return list<list<string|int|float|null>>
         */
        public function array(): array
        {
            return $this->rows;
        }
    };

    $path = tempnam(sys_get_temp_dir(), 'import').'.xlsx';

    file_put_contents($path, Excel::raw($export, Maatwebsite\Excel\Excel::XLSX));

    return new UploadedFile($path, $name, null, null, true);
}

/**
 * Upload a workbook and return the analyse payload.
 *
 * @return array{token: string, headers: list<string>, mapping: array<string, int|null>}
 */
function analyseUpload(UploadedFile $file): array
{
    /** @var string $url */
    $url = test()->url;

    /** @var array{token: string, headers: list<string>, mapping: array<string, int|null>} $payload */
    $payload = test()->actingAs(test()->owner)
        ->post($url.'/crm/import/analyse', ['file' => $file])
        ->assertOk()
        ->json();

    return $payload;
}

/* ---------------------------------------------------------------- reading -- */

it('advertises xlsx now that the reader is registered', function (): void {
    // The upload field offers what the registry can actually open, so a shop is never
    // invited to hand over a file nothing will read.
    expect(app(SpreadsheetReaders::class)->extensions())
        ->toContain('csv')
        ->toContain('xlsx');
});

it('reads a workbook and guesses the mapping from Persian headers', function (): void {
    $payload = analyseUpload(xlsxUpload([
        ['نام', 'شماره همراه', 'مانده اولیه'],
        ['علی رضایی', '09121112233', '120000'],
    ]));

    expect($payload['headers'])->toBe(['نام', 'شماره همراه', 'مانده اولیه']);
    expect($payload['mapping']['name'])->toBe(0);
    expect($payload['mapping']['mobile'])->toBe(1);
    expect($payload['mapping']['opening_balance'])->toBe(2);
});

it('recovers a mobile number Excel stored as a float', function (): void {
    // A cell of digits with no leading zero becomes a float, and comes back as
    // 9.1211122e+9. Rendered naively that is what lands in the customer record.
    $payload = analyseUpload(xlsxUpload([
        ['نام', 'شماره همراه'],
        ['علی رضایی', 9121112233],
    ]));

    $this->actingAs($this->owner)
        ->post($this->url.'/crm/import', [
            'token' => $payload['token'],
            'kind' => 'customer',
            'unit' => Money::UNIT_TOMAN,
            'mapping' => $payload['mapping'],
        ])
        ->assertRedirect();

    ($this->inTenant)(function (): void {
        expect(PartyContact::query()->value('value'))->toBe('9121112233');
    });
});

/* ----------------------------------------------------------- messy Persian -- */

it('imports a messy Persian workbook: mixed digits, half-spaces and a duplicate', function (): void {
    $payload = analyseUpload(xlsxUpload([
        ['نام', 'شماره همراه', 'مانده اولیه'],
        // Persian digits in both the number and the money column.
        ['علی رضایی', '۰۹۱۲۱۱۱۲۲۳۳', '۱۲۰٬۰۰۰'],
        // A ZWNJ half-space inside the name — the normal way Persian is typed, and it
        // must survive into the record untouched.
        ['مریم‌ احمدی', '۰۹۳۵۱۲۳۴۵۶۷', '0'],
        // Latin digits with separators someone typed by hand.
        ['حسین کریمی', '0912-111-9999', '45,000'],
        // The same person as line 2, entered again by a second member of staff.
        ['علی رضایی (تکراری)', '09121112233', '0'],
        // No name: a row that cannot become a customer.
        ['', '09120000000', '0'],
    ]));

    $report = $this->actingAs($this->owner)
        ->postJson($this->url.'/crm/import/dry-run', [
            'token' => $payload['token'],
            'kind' => 'customer',
            'unit' => Money::UNIT_TOMAN,
            'mapping' => $payload['mapping'],
        ])
        ->assertOk()
        ->json();

    expect($report['counts'][PartyImporter::OUTCOME_CREATE])->toBe(3);
    expect($report['counts'][PartyImporter::OUTCOME_DUPLICATE])->toBe(1);
    expect($report['counts'][PartyImporter::OUTCOME_ERROR])->toBe(1);

    $this->actingAs($this->owner)
        ->post($this->url.'/crm/import', [
            'token' => $payload['token'],
            'kind' => 'customer',
            'unit' => Money::UNIT_TOMAN,
            'mapping' => $payload['mapping'],
        ])
        ->assertRedirect();

    ($this->inTenant)(function (): void {
        expect(Party::query()->count())->toBe(3);

        $ali = Party::query()->where('name', 'علی رضایی')->firstOrFail();

        // Persian digits normalised, separators stripped, toman converted to rial.
        expect($ali->contacts()->value('value'))->toBe('09121112233');
        expect($ali->opening_balance)->toBe(1_200_000)->toBeRial();

        // The half-space is part of the name as typed and is not "cleaned" away.
        expect(Party::query()->where('name', 'مریم‌ احمدی')->exists())->toBeTrue();

        $hossein = Party::query()->where('name', 'حسین کریمی')->firstOrFail();
        expect($hossein->contacts()->value('value'))->toBe('09121119999');
        expect($hossein->opening_balance)->toBe(450_000)->toBeRial();
    });
});

it('reads a workbook and a CSV to exactly the same result', function (): void {
    // One shape, one set of bugs: whichever format a shop sends, the import behaves
    // identically or the format becomes a hidden variable in every support call.
    $rows = [
        ['نام', 'شماره همراه'],
        ['علی رضایی', '۰۹۱۲۱۱۱۲۲۳۳'],
        ['مریم احمدی', '09351234567'],
    ];

    $fromXlsx = analyseUpload(xlsxUpload($rows));

    $csv = "نام,شماره همراه\nعلی رضایی,۰۹۱۲۱۱۱۲۲۳۳\nمریم احمدی,09351234567\n";
    $fromCsv = analyseUpload(UploadedFile::fake()->createWithContent('customers.csv', $csv));

    expect($fromXlsx['headers'])->toBe($fromCsv['headers']);
    expect($fromXlsx['mapping'])->toBe($fromCsv['mapping']);
});

it('ignores every sheet after the first', function (): void {
    // A "راهنما" tab is not customer data, and concatenating it would import its cells
    // as people.
    $payload = analyseUpload(xlsxUpload([
        ['نام', 'شماره همراه'],
        ['علی رضایی', '09121112233'],
    ]));

    $this->actingAs($this->owner)
        ->postJson($this->url.'/crm/import/dry-run', [
            'token' => $payload['token'],
            'kind' => 'customer',
            'unit' => Money::UNIT_TOMAN,
            'mapping' => $payload['mapping'],
        ])
        ->assertOk()
        ->assertJsonPath('counts.'.PartyImporter::OUTCOME_CREATE, 1);
});
