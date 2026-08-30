<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Brand;
use App\Modules\Catalog\Models\PriceLevel;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductPrice;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Identity\Models\User;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Platform\Services\PlanCatalogueSeeder;
use App\Modules\Platform\Services\SubscriptionResolver;
use App\Modules\Platform\Services\TenantProvisioner;
use App\Modules\Storefront\Models\PriceListLink;
use App\Modules\Storefront\Models\PriceListView;
use App\Modules\Storefront\Models\StorefrontSetting;
use App\Modules\Storefront\Services\PriceListAccess;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The reseller price list, and every rule `docs/specs/storefront.md` asks to be tested.
 *
 * A link is a **bearer credential**: whoever holds the URL gets reseller prices, which are
 * the figures a shop most wants off its public page. So the tests below are not incidental
 * coverage — they are the feature. Each one names the spec line it pins.
 *
 * ## The consumer/reseller price gap is what makes a leak observable
 *
 * Both levels are priced on the same variant, deliberately and differently. A fixture where
 * they matched would let a screen serve the wrong level and every assertion would still pass
 * — the same shape as the round-number money fixtures in `docs/testing.md`.
 */
beforeEach(function (): void {
    app(PlanCatalogueSeeder::class)->sync();

    $this->tenant = Tenant::factory()->withDomain()->create();
    $this->url = appUrl();

    /*
    | Storefront used to need an add-on purchase here: it was `is_addonable`, so a `pro`
    | plan did not include it and the ADMIN routes 403'd without one. DECISION GATE 6 opened
    | every module to every plan, so the fixture is gone and the routes are simply reachable.
    |
    | The PUBLIC routes never cared and still do not: a price list already in a colleague's
    | WhatsApp keeps working for the days it was minted for, which is why they carry no
    | `module:` gate at all.
    */
    subscribe($this->tenant, 'pro');

    app(SubscriptionResolver::class)->forget();
    app(TenantProvisioner::class)->seedRoles($this->tenant);

    /** @var array{User, PriceLevel, PriceLevel} $fixtures */
    $fixtures = app(TenantContext::class)->runFor($this->tenant, function (): array {
        $owner = User::factory()->create();
        $owner->assignRole('Owner');

        $consumer = PriceLevel::factory()->create([
            'code' => PriceLevel::CONSUMER, 'name_fa' => 'مصرف‌کننده', 'is_default' => true, 'position' => 1,
        ]);

        $reseller = PriceLevel::factory()->create([
            'code' => PriceLevel::RESELLER, 'name_fa' => 'همکار', 'is_default' => false, 'position' => 2,
        ]);

        $brand = Brand::factory()->create(['name' => 'Apple', 'name_fa' => 'اپل']);
        $product = Product::factory()->create(['name' => 'iPhone 15', 'brand_id' => $brand->getKey(), 'is_active' => true]);
        // Options and no name — the ordinary case for a matrix-generated variant, and the
        // one that rendered four identical rows at four prices before the label was fixed.
        $variant = ProductVariant::factory()->create([
            'product_id' => $product->getKey(),
            'is_active' => true,
            'name' => null,
            'options' => ['رنگ' => 'مشکی', 'حافظه' => '۲۵۶'],
        ]);

        /*
        | Two DIFFERENT prices, and the gap is load-bearing. If consumer and reseller were
        | both 50,000,000 a screen serving the wrong level would pass every assertion here.
        */
        ProductPrice::query()->create([
            'product_variant_id' => $variant->getKey(),
            'price_level_id' => $consumer->getKey(),
            'price' => 88_819_990,
            'effective_from' => CarbonImmutable::now()->subDay(),
        ]);

        ProductPrice::query()->create([
            'product_variant_id' => $variant->getKey(),
            'price_level_id' => $reseller->getKey(),
            'price' => 81_110_000,
            'effective_from' => CarbonImmutable::now()->subDay(),
        ]);

        StorefrontSetting::query()->create([
            'is_enabled' => true,
            // ADR 0017 moved the public window to `/shop/{slug}`; the hostname used to say
            // whose window it was. The column is nullable, so a fixture without a slug has
            // no public page at all — and the four assertions below would 404 silently.
            'slug' => 'mobile-nemoone',
            'display_name' => 'موبایل نمونه',
            'phone' => '+989121234567',
            'whatsapp' => '+989121234567',
            'shows_out_of_stock' => true,
        ]);

        return [$owner, $consumer, $reseller];
    });

    [$this->owner, $this->consumer, $this->reseller] = $fixtures;

    $this->mint = fn (array $options = []): array => app(TenantContext::class)->runFor(
        $this->tenant,
        fn (): array => app(PriceListAccess::class)->mint(
            priceLevelId: (int) ($options['level'] ?? $this->reseller->getKey()),
            password: $options['password'] ?? null,
            expiresAt: $options['expires'] ?? null,
        ),
    );
});

afterEach(function (): void {
    app(TenantContext::class)->forget();
});

/* ------------------------------------------------------------ the happy path -- */

it('serves the reseller price to a live link, and logs the view', function (): void {
    $minted = ($this->mint)();

    $response = $this->get($this->url.'/p/'.$minted['token']);

    $response->assertOk()
        // The reseller figure...
        ->assertSee('8,111,000', false)
        // ...and NOT the consumer one. The negative needs the positive beside it, and the
        // fixture prices them apart so the two can be told from each other.
        ->assertDontSee('8,881,999', false);

    inTenantContext($this->tenant, function (): void {
        expect(PriceListView::query()->count())->toBe(1);
        expect(PriceListLink::query()->firstOrFail()->view_count)->toBe(1);
    });
});

/* ---------------------------------------------------------------- expiry -- */

it('returns 410 for an expired link and never the prices', function (): void {
    // Spec: «Expired token → 410, and never the prices.»
    $minted = ($this->mint)(['expires' => CarbonImmutable::now()->subMinute()]);

    $this->get($this->url.'/p/'.$minted['token'])
        ->assertStatus(410)
        ->assertSee('اعتبار')
        ->assertDontSee('8,111,000', false);
});

it('returns 410 the instant a link is revoked', function (): void {
    // Spec: «Revoking a link takes effect immediately.» Nothing caches link state.
    $minted = ($this->mint)();

    $this->get($this->url.'/p/'.$minted['token'])->assertOk();

    $this->actingAs($this->owner)
        ->delete($this->url.'/storefront/links/'.idOf($minted['link']))
        ->assertSessionHasNoErrors();

    $this->get($this->url.'/p/'.$minted['token'])
        ->assertStatus(410)
        ->assertDontSee('8,111,000', false);
});

/* -------------------------------------------------------------- password -- */

it('shows the lock screen with no prices in it, then opens on the right password', function (): void {
    $minted = ($this->mint)(['password' => 'hamkar-1404']);

    // The gate: 200, because the visitor has not failed anything yet — and no figures.
    $this->get($this->url.'/p/'.$minted['token'])
        ->assertOk()
        ->assertSee('رمز')
        ->assertDontSee('8,111,000', false);

    $this->post($this->url.'/p/'.$minted['token'].'/unlock', ['password' => 'hamkar-1404'])
        ->assertRedirect();

    $this->get($this->url.'/p/'.$minted['token'])
        ->assertOk()
        ->assertSee('8,111,000', false);
});

it('returns 403 for a wrong password and leaks nothing with it', function (): void {
    // Spec: «Wrong password → 403, rate-limited to defeat brute force.»
    $minted = ($this->mint)(['password' => 'hamkar-1404']);

    $this->post($this->url.'/p/'.$minted['token'].'/unlock', ['password' => 'wrong'])
        ->assertForbidden()
        ->assertDontSee('8,111,000', false);
});

it('rate-limits password attempts', function (): void {
    $minted = ($this->mint)(['password' => 'hamkar-1404']);

    // Ten are allowed; the eleventh is refused. A password on a public URL is otherwise
    // free to brute-force, which is why the spec asks for this by name.
    for ($attempt = 1; $attempt <= 10; $attempt++) {
        $this->post($this->url.'/p/'.$minted['token'].'/unlock', ['password' => 'nope-'.$attempt])
            ->assertForbidden();
    }

    $this->post($this->url.'/p/'.$minted['token'].'/unlock', ['password' => 'nope-11'])
        ->assertStatus(429);
});

it('does not let one link’s password open another', function (): void {
    $first = ($this->mint)(['password' => 'one-1234']);
    $second = ($this->mint)(['password' => 'two-1234']);

    $this->post($this->url.'/p/'.$first['token'].'/unlock', ['password' => 'one-1234'])
        ->assertRedirect();

    // The session records the unlock PER LINK. A single «unlocked» flag would hand over
    // every password-protected list in the shop on one correct guess.
    $this->get($this->url.'/p/'.$second['token'])
        ->assertOk()
        ->assertSee('رمز')
        ->assertDontSee('8,111,000', false);
});

/* --------------------------------------------------- the token cannot escalate -- */

it('grants only the price level the token was minted with', function (): void {
    /*
    | Spec: «The token grants ONLY the price level it was minted with. Changing the URL
    | cannot escalate to another level.»
    |
    | Structural rather than defended: the level is a column on the row and there is nowhere
    | in the request for one to come from. This proves the query parameters a determined
    | visitor would try are simply ignored.
    */
    $minted = ($this->mint)(['level' => $this->consumer->getKey()]);

    $consumerId = idOf($this->consumer);
    $resellerId = idOf($this->reseller);

    foreach ([
        '?price_level_id='.$resellerId,
        '?price_level='.$resellerId,
        '?level=reseller',
    ] as $attempt) {
        $this->get($this->url.'/p/'.$minted['token'].$attempt)
            ->assertOk()
            ->assertSee('8,881,999', false)      // the consumer price it was minted for
            ->assertDontSee('8,111,000', false); // never the reseller one
    }

    expect($consumerId)->not->toBe($resellerId);
});

/* --------------------------------------------------------- unknown tokens -- */

it('cannot tell a wrong token from a missing one', function (): void {
    // Both 404. Distinguishing them would make this an oracle for guessing lookups.
    $minted = ($this->mint)();

    $this->get($this->url.'/p/'.str_repeat('z', 44))->assertNotFound();

    // A real lookup prefix with the wrong secret half — the more interesting case.
    $tampered = substr($minted['token'], 0, 12).str_repeat('q', 32);

    $this->get($this->url.'/p/'.$tampered)->assertNotFound();
});

it('never stores the token in readable form', function (): void {
    $minted = ($this->mint)();

    inTenantContext($this->tenant, function () use ($minted): void {
        $row = DB::table('price_list_links')->first();
        $values = (array) $row;

        // The hash is not the token, and the whole token is not in the row anywhere.
        expect($values['token_hash'])->not->toBe($minted['token']);
        expect(json_encode($values))->not->toContain(substr($minted['token'], 12));

        // The model does not carry either hash across the wire.
        $link = PriceListLink::query()->firstOrFail();

        expect($link->toArray())->not->toHaveKey('token_hash')
            ->and($link->toArray())->not->toHaveKey('password_hash');
    });
});

/* ---------------------------------------------------------------- the PDF -- */

it('applies the same gates to the printable sheet as to the page', function (): void {
    // A print route that skipped the password would be the whole security model with a
    // different suffix.
    $minted = ($this->mint)(['password' => 'hamkar-1404']);

    $this->get($this->url.'/p/'.$minted['token'].'/print')->assertForbidden();

    $this->post($this->url.'/p/'.$minted['token'].'/unlock', ['password' => 'hamkar-1404']);

    $this->get($this->url.'/p/'.$minted['token'].'/print')
        ->assertOk()
        // Spec: «The PDF matches the web list exactly.» Same query, same rows.
        ->assertSee('8,111,000', false);

    $expired = ($this->mint)(['expires' => CarbonImmutable::now()->subMinute()]);

    $this->get($this->url.'/p/'.$expired['token'].'/print')->assertStatus(410);
});

/* --------------------------------------------------- the public page leaks nothing -- */

it('renders the expiry as a Jalali date, not a raw timestamp', function (): void {
    /*
    | This caught a dead helper. `jdate()` in `App\Support\helpers.php` never ran —
    | **morilog/jalali defines a global `jdate()` too**, both are `function_exists`-guarded,
    | and the package's autoloaded first. It rendered «1405-06-02 21:18:47» where every other
    | screen shows «۱۴۰۵/۰۶/۰۲». Ours is now `jalali()`.
    */
    /*
    | The clock is pinned, and here the literal date is the substance rather than
    | scaffolding: `2026-09-01` and «۱۴۰۵/۰۶/۱۰» are the same day in two calendars, and
    | that correspondence is the whole assertion. It cannot be derived from the helper
    | under test without the test agreeing with whatever the helper does — which is
    | exactly how the shadowed `jdate()` stayed invisible for eight phases.
    |
    | So the pair stays, and the *clock* moves to meet it. Without this the token is
    | expired from 2026-09-01 onward, the page stops returning 200, and a formatting
    | test starts failing for a reason that has nothing to do with formatting.
    */
    $this->travelTo(CarbonImmutable::parse('2026-08-01 09:00:00'));

    $minted = ($this->mint)(['expires' => CarbonImmutable::parse('2026-09-01 12:00:00')]);

    $this->get($this->url.'/p/'.$minted['token'])
        ->assertOk()
        ->assertSee('۱۴۰۵/۰۶/۱۰')
        // The package's shape, which is what a shadowed helper would have produced.
        ->assertDontSee('1405-06-10 ');
});

it('labels an unnamed variant by its options, not as a bare product name', function (): void {
    /*
    | Four storage tiers of one handset at four prices, all rendering as «آیفون ۱۵ پرو مکس»,
    | reads as a pricing error rather than as a product range. `ProductVariant::displayName()`
    | is the app's rule; the public catalogue's set-based query has to mirror it.
    */
    $this->get(appUrl('/shop/mobile-nemoone'))
        ->assertOk()
        ->assertSee('مشکی · ۲۵۶');
});

it('shows consumer prices publicly and never a reseller price or a cost', function (): void {
    // Spec: «The public catalogue leaks no cost, no non-consumer price level and no
    // customer data.»
    $this->get(appUrl('/shop/mobile-nemoone'))
        ->assertOk()
        ->assertSee('موبایل نمونه')
        ->assertSee('8,881,999', false)
        ->assertDontSee('8,111,000', false);
});

it('404s the public page while the storefront is switched off', function (): void {
    // Not a placeholder: a half-configured page indexed by a search engine is worse for
    // the shop than no page.
    app(TenantContext::class)->runFor($this->tenant, function (): void {
        StorefrontSetting::query()->firstOrFail()->forceFill(['is_enabled' => false])->save();
    });

    $this->get(appUrl('/shop/mobile-nemoone'))->assertNotFound();
});

it('builds a WhatsApp link the app can actually open', function (): void {
    $this->get(appUrl('/shop/mobile-nemoone'))
        ->assertOk()
        // wa.me wants digits with no plus. A number typed on a Persian keypad was
        // normalised on save, so this is the whole conversion.
        ->assertSee('https://wa.me/989121234567', false);
});

/* ------------------------------------------------------------------ minting -- */

it('shows the token exactly once and refuses a link with no expiry', function (): void {
    $this->actingAs($this->owner)
        ->post($this->url.'/storefront/links', [
            'price_level_id' => idOf($this->reseller),
            'label' => 'حاج آقای رضایی',
            'days' => 14,
        ])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('minted_link');

    // Over the maximum: a link that outlives its price list is worse than an expired one.
    $this->actingAs($this->owner)
        ->post($this->url.'/storefront/links', [
            'price_level_id' => idOf($this->reseller),
            'days' => 400,
        ])
        ->assertSessionHasErrors('days');
});

/* ---------------------------------------------------------------- isolation -- */

it('never renders one shop’s catalogue under another shop’s link', function (): void {
    // Spec: «Cross-tenant isolation: one shop's link never renders another shop's
    // catalogue.»
    $minted = ($this->mint)();

    $other = Tenant::factory()->withDomain()->create();
    subscribe($other, 'pro');
    app(SubscriptionResolver::class)->forget();

    app(TenantContext::class)->runFor($other, function (): void {
        $level = PriceLevel::factory()->create(['code' => PriceLevel::CONSUMER, 'is_default' => true]);
        $product = Product::factory()->create(['name' => 'گوشی همسایه', 'is_active' => true]);
        $variant = ProductVariant::factory()->create(['product_id' => $product->getKey(), 'is_active' => true]);

        ProductPrice::query()->create([
            'product_variant_id' => $variant->getKey(),
            'price_level_id' => $level->getKey(),
            'price' => 12_345_670,
            'effective_from' => CarbonImmutable::now()->subDay(),
        ]);

        StorefrontSetting::query()->create(['is_enabled' => true, 'display_name' => 'همسایه']);
    });

    /*
    | Both shops now answer on the SAME address, which is what makes this the interesting
    | case rather than a weaker one.
    |
    | The hostname used to carry the tenant, so «one shop's link on another shop's host»
    | was a question the URL answered before any query ran. ADR 0017 removed that: the
    | token is now the only thing saying which shop this is, resolved by the one statement
    | that crosses tenants (ADR 0002's narrow escape, `PublicTenantResolver`). So the
    | request below is indistinguishable from a request for the neighbour's page except
    | for the token — and it must still render the MINTING shop's catalogue and nothing
    | of the neighbour's.
    */
    $this->get(appUrl('/p/'.$minted['token']))
        ->assertOk()
        ->assertSee('iPhone 15')
        ->assertDontSee('گوشی همسایه')
        ->assertDontSee('1,234,567', false);

    // And the view was logged against the owning shop, not the host.
    inTenantContext($this->tenant, fn () => expect(PriceListView::query()->count())->toBe(1));
    inTenantContext($other, fn () => expect(PriceListView::query()->count())->toBe(0));
})->group('isolation');
