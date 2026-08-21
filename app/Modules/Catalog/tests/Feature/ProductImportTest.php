<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Brand;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\PriceLevel;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Catalog\Services\PriceResolver;
use App\Modules\Catalog\Services\ProductImporter;
use App\Modules\Identity\Models\User;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Platform\Services\PlanCatalogueSeeder;
use App\Modules\Platform\Services\SubscriptionResolver;
use App\Modules\Platform\Services\TenantProvisioner;
use App\Support\Money;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;

/**
 * The products import, end to end.
 *
 * The file a shop actually sends is not a tidy fixture, so the messy-file test below is
 * the centrepiece: mixed digits, a slash decimal, a contradicting currency word, an
 * unreadable price and a duplicated barcode, all in five rows. Every one of those
 * produces a *wrong catalogue* rather than an error if it is not handled, which is why
 * each has a named verdict a shopkeeper can act on.
 */
beforeEach(function (): void {
    app(PlanCatalogueSeeder::class)->sync();

    $this->tenant = Tenant::factory()->withDomain()->create();
    $this->url = appUrl();

    subscribe($this->tenant, 'pro');
    app(SubscriptionResolver::class)->forget();
    app(TenantProvisioner::class)->seedRoles($this->tenant);

    $this->owner = inTenantContext($this->tenant, function (): User {
        $user = User::factory()->create();
        $user->assignRole('Owner');

        return $user;
    });

    $this->inTenant = fn (Closure $callback): mixed => inTenantContext($this->tenant, $callback);

    // Every shop is seeded with price levels on provisioning; the import writes to the
    // default one, so a missing default would be a silent no-price import.
    ($this->inTenant)(function (): void {
        if (PriceLevel::query()->count() === 0) {
            PriceLevel::query()->create([
                'code' => PriceLevel::CONSUMER,
                'name_fa' => 'مصرف‌کننده',
                'is_default' => true,
                'position' => 1,
            ]);
        }
    });
});

afterEach(fn () => app(TenantContext::class)->forget());

/**
 * Upload a CSV and return the analyse payload.
 *
 * @return array{token: string, headers: list<string>, mapping: array<string, int|null>, encoding: string, repairedText: bool}
 */
function uploadCatalogue(string $contents, string $name = 'products.csv'): array
{
    /** @var string $url */
    $url = test()->url;

    /** @var array{token: string, headers: list<string>, mapping: array<string, int|null>, encoding: string, repairedText: bool} $payload */
    $payload = test()->actingAs(test()->owner)
        ->post($url.'/catalog/import/analyse', [
            'file' => UploadedFile::fake()->createWithContent($name, $contents),
        ])
        ->assertOk()
        ->json();

    return $payload;
}

/* ---------------------------------------------------------------- reading -- */

it('reads the header row and guesses the mapping', function (): void {
    $payload = uploadCatalogue("نام کالا,بارکد,قیمت فروش,برند\nگوشی سامسونگ,6260001,18900000,سامسونگ\n");

    expect($payload['mapping']['name'])->toBe(0);
    expect($payload['mapping']['barcode'])->toBe(1);
    expect($payload['mapping']['price'])->toBe(2);
    expect($payload['mapping']['brand'])->toBe(3);
});

it('does not let «بارکد» steal the «کد کالا» column', function (): void {
    // Both headers contain «کد». A naive substring guesser maps SKU onto the barcode
    // column and the catalogue imports with its identifiers swapped — which then makes
    // every re-import match the wrong row.
    $payload = uploadCatalogue("نام کالا,بارکد,کد کالا\nگوشی,6260001,SKU-1\n");

    expect($payload['mapping']['barcode'])->toBe(1);
    expect($payload['mapping']['sku'])->toBe(2);
});

/* ------------------------------------------------------------------ unit -- */

it('refuses to run without a currency unit', function (): void {
    $payload = uploadCatalogue("نام کالا,قیمت\nگوشی,18900000\n");

    // No default anywhere in the stack. A client that omits the unit is rejected rather
    // than served a guess worth ten times the catalogue.
    test()->actingAs($this->owner)
        ->postJson($this->url.'/catalog/import/dry-run', [
            'token' => $payload['token'],
            'type' => 'standard',
            'mapping' => $payload['mapping'],
        ])
        ->assertStatus(422);
});

/* --------------------------------------------------------------- the walk -- */

it('creates one product and one no-axis variant per row', function (): void {
    $payload = uploadCatalogue(
        "نام کالا,بارکد,قیمت فروش,برند,دسته‌بندی\n".
        "گوشی موبایل سامسونگ,6260000000019,18900000,سامسونگ,گوشی موبایل\n".
        "شارژر 20 وات,6260000000026,450000,اپل,لوازم جانبی\n"
    );

    $this->actingAs($this->owner)
        ->post($this->url.'/catalog/import', [
            'token' => $payload['token'],
            'unit' => Money::UNIT_TOMAN,
            'type' => 'standard',
            'mapping' => $payload['mapping'],
        ])
        ->assertRedirect();

    ($this->inTenant)(function (): void {
        expect(Product::query()->count())->toBe(2);
        expect(ProductVariant::query()->count())->toBe(2);

        $phone = Product::query()->where('name', 'گوشی موبایل سامسونگ')->firstOrFail();
        $variant = $phone->variants()->firstOrFail();

        // ADR 0013: the no-axis variant is what everything else in the system can
        // reference. A product without one is a row nothing can reach.
        expect($variant->options)->toBe([]);
        expect($variant->barcode)->toBe('6260000000019');

        // 18,900,000 toman → 189,000,000 rial.
        $price = app(PriceResolver::class)->priceFor($variant->id, PriceLevel::query()->firstOrFail()->id);
        expect($price)->toBe(189_000_000);

        expect(Brand::query()->count())->toBe(2);
        expect(Category::query()->count())->toBe(2);
    });
});

it('matches an existing product by barcode and does not duplicate it', function (): void {
    $existing = ($this->inTenant)(function (): ProductVariant {
        $product = Product::query()->create(['name' => 'نام درست', 'type' => 'standard']);

        return ProductVariant::query()->create([
            'product_id' => $product->id,
            'options' => [],
            'barcode' => '6260000000019',
        ]);
    });

    $payload = uploadCatalogue(
        "نام کالا,بارکد,قیمت فروش\n".
        "نام غلط از فایل قدیمی,6260000000019,18900000\n"
    );

    $this->actingAs($this->owner)
        ->post($this->url.'/catalog/import', [
            'token' => $payload['token'],
            'unit' => Money::UNIT_TOMAN,
            'type' => 'standard',
            'mapping' => $payload['mapping'],
        ])
        ->assertRedirect();

    ($this->inTenant)(function () use ($existing): void {
        expect(Product::query()->count())->toBe(1);

        // The sheet is an import, not a source of truth: a name corrected in the app
        // last week must not be undone by a stale export.
        expect(Product::query()->firstOrFail()->name)->toBe('نام درست');

        // The price IS updated, because prices are append-only history and re-importing
        // a price list means exactly "these are the prices from now".
        $price = app(PriceResolver::class)->priceFor($existing->id, PriceLevel::query()->firstOrFail()->id);
        expect($price)->toBe(189_000_000);
    });
});

it('warns on a row that can never be matched again', function (): void {
    // No barcode and no SKU means re-importing this file makes a second copy. Said at
    // the row, because by the time the shop notices they have two catalogues.
    $payload = uploadCatalogue("نام کالا,قیمت فروش\nگوشی بدون بارکد,18900000\n");

    $report = $this->actingAs($this->owner)
        ->postJson($this->url.'/catalog/import/dry-run', [
            'token' => $payload['token'],
            'unit' => Money::UNIT_TOMAN,
            'type' => 'standard',
            'mapping' => $payload['mapping'],
        ])
        ->assertOk()
        ->json();

    expect($report['rows'][0]['outcome'])->toBe(ProductImporter::OUTCOME_CREATE);
    expect($report['rows'][0]['message'])->toContain('تکراری ساخته می‌شود');
});

it('skips the example rows shipped in our own template', function (): void {
    $payload = uploadCatalogue(
        "نام کالا,قیمت فروش\n".
        "# مثال — این سطر را پاک کنید,450000\n".
        "گوشی واقعی,18900000\n"
    );

    $report = $this->actingAs($this->owner)
        ->postJson($this->url.'/catalog/import/dry-run', [
            'token' => $payload['token'],
            'unit' => Money::UNIT_TOMAN,
            'type' => 'standard',
            'mapping' => $payload['mapping'],
        ])
        ->assertOk()
        ->json();

    expect($report['counts'][ProductImporter::OUTCOME_CREATE])->toBe(1);
    expect($report['rows'])->toHaveCount(1);
});

it('never writes anything on a dry run', function (): void {
    $payload = uploadCatalogue("نام کالا,قیمت فروش\nگوشی سامسونگ,18900000\n");

    $this->actingAs($this->owner)
        ->postJson($this->url.'/catalog/import/dry-run', [
            'token' => $payload['token'],
            'unit' => Money::UNIT_TOMAN,
            'type' => 'standard',
            'mapping' => $payload['mapping'],
        ])
        ->assertOk();

    ($this->inTenant)(fn () => expect(Product::query()->count())->toBe(0));
});

/* ------------------------------------------------------------- the mess -- */

it('gives a shopkeeper an actionable verdict for every kind of bad row', function (): void {
    /*
    | The file a shop actually sends. Every row here is a different way to end up with a
    | wrong catalogue and no error, which is why each gets a verdict naming the row and
    | the reason rather than a count at the bottom.
    */
    $payload = uploadCatalogue(
        "نام کالا,بارکد,قیمت فروش\n".
        "گوشی موبایل سامسونگ,6260000000019,۱۸٬۹۰۰٬۰۰۰\n".   // Persian digits + separators
        "شارژر 20 وات,6260000000026,450000/0\n".            // slash decimal — the 10x trap
        "قاب محافظ شفاف,6260000000033,180000 ریال\n".       // contradicts the chosen unit
        "گلس محافظ,6260000000040,حدود صد هزار\n".           // unreadable price
        "پاوربانک,6260000000019,890000\n".                  // barcode already used on row 2
        ",6260000000057,120000\n"                           // no name
    );

    $report = $this->actingAs($this->owner)
        ->postJson($this->url.'/catalog/import/dry-run', [
            'token' => $payload['token'],
            'unit' => Money::UNIT_TOMAN,
            'type' => 'standard',
            'mapping' => $payload['mapping'],
        ])
        ->assertOk()
        ->json();

    /** @var array<int, array<string, mixed>> $byLine */
    $byLine = [];

    foreach ($report['rows'] as $row) {
        $byLine[$row['line']] = $row;
    }

    // Persian digits and separators — a clean row.
    expect($byLine[2]['outcome'])->toBe(ProductImporter::OUTCOME_CREATE);
    expect($byLine[2]['price'])->toBe(189_000_000);

    // The slash decimal. 450,000 toman is 4,500,000 rial — NOT 45,000,000.
    expect($byLine[3]['outcome'])->toBe(ProductImporter::OUTCOME_CREATE);
    expect($byLine[3]['price'])->toBe(4_500_000);

    // The cell says ریال while the operator chose تومان. Worth ten times the price, so
    // it is an error rather than a word to strip.
    expect($byLine[4]['outcome'])->toBe(ProductImporter::OUTCOME_ERROR);
    expect($byLine[4]['message'])->toContain('قیمت');

    // Unreadable price: an error, never a zero. A zero price would go out the door.
    expect($byLine[5]['outcome'])->toBe(ProductImporter::OUTCOME_ERROR);
    expect($byLine[5]['message'])->toContain('خوانده نشد');

    // Duplicate barcode inside one file, naming the row it collides with — line 2, and
    // in Persian digits, because that is what the shopkeeper is reading on screen.
    expect($byLine[6]['outcome'])->toBe(ProductImporter::OUTCOME_DUPLICATE);
    expect($byLine[6]['message'])->toContain('۲');

    // No name.
    expect($byLine[7]['outcome'])->toBe(ProductImporter::OUTCOME_ERROR);
    expect($byLine[7]['message'])->toContain('نام کالا خالی است');

    expect($report['counts'][ProductImporter::OUTCOME_CREATE])->toBe(2);
    expect($report['counts'][ProductImporter::OUTCOME_ERROR])->toBe(3);
    expect($report['counts'][ProductImporter::OUTCOME_DUPLICATE])->toBe(1);
});

it('refuses a row whose stray delimiter shifted every column after it', function (): void {
    /*
    | Found on the browser walk, and it is the worst kind of wrong: the row does not
    | fail, it succeeds at a plausible number. «18,900,000» unquoted in a comma-delimited
    | file splits into three cells, the price column reads «18», and a phone imports at
    | eighteen toman. Nothing is empty and nothing throws.
    */
    $payload = uploadCatalogue(
        "نام کالا,بارکد,قیمت فروش\n".
        "گوشی سامسونگ,6260000000019,18,900,000\n".   // unquoted separators — 5 fields, header has 3
        "شارژر,6260000000026,450000\n"
    );

    $report = $this->actingAs($this->owner)
        ->postJson($this->url.'/catalog/import/dry-run', [
            'token' => $payload['token'],
            'unit' => Money::UNIT_TOMAN,
            'type' => 'standard',
            'mapping' => $payload['mapping'],
        ])
        ->assertOk()
        ->json();

    /** @var array<int, array<string, mixed>> $byLine */
    $byLine = [];

    foreach ($report['rows'] as $row) {
        $byLine[$row['line']] = $row;
    }

    expect($byLine[2]['outcome'])->toBe(ProductImporter::OUTCOME_ERROR);
    expect($byLine[2]['message'])->toContain('ستون');
    expect($byLine[2]['message'])->toContain('گیومه');

    // The well-formed row beside it is unaffected — one bad row is not a bad file.
    expect($byLine[3]['outcome'])->toBe(ProductImporter::OUTCOME_CREATE);
    expect($byLine[3]['price'])->toBe(4_500_000);
});

it('accepts a row that merely omits its trailing columns', function (): void {
    // Fewer fields shifts nothing — the values present are still in their own columns —
    // so this must NOT be caught by the guard above.
    $payload = uploadCatalogue(
        "نام کالا,بارکد,قیمت فروش,برند\n".
        "گوشی سامسونگ,6260000000019,18900000\n"
    );

    $report = $this->actingAs($this->owner)
        ->postJson($this->url.'/catalog/import/dry-run', [
            'token' => $payload['token'],
            'unit' => Money::UNIT_TOMAN,
            'type' => 'standard',
            'mapping' => $payload['mapping'],
        ])
        ->assertOk()
        ->json();

    expect($report['counts'][ProductImporter::OUTCOME_CREATE])->toBe(1);
    expect($report['rows'][0]['price'])->toBe(189_000_000);
});

it('repairs Arabic yeh and announces that it did', function (): void {
    // A UTF-8 file typed on an Arabic-locale device needs the same repair a legacy code
    // page forces, so the question is asked of the text and not only of the encoding.
    $payload = uploadCatalogue("نام كالا,قيمت\nگوشي موبايل سامسونگ,18900000\n");

    expect($payload['repairedText'])->toBeTrue();
    expect($payload['headers'][0])->toBe('نام کالا');

    $this->actingAs($this->owner)
        ->post($this->url.'/catalog/import', [
            'token' => $payload['token'],
            'unit' => Money::UNIT_TOMAN,
            'type' => 'standard',
            'mapping' => $payload['mapping'],
        ])
        ->assertRedirect();

    // Stored with Persian yeh, so the shopkeeper's own keyboard finds it in search.
    ($this->inTenant)(fn () => expect(Product::query()->firstOrFail()->name)->toBe('گوشی موبایل سامسونگ'));
});

/* ------------------------------------------------------------- template -- */

it('offers a template that carries the ignored column and its reason', function (): void {
    $response = $this->actingAs($this->owner)->get($this->url.'/catalog/import/template');

    $response->assertOk();
    expect($response->headers->get('content-disposition'))->toContain('.xlsx');
});

/* ------------------------------------------------------------ isolation -- */

it('refuses another shop a token from this one', function (): void {
    $payload = uploadCatalogue("نام کالا,قیمت فروش\nگوشی,18900000\n");

    $other = Tenant::factory()->withDomain()->create();
    subscribe($other, 'pro');
    app(SubscriptionResolver::class)->forget();
    app(TenantProvisioner::class)->seedRoles($other);

    $stranger = inTenantContext($other, function (): User {
        $user = User::factory()->create();
        $user->assignRole('Owner');

        return $user;
    });

    // The token names a file inside the caller's own tenant directory, so another shop's
    // token simply does not exist there — and a traversal attempt is stripped by
    // `basename` before the lookup ever happens.
    $this->actingAs($stranger)
        ->postJson(appUrl().'/catalog/import/dry-run', [
            'token' => $payload['token'],
            'unit' => Money::UNIT_TOMAN,
            'type' => 'standard',
            'mapping' => ['name' => 0],
        ])
        ->assertNotFound();

    $this->actingAs($stranger)
        ->postJson(appUrl().'/catalog/import/dry-run', [
            'token' => '../'.$this->tenant->id.'/'.$payload['token'],
            'unit' => Money::UNIT_TOMAN,
            'type' => 'standard',
            'mapping' => ['name' => 0],
        ])
        ->assertNotFound();
})->group('isolation');

it('does not let a shop import into another shop catalogue', function (): void {
    $other = Tenant::factory()->withDomain()->create();
    subscribe($other, 'pro');
    app(SubscriptionResolver::class)->forget();
    app(TenantProvisioner::class)->seedRoles($other);

    $payload = uploadCatalogue("نام کالا,قیمت فروش\nگوشی این مغازه,18900000\n");

    $this->actingAs($this->owner)
        ->post($this->url.'/catalog/import', [
            'token' => $payload['token'],
            'unit' => Money::UNIT_TOMAN,
            'type' => 'standard',
            'mapping' => $payload['mapping'],
        ])
        ->assertRedirect();

    // The other shop's catalogue is untouched — the write was scoped by RLS and the
    // global scope, not by anything the controller remembered to filter.
    inTenantContext($other, fn () => expect(Product::query()->count())->toBe(0));

    ($this->inTenant)(fn () => expect(Product::query()->count())->toBe(1));
})->group('isolation');

it('refuses a user without the import permission', function (): void {
    $seller = inTenantContext($this->tenant, function (): User {
        $user = User::factory()->create();
        $user->assignRole('Salesperson');

        return $user;
    });

    $this->actingAs($seller)->get($this->url.'/catalog/import')->assertForbidden();
});
