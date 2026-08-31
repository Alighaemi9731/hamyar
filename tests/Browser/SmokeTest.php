<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\CRM\Models\Account;
use App\Modules\CRM\Models\Party;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Enums\UnitStatus;
use App\Modules\Inventory\Models\ProductUnit;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Platform\Models\Domain;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Platform\Services\PlanCatalogueSeeder;
use App\Modules\Platform\Services\SubscriptionResolver;
use App\Modules\Platform\Services\TenantProvisioner;
use App\Modules\Repairs\Models\RepairTicket;
use App\Support\Tenancy\TenantContext;

/**
 * The manual walk, run by the machine.
 *
 * Every phase has ended with somebody opening the app and checking the same few things:
 * no sideways scroll at 390 and at 1280, no console errors, the page rendering at all.
 * It has found real defects every time — three in 11b, six in 11c — because a rendered
 * page asks a different question from a passing assertion.
 *
 * It also depends on somebody remembering, which is what this removes. Roadmap 11.1b.
 *
 * ## The shop is given the hostname the server answers on
 *
 * Every screen in this product is resolved from the **hostname**: `ResolveTenant` turns
 * `acme.app.localhost` into a shop and pins it for the request, and a hostname belonging
 * to no tenant is a 404 by design. Pest's HTTP server always binds `127.0.0.1`, so a
 * browser test visiting `/dashboard` arrives as a shop that does not exist.
 *
 * The plugin's `withHost()` looked like the answer and is not — it moves where the
 * server listens, not the Host header the page is fetched with, and the request still
 * arrives as `127.0.0.1`.
 *
 * So the fixture is inverted instead: the tenant is created with `127.0.0.1` as its own
 * primary domain. Nothing is faked — `ResolveTenant` does its real lookup against a real
 * `domains` row and either finds the shop or does not. It is the same trick the product
 * itself relies on (golden rule 1b: tenants resolve by `domains.hostname` rows, and the
 * apex is never a literal in code), which is exactly why it works here.
 *
 * ## Scope, deliberately narrow
 *
 * A **smoke** suite, not a replacement for the walk. It asserts what is mechanical —
 * overflow, console errors, the page rendering — and makes no claim about whether a
 * screen looks right. Judgement stays human; the tripwires do not.
 */
pest()->group('browser');

/**
 * Paths whose screens render a `<table>` that the fixture fills.
 *
 * Kept as a list rather than inferred, because "did this page render rows" is only a
 * meaningful question for a page that has a table at all — see the witness assertion.
 */
const POPULATED_TABLES = ['/catalog', '/crm', '/inventory', '/sales'];

beforeEach(function (): void {
    app(PlanCatalogueSeeder::class)->sync();

    $this->tenant = Tenant::factory()->create();

    // The host Pest's server answers on, given to the shop as its own.
    Domain::query()->create([
        'tenant_id' => $this->tenant->getKey(),
        'hostname' => '127.0.0.1',
        'is_primary' => true,
    ]);

    subscribe($this->tenant, 'pro');
    app(SubscriptionResolver::class)->forget();
    app(TenantProvisioner::class)->seedRoles($this->tenant);

    $this->owner = app(TenantContext::class)->runFor($this->tenant, function (): User {
        $user = User::factory()->create(['name' => 'مالک']);
        $user->assignRole('Owner');

        return $user;
    });

    seedShopData($this->tenant, $this->owner);
});

it('renders every main screen with no console error and no sideways scroll', function (string $path, string $device, string $theme): void {
    $this->actingAs($this->owner);

    /*
    | The theme is set on the browser context, not by clicking the toggle, and that is
    | the mechanism rather than a shortcut.
    |
    | `app.blade.php` decides the theme before first paint, from localStorage and — when
    | nothing is stored, which is every first visit — from `prefers-color-scheme`. So
    | `inDarkMode()` drives the real code path a shop owner meets on day one, on a phone
    | whose OS is in dark mode. Clicking the toggle would test the second visit and skip
    | the flash-of-wrong-theme logic entirely.
    |
    | The viewport is chosen at visit time rather than resized afterwards, for the same
    | family of reasons: the plugin builds the browser context from a device profile, and
    | a page laid out at one width and then resized is not the same thing as a page that
    | loaded at the other — media queries and `dvh` units settle differently.
    */
    $pending = $theme === 'dark' ? visit($path)->inDarkMode() : visit($path)->inLightMode();

    $page = $device === 'mobile'
        ? $pending->on()->mobile()
        : $pending->on()->desktop();

    $page->assertNoJavascriptErrors();

    /*
    | ---------------------------------------------------------------- the wait --
    | This poll is the difference between a test and a decoration.
    |
    | Inertia renders server-side into `data-page` and React mounts from it a beat
    | later. `script()` runs as soon as the load event fires, which is *before* that —
    | so the first version of this file measured an empty `<div id="app">`, found
    | scrollWidth equal to clientWidth on every page at every width, and passed all
    | eight cases. It also passed with a 2000px-wide element planted in the page, and
    | with sixty repetitions of an unbreakable token: green without witness, on the
    | exact suite written to stop that.
    |
    | Polling for a mounted root rather than sleeping a fixed interval: a sleep long
    | enough for CI is dead time locally, and one long enough locally is a flake in CI.
    */
    $result = $page->script(<<<'JS'
        new Promise((resolve) => {
            const deadline = Date.now() + 10000;

            const check = () => {
                const root = document.getElementById('app');
                const mounted = root !== null && root.innerHTML.length > 0;

                if (mounted || Date.now() > deadline) {
                    const el = document.documentElement;

                    resolve(JSON.stringify({
                        mounted,
                        // Which theme the page actually settled on, read off the element
                        // the stylesheet keys from. Without this the dark half of the
                        // matrix is the light half run twice.
                        dark: el.classList.contains('dark'),
                        // +1: sub-pixel rounding leaves scrollWidth a fraction over
                        // clientWidth on pages that do not scroll, and a gate that
                        // fires on rounding is a gate somebody switches off.
                        overflows: el.scrollWidth > el.clientWidth + 1,
                        scrollWidth: el.scrollWidth,
                        clientWidth: el.clientWidth,
                        // The witness for the fixture. See the assertion below.
                        rows: document.querySelectorAll('tbody tr').length,
                    }));

                    return;
                }

                setTimeout(check, 50);
            };

            check();
        })
    JS);

    // Asserted rather than cast: `script()` returns `mixed`, and a run where the page
    // never answered would otherwise decode to null and read as "did not overflow".
    expect($result)->toBeString('The measurement script must return a JSON string.');

    /** @var string $json */
    $json = $result;

    /** @var array{mounted: bool, dark: bool, overflows: bool, scrollWidth: int, clientWidth: int, rows: int} $measured */
    $measured = json_decode($json, true);

    // Asserted before the overflow check, and first: an unmounted page cannot overflow,
    // so without this every assertion below is true for the wrong reason.
    expect($measured['mounted'])->toBeTrue("[{$path}] never mounted on {$device}; nothing below this line means anything.");

    /*
    | The witness for the fixture, and the same lesson this file has now learned twice.
    |
    | `seedShopData()` posts a real sale and creates real rows, and nothing here would
    | notice if it quietly stopped. A failed POS post, a factory that drifts from its
    | schema, a route that starts filtering by a column the fixture leaves null — each
    | leaves every screen rendering its empty state, every assertion below passing, and
    | the suite reporting that nine populated screens are fine when it measured nine
    | empty ones. That is exactly the shape of the mount bug and the theme bug this file
    | already carries scars from: green, on the states least able to break.
    |
    | So the paths that are known to render a `<table>` must actually have rows in it.
    | `/repairs` is deliberately absent — its list is hand-rolled `<div>`s, not a table,
    | so a row count would assert nothing there and quietly pass for the wrong reason.
    */
    if (in_array($path, POPULATED_TABLES, true)) {
        expect($measured['rows'])->toBeGreaterThan(
            0,
            "[{$path}] rendered a table with no rows on {$device}; the fixture is not "
            .'populating this screen, so the measurements below are of an empty state.',
        );
    }

    /*
    | The witness for the theme half of the matrix, and it is the same lesson this file
    | already learned once about mounting.
    |
    | If `prefers-color-scheme` never reached the page, both halves would render light,
    | every assertion would pass, and the suite would report that dark mode is fine on
    | eight screens it never rendered in dark mode. Sixteen green cases proving eight
    | things is worse than eight, because it reads as twice the coverage.
    */
    expect($measured['dark'])->toBe(
        $theme === 'dark',
        "[{$path}] asked for the {$theme} theme and the document did not settle on it; the {$theme} cases below prove nothing.",
    );

    /*
    | Horizontal overflow is the defect no server-side test can reach. An RTL layout
    | that fits on a laptop and pushes the page sideways on a phone looks correct in
    | every screenshot anybody thought to take, and reads to a shop assistant as a
    | broken app — on the device they actually hold.
    */
    expect($measured['overflows'])->toBeFalse(sprintf(
        '[%s] scrolls sideways on %s in %s mode: %dpx of content in a %dpx viewport.',
        $path,
        $device,
        $theme,
        $measured['scrollWidth'],
        $measured['clientWidth'],
    ));
})->with([
    'dashboard' => '/dashboard',
    'audit log' => '/settings/activity',
    'products' => '/catalog',
    'users' => '/settings/users',
    'treasury' => '/treasury',
    'sales' => '/sales',
    'repairs' => '/repairs',
    'inventory' => '/inventory',
    'customers' => '/crm',
    /*
    | The till, and the screen this suite most needed and least had.
    |
    | It is the highest-traffic page in the product, and it shipped an action row that came
    | to 411px inside a 375px viewport — the exact defect `AppShell`'s own comment warns
    | about, re-created by a page that wrapped its buttons in a `flex` without `flex-wrap`.
    | Nothing caught it because nothing here looked at `/sales/pos`.
    |
    | Deliberately absent from POPULATED_TABLES: the cart starts empty, and a till that
    | opened with rows in it would be a different bug.
    */
    'pos' => '/sales/pos',
])->with([
    'mobile',
    'desktop',
])->with([
    'light',
    'dark',
]);

/**
 * A shop with a day's work in it.
 *
 * ## Why the fixture stopped being empty
 *
 * Every case in this file used to run against a shop with no rows, so what it actually
 * proved was that a handful of *empty* screens do not scroll sideways. Empty screens are
 * not where sideways scroll comes from. A table overflows once it has columns with content
 * in them; a toolbar overflows once its filters carry counts; a money column can only
 * misalign when there are two figures to misalign. The suite was green on precisely the
 * states least able to break, which is the same shape of defect as the mount check this
 * file already learned about the hard way — green without witness.
 *
 * So the shop is given the smallest set of rows that makes every screen render its real
 * layout: a catalogue with variants, serialized handsets in a warehouse, customers and a
 * supplier, a finalised sale, and a device on the bench.
 *
 * ## The sale is posted, not fabricated
 *
 * There is no `SalesInvoice::factory()`, and that is not a gap to work around. An invoice
 * is a counter row, a set of stock movements, ledger entries and a quota consumption,
 * written in one transaction. A hand-built header is a row no screen in this product would
 * ever show, so a layout measured against it is measured against fiction. Posting the real
 * form is both more honest and less code — the same reasoning `printableInvoice()` in
 * `InvoicePrintLayoutTest` is built on.
 *
 * ## It stays small on purpose
 *
 * Two products, four units, three parties, one sale, one ticket. Enough that every list
 * renders rows and every total has something to total; not so much that the fixture
 * becomes a seeder nobody can reason about, or that the suite pays for rows no assertion
 * looks at.
 */
function seedShopData(Tenant $tenant, User $owner): void
{
    /** @var array{Warehouse, Account, ProductUnit, Party} $fixtures */
    $fixtures = app(TenantContext::class)->runFor($tenant, function (): array {
        $warehouse = Warehouse::factory()->create([
            'name' => 'انبار مرکزی',
            'is_sellable' => true,
            'is_default' => true,
        ]);

        // A sale needs all three: somewhere the money lands, and the two headings the
        // ledger posts against.
        $cash = Account::factory()->create([
            'name' => 'صندوق فروشگاه',
            'type' => Account::TYPE_CASH,
            'is_default' => true,
        ]);
        Account::factory()->create(['name' => 'فروش کالا', 'type' => Account::TYPE_SALES]);
        Account::factory()->create(['name' => 'موجودی کالا', 'type' => Account::TYPE_INVENTORY]);
        Account::factory()->create(['name' => 'حساب بانکی', 'type' => Account::TYPE_BANK]);

        // A long Persian handset name, because a short one never reveals a column that
        // cannot hold its contents — which is the class of defect this suite exists for.
        $phone = Product::factory()->serialized()->create([
            'name' => 'گوشی موبایل سامسونگ گلکسی S23 اولترا ظرفیت ۵۱۲ گیگابایت',
        ]);

        $variant = ProductVariant::factory()->for($phone)->create();

        ProductUnit::factory()->count(3)->for($variant, 'variant')->create([
            'warehouse_id' => $warehouse->id,
            'status' => UnitStatus::InStock,
        ]);

        // The one that gets sold, so the sale below has something to move.
        $sellable = ProductUnit::factory()->for($variant, 'variant')->create([
            'warehouse_id' => $warehouse->id,
            'status' => UnitStatus::InStock,
            'cost' => 38_000_000,
        ]);

        // A non-serialized line too: the catalogue and stock screens render the two
        // types differently, and only one of them was ever on screen.
        Product::factory()->create(['name' => 'کابل شارژ تایپ‌سی']);

        $customer = Party::factory()->create(['name' => 'رضا کریمی', 'kind' => 'customer']);
        Party::factory()->create(['name' => 'مریم احمدی', 'kind' => 'customer']);
        Party::factory()->supplier()->create(['name' => 'پخش موبایل ایران']);

        RepairTicket::factory()->create([
            'branch_id' => $warehouse->branch_id,
            'device_brand' => 'اپل',
            'device_model' => 'آیفون ۱۳ پرو',
            'reported_issue' => 'شکستگی گلس و روشن نشدن صفحه',
        ]);

        return [$warehouse, $cash, $sellable, $customer];
    });

    [$warehouse, $cash, $unit, $customer] = $fixtures;

    test()->actingAs($owner)->post('http://127.0.0.1/sales/pos', [
        'branch_id' => $warehouse->branch_id,
        'party_id' => $customer->id,
        'salesperson_id' => null,
        'unit' => 'rial',
        'action' => 'finalise',
        'vat_applied' => true,
        'discount_amount' => 0,
        'shipping_amount' => 0,
        'notes' => null,
        'lines' => [[
            'unit_id' => $unit->id,
            'variant_id' => null,
            'quantity' => 1,
            'unit_price' => 52_000_000,
            'discount_amount' => 0,
        ]],
        'payments' => [[
            'method' => 'cash',
            'amount' => 56_680_000,
            'account_id' => $cash->id,
        ]],
    ])->assertSessionHasNoErrors();
}
