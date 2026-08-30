<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\PriceLevel;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Catalog\Services\ProductImporter;
use App\Modules\Identity\Models\User;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Platform\Services\SubscriptionResolver;
use App\Modules\Platform\Services\TenantProvisioner;
use App\Support\Money;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;

/**
 * The meter on the catalogue, at the two places a shopkeeper actually meets it.
 *
 * ## Why this file exists separately from the guard's own suite
 *
 * `Platform/tests/Feature/Quota/*` proves the guard is correct against a synthetic
 * `quota.widgets` metric — it counts, it refuses at the ceiling, it is atomic under
 * concurrency. None of those tests touch a route, deliberately, so that they break when
 * the guard breaks rather than when Catalog renames something. A guard that is perfect
 * and never called is indistinguishable, from the shop floor, from no guard at all, and
 * this file is the other half: that the real endpoints refuse, that the refusal reaches
 * the operator as something a React component can render, and that nothing is written on
 * the way.
 *
 * ## Catalog spends one credit two ways round, and the difference matters
 *
 * `POST /catalog/products` consumes **before** it writes: `ProductController::store()`
 * opens a transaction, takes the credit, then creates the row.
 *
 * `POST /catalog/import` consumes **after**: `ProductImporter::import()` cannot know how
 * many products are new until it has walked the file, so the whole walk runs — creating
 * rows as it goes — and the batch credit is taken as the last statement in the same
 * transaction. That inversion is the interesting part. It means the import proves the
 * pairing from the far side: the products are already written when the guard says no, and
 * the only thing that keeps a refused import from leaving half a catalogue behind is that
 * the refusal unwinds the transaction those writes are sitting in. The ceiling test below
 * is what checks that, and it is the strongest rollback claim in this file.
 *
 * ## The dry run is a question, not a write
 *
 * `POST /catalog/import/dry-run` walks the identical code with `commit: false` and must
 * cost nothing — including for a shop that has already run out. Charging a shop to *look*
 * at its own spreadsheet would teach it to stop checking and import blind, which is
 * exactly the habit the wizard exists to break.
 */
beforeEach(function (): void {
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

    // Every shop is seeded with price levels on provisioning; the importer writes prices
    // to the default one and refuses outright when there is none, so a missing default
    // would turn every import test in this file into a failure about the fixture rather
    // than about the meter.
    inTenantContext($this->tenant, function (): void {
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
 * One product through the counter form.
 *
 * Deliberately asserts nothing about the response — every test here wants to say
 * something different about it, and a helper that asserted success could not be used by
 * the tests about refusal.
 *
 * @return Illuminate\Testing\TestResponse<Illuminate\Http\Response>
 */
function createOneProduct(string $name = 'کابل شارژ'): Illuminate\Testing\TestResponse
{
    /** @var User $owner */
    $owner = test()->owner;
    /** @var string $url */
    $url = test()->url;

    return test()->actingAs($owner)->post($url.'/catalog/products', [
        'name' => $name,
        'type' => 'standard',
    ]);
}

function productCount(): int
{
    /** @var Tenant $tenant */
    $tenant = test()->tenant;

    /** @var int $count */
    $count = inTenantContext($tenant, fn (): int => Product::query()->count());

    return $count;
}

/**
 * Step one of the wizard: hand over a sheet, get back a token and a column mapping.
 *
 * The token is how the later steps name the file — the client never sends a path, because
 * a client that chooses which file the server reads is a client that can read any file
 * the server can.
 *
 * @return array{token: string, mapping: array<string, int|null>}
 */
function uploadPriceSheet(string $contents): array
{
    /** @var User $owner */
    $owner = test()->owner;
    /** @var string $url */
    $url = test()->url;

    /** @var array{token: string, mapping: array<string, int|null>} $payload */
    $payload = test()->actingAs($owner)
        ->post($url.'/catalog/import/analyse', [
            'file' => UploadedFile::fake()->createWithContent('products.csv', $contents),
        ])
        ->assertOk()
        ->json();

    return $payload;
}

/**
 * A sheet of `$rows` products nothing in the catalogue matches yet.
 */
function newProductSheet(int $rows): string
{
    $sheet = "نام کالا,بارکد,قیمت فروش\n";

    for ($i = 1; $i <= $rows; $i++) {
        $sheet .= 'قاب محافظ شمارهٔ '.$i.',626000000'.str_pad((string) $i, 4, '0', STR_PAD_LEFT).",450000\n";
    }

    return $sheet;
}

/**
 * @param  array{token: string, mapping: array<string, int|null>}  $upload
 * @return Illuminate\Testing\TestResponse<Illuminate\Http\Response>
 */
function importPriceSheet(array $upload): Illuminate\Testing\TestResponse
{
    /** @var User $owner */
    $owner = test()->owner;
    /** @var string $url */
    $url = test()->url;

    return test()->actingAs($owner)->post($url.'/catalog/import', [
        'token' => $upload['token'],
        'unit' => Money::UNIT_RIAL,
        'type' => 'standard',
        'mapping' => $upload['mapping'],
    ]);
}

/**
 * @param  array{token: string, mapping: array<string, int|null>}  $upload
 * @return Illuminate\Testing\TestResponse<Illuminate\Http\Response>
 */
function dryRunPriceSheet(array $upload): Illuminate\Testing\TestResponse
{
    /** @var User $owner */
    $owner = test()->owner;
    /** @var string $url */
    $url = test()->url;

    return test()->actingAs($owner)->postJson($url.'/catalog/import/dry-run', [
        'token' => $upload['token'],
        'unit' => Money::UNIT_RIAL,
        'type' => 'standard',
        'mapping' => $upload['mapping'],
    ]);
}

/* ------------------------------------------------------------- happy path -- */

it('spends one product credit for one product added at the counter', function (): void {
    createOneProduct()->assertSessionHasNoErrors()->assertRedirect();

    expect(quotaUsed($this->tenant, 'catalog.products'))->toBe(1)
        ->and(productCount())->toBe(1);
});

it('spends one credit per created row, in a single batched consume', function (): void {
    importPriceSheet(uploadPriceSheet(newProductSheet(3)))
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    expect(quotaUsed($this->tenant, 'catalog.products'))->toBe(3)
        ->and(productCount())->toBe(3);

    // That this is ONE spend of three rather than three of one is not visible in the
    // counter — both land as `used = 3`. It is visible in the refusal, where the block
    // payload carries `requested: 3`, and a per-row consume could only ever report one.
    // The ceiling test below is where that claim is actually made.
});

/* ---------------------------------------------------------- at the ceiling -- */

it('refuses the product that would cross the ceiling, and writes no row', function (): void {
    capQuota($this->tenant, 'catalog.products', 1);

    createOneProduct('کابل شارژ')->assertSessionHasNoErrors();
    expect(productCount())->toBe(1);

    // The second one is the whole test. A shop at its cap must be *told*, not silently
    // handed a form that does nothing — see CLAUDE.md on the operator pressing submit
    // twice with a customer at the counter.
    createOneProduct('محافظ صفحه')->assertSessionHasErrors('quota');

    expect(productCount())->toBe(1)
        ->and(quotaUsed($this->tenant, 'catalog.products'))->toBe(1);
});

it('refuses a whole import rather than the part of it that would fit', function (): void {
    // Ten credits, eight already spent this month, and a five-row file arriving. Two of
    // them fit.
    capQuota($this->tenant, 'catalog.products', 10);
    spendQuota($this->tenant, 'catalog.products', 8);

    importPriceSheet(uploadPriceSheet(newProductSheet(5)))->assertSessionHasErrors('quota');

    // Two things are being claimed at once, and the second is the one only this module
    // can make. First: the batch is refused whole — `DatabaseQuotaGuard`'s bounded
    // statement guards its update arm with `used + EXCLUDED.used <= limit`, so a spend
    // either lands entirely or returns no row, and nothing takes the two that fit.
    expect(quotaUsed($this->tenant, 'catalog.products'))->toBe(8)
        // Second: the five products had ALREADY been created by the walk when the credit
        // was refused — the importer cannot count new rows until it has written them —
        // and they are gone. This is the pairing proved from the far side, and the only
        // thing standing between a refused import and half a catalogue nobody can tell
        // the halves of.
        ->and(productCount())->toBe(0);

    inTenantContext($this->tenant, function (): void {
        expect(ProductVariant::query()->count())->toBe(0);
    });
});

it('hands the operator something to render, not just an error string', function (): void {
    capQuota($this->tenant, 'catalog.products', 0);

    createOneProduct();

    /** @var array<string, mixed> $block */
    $block = session('quota_block') ?? [];

    // These are the keys `quota-block.tsx` reads. A refusal that reached the browser
    // without them would render an empty card, which is worse than a 500 because nobody
    // would report it.
    expect($block)->toHaveKeys(['metric', 'label', 'message', 'used', 'limit', 'resets_at', 'next_plan'])
        ->and($block['metric'])->toBe('catalog.products')
        // Persian, not the exception's English. `QuotaExceeded` stopped extending
        // `RuntimeException` precisely because a dozen controllers catch that class and
        // turn it into a field message carrying the raw `Quota exceeded for [...]` string.
        ->and($block['message'])->toBeString()->not->toContain('Quota exceeded')
        ->and($block['next_plan'])->toBeArray();

    /** @var array{code?: string} $next */
    $next = $block['next_plan'];
    // The cheapest rung that clears the wall the shop just hit, not simply the next one
    // up: aiming a shop at a plan that would block it again tomorrow is how an upsell
    // becomes a refund.
    expect($next['code'] ?? null)->toBe('enterprise');
});

it('tells a blocked import how much of the file would fit', function (): void {
    capQuota($this->tenant, 'catalog.products', 2);

    importPriceSheet(uploadPriceSheet(newProductSheet(5)))->assertSessionHasErrors('quota');

    /** @var array<string, mixed> $block */
    $block = session('quota_block') ?? [];

    // A bulk refusal is a different sentence from a full credit. «سهمیه‌ات تمام شد» is
    // not actionable to somebody holding a five-hundred-row spreadsheet; "you asked for
    // five and have two left" tells them how much of the file to split off.
    //
    // `requested` reading 5 is also the only external evidence that the importer spends
    // its credits as one batch: five separate consumes could each only ever report 1.
    expect($block['metric'])->toBe('catalog.products')
        ->and($block['requested'] ?? null)->toBe(5)
        ->and($block['limit'] ?? null)->toBe(2)
        ->and($block['message'])->toBeString()->not->toContain('Quota exceeded');

    /** @var string $message */
    $message = $block['message'];
    // Persian digits, because every number on every screen in this product is Persian and
    // a Latin «5» inside a Persian sentence is the tell that a string was assembled
    // somewhere that forgot.
    expect($message)->toContain('۵')->toContain('۲');
});

/* ------------------------------------------------------- the dry run is free -- */

it('charges nothing for a dry run', function (): void {
    $upload = uploadPriceSheet(newProductSheet(4));

    dryRunPriceSheet($upload)
        ->assertOk()
        ->assertJsonPath('counts.'.ProductImporter::OUTCOME_CREATE, 4);

    // Four products were counted and none were created, so nothing was owed. The absent
    // row — rather than a row reading zero — is what says `consume()` was never reached
    // on this path at all.
    expect(quotaRowExists($this->tenant, 'catalog.products'))->toBeFalse()
        ->and(productCount())->toBe(0);
});

it('lets a shop with nothing left still check its own file', function (): void {
    capQuota($this->tenant, 'catalog.products', 0);

    $upload = uploadPriceSheet(newProductSheet(4));

    // Reads are never blocked (golden rule 7), and a dry run is a read that happens to
    // walk a spreadsheet. A shop that had to buy credits before it could find out its
    // price column was mis-mapped would learn to skip the check and import blind — which
    // is the failure this whole wizard exists to prevent.
    dryRunPriceSheet($upload)
        ->assertOk()
        ->assertJsonPath('counts.'.ProductImporter::OUTCOME_CREATE, 4);

    expect(quotaRowExists($this->tenant, 'catalog.products'))->toBeFalse();

    // And the answer it gave was true: the same file, committed, is what gets refused.
    importPriceSheet($upload)->assertSessionHasErrors('quota');
});

/* ----------------------------------------------------- failing for itself -- */

it('spends nothing when the create is rejected before it reaches the guard', function (): void {
    /** @var User $owner */
    $owner = $this->owner;

    // A category that does not exist — a stale option on a screen somebody left open, or
    // another shop's id typed into the request by hand. `ProductRequest` rejects it, so
    // the controller's transaction never opens.
    //
    // This is the honest version of "a write that fails for its own reasons spends
    // nothing" for this endpoint. There is no post-consume failure to stage here: the
    // transaction contains `consume()` and a single `Product::create()` whose every
    // column is already covered by validation, and `products` carries no unique index for
    // a duplicate to violate. What this does say is worth saying — the guard sits inside
    // the controller action rather than in middleware, so a request the validator turns
    // away costs the shop nothing. The rollback claim the exemplar makes with a sold
    // handset is made instead by the import ceiling test above, from the other side.
    $this->actingAs($owner)->post($this->url.'/catalog/products', [
        'name' => 'کابل شارژ',
        'type' => 'standard',
        'category_id' => 987_654_321,
    ])->assertSessionHasErrors('category_id');

    expect(productCount())->toBe(0)
        // No row at all, rather than a row reading zero: the two are different claims and
        // only the first one says the guard was never called.
        ->and(quotaRowExists($this->tenant, 'catalog.products'))->toBeFalse();
});

it('spends nothing for an import that creates nothing', function (): void {
    // The catalogue already has this barcode, so re-importing the shop's price list is
    // an update pass: every row matches, `created` is zero, and `import()` skips the
    // consume entirely.
    inTenantContext($this->tenant, function (): void {
        $product = Product::factory()->create(['name' => 'قاب محافظ شمارهٔ ۱']);
        // The barcode `newProductSheet()` puts on its first row. Matching on barcode is
        // the top rung of the importer's ladder (ADR 0013), and it is how a shop's
        // re-export of its own catalogue lands as updates rather than as a second copy.
        ProductVariant::factory()->for($product)->create(['barcode' => '6260000000001']);
    });

    importPriceSheet(uploadPriceSheet(newProductSheet(1)))
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    // `catalog.products` counts NEW lines in the catalogue. A shop that re-imports its
    // price list every Saturday to refresh prices is doing the thing this product wants
    // it to do, and charging it a month's credit for touching rows it already owns would
    // teach it to stop.
    expect(productCount())->toBe(1)
        ->and(quotaRowExists($this->tenant, 'catalog.products'))->toBeFalse();
});
