<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Brand;
use App\Modules\Catalog\Models\PriceLevel;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductPrice;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Storefront\Models\StorefrontSetting;
use App\Modules\Storefront\Services\PriceListAccess;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;

/**
 * The Storefront templates, rendered and read.
 *
 * ## Why this file exists
 *
 * 1,189 tests were green and **four identical «آیفون ۱۵ پرو مکس» rows at four different
 * prices still shipped.** So did a date helper that had been dead for eight phases. Both
 * were found by opening a page in a browser, and neither was found by the suite — because
 * nothing in the suite rendered a page and read the output. Every existing test asserted on
 * the data *behind* the template.
 *
 * That is the gap this closes, and the assertions below are deliberately about **rendered
 * output**: what a visitor's browser receives, parsed back out of the response.
 *
 * ## Structural, not literal
 *
 * The most valuable assertion here does not know what the labels should say. It asserts
 * that **N distinct variants render N distinct rows** — which is true of any correct
 * catalogue and false of the bug, without anybody having to predict «تیتانیوم مشکی · ۵۱۲».
 * A test asserting the exact expected strings would have had to be written by somebody who
 * already knew the answer, which is precisely the person who did not write the bug.
 *
 * The same reasoning drives the date and the money assertions: they check the *shape* a
 * rendered value must have, so they catch a whole class of formatter regressions rather
 * than one known string.
 */
beforeEach(function (): void {
    $this->tenant = Tenant::factory()->withDomain()->create();
    $this->url = appUrl();

    /** @var array{PriceLevel, PriceLevel} $levels */
    $levels = app(TenantContext::class)->runFor($this->tenant, function (): array {
        $consumer = PriceLevel::factory()->create([
            'code' => PriceLevel::CONSUMER, 'name_fa' => 'مصرف‌کننده', 'is_default' => true, 'position' => 1,
        ]);

        $reseller = PriceLevel::factory()->create([
            'code' => PriceLevel::RESELLER, 'name_fa' => 'همکار', 'is_default' => false, 'position' => 2,
        ]);

        $brand = Brand::factory()->create(['name' => 'Apple', 'name_fa' => 'اپل']);

        $product = Product::factory()->create([
            'name' => 'آیفون ۱۵ پرو مکس', 'brand_id' => $brand->getKey(), 'is_active' => true,
        ]);

        /*
        | FOUR variants of one product: null `name`, distinct `options`, distinct prices.
        |
        | This is the exact fixture the shipped bug needed and never had. A shop that
        | generates a colour/storage matrix names none of them, so the label has to come
        | from the JSON — and when it did not, all four rendered identically.
        */
        $matrix = [
            ['رنگ' => 'تیتانیوم طبیعی', 'حافظه' => '۲۵۶'],
            ['رنگ' => 'تیتانیوم مشکی', 'حافظه' => '۲۵۶'],
            ['رنگ' => 'تیتانیوم طبیعی', 'حافظه' => '۵۱۲'],
            ['رنگ' => 'تیتانیوم مشکی', 'حافظه' => '۵۱۲'],
        ];

        foreach ($matrix as $index => $options) {
            $variant = ProductVariant::factory()->create([
                'product_id' => $product->getKey(),
                'is_active' => true,
                'name' => null,
                'options' => $options,
            ]);

            // Non-round, and different per row — so a template that repeated one row's
            // price would be as visible as one that repeated its label.
            foreach ([[$consumer, 88_819_990], [$reseller, 81_110_000]] as [$level, $base]) {
                ProductPrice::query()->create([
                    'product_variant_id' => $variant->getKey(),
                    'price_level_id' => $level->getKey(),
                    'price' => $base + ($index * 1_230_000),
                    'effective_from' => CarbonImmutable::now()->subDay(),
                ]);
            }
        }

        StorefrontSetting::query()->create([
            'is_enabled' => true,
            // The public window is `/shop/{slug}` since ADR 0017 — the hostname used to
            // say whose window it was, and the slug says it now. The column is nullable,
            // so a fixture without one renders no page at all rather than failing loudly.
            'slug' => 'mobile-nemoone',
            'display_name' => 'موبایل نمونه',
            'about' => 'فروش و تعمیر گوشی موبایل',
            'address' => 'تهران، میدان ونک',
            'phone' => '+982188889999',
            'whatsapp' => '+989121234567',
            'working_hours' => 'شنبه تا پنج‌شنبه ۱۰ تا ۲۱',
            'shows_out_of_stock' => true,
        ]);

        return [$consumer, $reseller];
    });

    [$this->consumer, $this->reseller] = $levels;

    $this->mint = fn (array $options = []): array => app(TenantContext::class)->runFor(
        $this->tenant,
        fn (): array => app(PriceListAccess::class)->mint(
            priceLevelId: (int) ($options['level'] ?? $this->reseller->getKey()),
            password: $options['password'] ?? null,
            // Relative, not a literal. The default only has to mean "a token that has
            // not expired"; pinned to 2026-09-01 it meant that until 2026-09-01, after
            // which five tests in this file would have started failing with nothing
            // committed. A date literal is only safe when the date is the point — see
            // PriceListSecurityTest, where it is, and is pinned on both sides instead.
            expiresAt: $options['expires'] ?? CarbonImmutable::now()->addMonth(),
        ),
    );
});

afterEach(function (): void {
    app(TenantContext::class)->forget();
});

/* ------------------------------------------------------------------ helpers -- */

/**
 * The first cell of every body row in the first table of a rendered page.
 *
 * Parsed out of the DOM rather than regexed, so it reads what a browser reads.
 *
 * @return list<string>
 */
function renderedRowLabels(string $html): array
{
    $document = new DOMDocument;

    // A public page is hand-written HTML with Persian in it; libxml's warnings about
    // HTML5 elements are noise, and the parse is good enough for cell extraction.
    libxml_use_internal_errors(true);
    $document->loadHTML('<?xml encoding="utf-8" ?>'.$html);
    libxml_clear_errors();

    $labels = [];

    foreach ((new DOMXPath($document))->query('//table//tbody/tr') ?: [] as $row) {
        $cells = $row->childNodes;

        foreach ($cells as $cell) {
            if ($cell instanceof DOMElement && $cell->tagName === 'td') {
                $labels[] = trim(preg_replace('/\s+/u', ' ', $cell->textContent) ?? '');

                break;
            }
        }
    }

    return $labels;
}

/* ------------------------------------------------- the bug that shipped -- */

it('renders four distinct rows for four distinct variants', function (): void {
    /*
    | THE assertion this file exists for, and it does not know what the labels should say.
    |
    | Four variants differing only in `options` must produce four distinguishable rows.
    | That is true of any correct catalogue and false of the bug that shipped, without
    | anybody having to predict the label format — which matters, because the person who
    | could predict it is the person who would not have written the bug.
    */
    $labels = renderedRowLabels($this->get(appUrl('/shop/mobile-nemoone'))->assertOk()->getContent() ?: '');

    expect($labels)->toHaveCount(4);
    expect(array_unique($labels))->toHaveCount(4);

    // And every one carries the product name, so "distinct" was not achieved by dropping it.
    foreach ($labels as $label) {
        expect($label)->toContain('آیفون ۱۵ پرو مکس');
    }
});

it('renders distinct rows on the reseller list and the print sheet too', function (): void {
    // The same defect could live in any of the three templates that render a catalogue.
    // Asserting it on one of them is how the other two ship broken.
    $minted = ($this->mint)();

    foreach (['/p/'.$minted['token'], '/p/'.$minted['token'].'/print'] as $path) {
        $labels = renderedRowLabels($this->get($this->url.$path)->assertOk()->getContent() ?: '');

        expect($labels)->toHaveCount(4);
        expect(array_unique($labels))->toHaveCount(4);
    }
});

/* ------------------------------------------- the helper that was dead -- */

it('renders no raw machine timestamps on any public page', function (): void {
    /*
    | This would have surfaced the shadowed `jdate()` about seven phases before Blade did.
    |
    | It does not assert a particular date. It asserts that **no `1405-06-02 21:18:47`
    | shape reaches a visitor** — which is what a broken date helper produces, whatever the
    | date happens to be, and which no correct page in this product ever renders.
    */
    $minted = ($this->mint)();

    $pages = [
        '/shop/mobile-nemoone',
        '/p/'.$minted['token'],
        '/p/'.$minted['token'].'/print',
    ];

    foreach ($pages as $path) {
        $html = $this->get($this->url.$path)->assertOk()->getContent() ?: '';

        // `Y-m-d H:i:s` and ISO-8601 — the two shapes a raw Carbon leaks as.
        expect($html)->not->toMatch('/\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}/');
    }
});

it('renders the expiry as a Jalali date with Persian digits', function (): void {
    /*
    | The positive half of the assertion above: something date-shaped IS there, so the
    | negative is not passing on a page that simply shows no date at all.
    |
    | The expiry is passed explicitly and the clock is pinned to meet it. It used to
    | rely on the *default* mint expiry happening to be `2026-09-01`, which made this
    | the one test in the file that cared what that default was — invisibly, from four
    | screens away, so relaxing the default to `now()->addMonth()` broke an assertion
    | about Jalali formatting with a message about a missing string.
    |
    | `2026-09-01` and «۱۴۰۵/۰۶/۱۰» are the same day in two calendars, and holding those
    | two literals side by side is the entire test — it is what catches a date rendered
    | by the wrong helper. So the pair stays visible here, where it is read, rather than
    | living in a fixture default that reads as arbitrary.
    */
    $this->travelTo(CarbonImmutable::parse('2026-08-01 09:00:00'));

    $minted = ($this->mint)(['expires' => CarbonImmutable::parse('2026-09-01 12:00:00')]);

    $this->get($this->url.'/p/'.$minted['token'])
        ->assertOk()
        ->assertSee('۱۴۰۵/۰۶/۱۰');
});

/* -------------------------------------------------------- template health -- */

it('leaves no unrendered Blade in any template', function (): void {
    /*
    | A mistyped directive — `@if` inside a string, a `{{` that was never closed — renders
    | as literal text rather than failing. On a public page that is a visitor reading our
    | template source.
    */
    $minted = ($this->mint)(['password' => 'hamkar-1404']);

    $expired = ($this->mint)(['expires' => CarbonImmutable::now()->subMinute()]);

    $pages = [
        ['/shop/mobile-nemoone', 200],
        ['/p/'.$minted['token'], 200],                 // the lock screen
        ['/p/'.$expired['token'], 410],                // the closed screen
    ];

    foreach ($pages as [$path, $status]) {
        $html = $this->get($this->url.$path)->assertStatus($status)->getContent() ?: '';

        expect($html)->not->toContain('{{')
            ->and($html)->not->toContain('{!!')
            ->and($html)->not->toContain('@if')
            ->and($html)->not->toContain('@foreach')
            // A null that reached the page as text — «قیمت: » with nothing after it is the
            // visible half; this is the half that shows up as the word.
            ->and($html)->not->toContain('>Array<')
            ->and($html)->not->toContain('{"');
    }

    // And every one of them is a complete document rather than a fragment.
    foreach ($pages as [$path, $status]) {
        $html = $this->get($this->url.$path)->assertStatus($status)->getContent() ?: '';

        expect($html)->toContain('<!DOCTYPE html>')
            ->and($html)->toContain('dir="rtl"')
            ->and($html)->toContain('</html>');
    }
});

it('renders every money figure formatted, never as raw rial', function (): void {
    /*
    | Money crosses to a template as an integer and must leave it as a string a person
    | reads. A raw `88819990` on the page is the formatter having been skipped — which is
    | invisible to a test that asserts on the service's return value.
    */
    $html = $this->get(appUrl('/shop/mobile-nemoone'))->assertOk()->getContent() ?: '';

    // The formatted figure is there…
    expect($html)->toContain('8,881,999');

    // …and the unformatted rial it came from is not, anywhere on the page.
    expect($html)->not->toContain('88819990');
});

it('renders the shop’s contact links as working hrefs', function (): void {
    // `assertSee` on a phone number passes even if the anchor is malformed. These are the
    // attributes a tap actually follows.
    $html = $this->get(appUrl('/shop/mobile-nemoone'))->assertOk()->getContent() ?: '';

    expect($html)->toContain('href="tel:+982188889999"')
        ->and($html)->toContain('href="https://wa.me/989121234567"');
});

it('renders the locked screen with a usable form and no catalogue', function (): void {
    $minted = ($this->mint)(['password' => 'hamkar-1404']);

    $html = $this->get($this->url.'/p/'.$minted['token'])->assertOk()->getContent() ?: '';

    // A password screen with no CSRF token is a form that always fails — and the failure
    // looks like a wrong password, which is the worst possible message for it.
    expect($html)->toContain('name="_token"')
        ->and($html)->toContain('type="password"');

    // Nothing of the list is behind it: no table at all, not merely no prices.
    expect(renderedRowLabels($html))->toBeEmpty();
});
