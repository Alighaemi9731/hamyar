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
use App\Modules\Sales\Models\SalesInvoice;
use App\Support\Tenancy\TenantContext;

/**
 * The invoice table, measured instead of inferred.
 *
 * ## The defect this owns
 *
 * With an `auto` table layout, a realistic Persian product name — «گوشی موبایل اپل
 * آیفون ۱۵ پرو مکس ظرفیت ۲۵۶ گیگابایت تیتانیوم طبیعی», an ordinary name for a phone and
 * not an edge case — squeezed the money columns until three figures ran together as
 * `96,636,7981,200,00089,200,0001`. A printed invoice, handed to a customer, with the
 * total unreadable.
 *
 * `app/Modules/Sales/tests/Feature/InvoicePrintLayoutTest.php` has guarded that since
 * Phase 7 by asserting the **mechanism** — `table-fixed` is still in the source, the
 * colgroup widths are still there — and said plainly in its own docblock that it could
 * not catch a width that was merely too small, because measuring a rendered box needs a
 * browser. Roadmap 11.1b owns replacing it, and this is that replacement.
 *
 * ## Two assertions, and the second is the one that matters
 *
 * The roadmap asks for "no two cells in a row overlap", and that is asserted below. But
 * cell **boxes** do not overlap under `table-fixed` even when the bug is present: the
 * columns stay where the colgroup put them and the *text inside them* spills past its
 * cell to collide with the neighbour's. So the load-bearing check is the second one —
 * every cell's content fits inside the cell that owns it.
 *
 * A test that only asserted non-overlapping boxes would pass on the exact defect it was
 * written for.
 */
beforeEach(function (): void {
    app(PlanCatalogueSeeder::class)->sync();

    $this->tenant = Tenant::factory()->create();

    // Pest's server binds 127.0.0.1 and every screen resolves from the hostname, so the
    // shop is given that host as its own. See tests/Browser/SmokeTest.php.
    Domain::query()->create([
        'tenant_id' => $this->tenant->getKey(),
        'hostname' => '127.0.0.1',
        'is_primary' => true,
    ]);

    subscribe($this->tenant, 'pro');
    app(SubscriptionResolver::class)->forget();
    app(TenantProvisioner::class)->seedRoles($this->tenant);

    /** @var array{User, Warehouse, Account, ProductUnit, Party} $fixtures */
    $fixtures = inTenantContext($this->tenant, function (): array {
        $owner = User::factory()->create();
        $owner->assignRole('Owner');

        $warehouse = Warehouse::factory()->create(['is_sellable' => true, 'is_default' => true]);

        $cash = Account::factory()->create(['type' => Account::TYPE_CASH, 'is_default' => true]);
        Account::factory()->create(['type' => Account::TYPE_SALES]);
        Account::factory()->create(['type' => Account::TYPE_INVENTORY]);

        // The name from the original defect, verbatim. Shortening it to something
        // convenient would be testing a different invoice than the one that broke.
        $phone = Product::factory()->serialized()->create([
            'name' => 'گوشی موبایل اپل آیفون ۱۵ پرو مکس ظرفیت ۲۵۶ گیگابایت تیتانیوم طبیعی',
        ]);

        $variant = ProductVariant::factory()->for($phone)->create();

        $unit = ProductUnit::factory()->for($variant, 'variant')->create([
            'warehouse_id' => $warehouse->id,
            'status' => UnitStatus::InStock,
            'imei1' => '356938035643809',
            'cost' => 40_000_000,
        ]);

        // A named customer, because the invoice under test carries a VAT total the
        // cash payment does not cover — and a credit balance without a party is
        // refused, correctly, by the POS.
        $party = Party::factory()->create(['name' => 'رضا کریمی', 'kind' => 'customer']);

        return [$owner, $warehouse, $cash, $unit, $party];
    });

    [$this->owner, $this->warehouse, $this->cash, $this->unit, $this->party] = $fixtures;
});

afterEach(fn () => app(TenantContext::class)->forget());

/**
 * Sell the seeded handset at the figure from the original collision.
 *
 * `96,636,798` **toman** is the number that ran into its neighbour on the broken
 * invoice, so the price here is that exact amount expressed in rial. Rounding it to
 * something tidy would give the money columns more room than a real invoice ever has,
 * which is the whole variable under test.
 *
 * It ends in a zero because it has to: rial amounts in this product always land on a
 * whole number of toman, and `Money::inUnit()` refuses anything else rather than
 * rounding a customer's money away (golden rule 2). The first version of this fixture
 * used `896,636,798` rial and was correctly refused — the guard has eyes.
 */
function printableInvoice(): SalesInvoice
{
    /** @var Warehouse $warehouse */
    $warehouse = test()->warehouse;
    /** @var User $owner */
    $owner = test()->owner;
    /** @var Account $cash */
    $cash = test()->cash;
    /** @var ProductUnit $unit */
    $unit = test()->unit;
    /** @var Party $party */
    $party = test()->party;

    test()->actingAs($owner)->post('http://127.0.0.1/sales/pos', [
        'branch_id' => $warehouse->branch_id,
        'party_id' => $party->id,
        'salesperson_id' => null,
        'unit' => 'rial',
        'action' => 'finalise',
        'vat_applied' => true,
        'discount_amount' => 12_000_000,
        'shipping_amount' => 0,
        'notes' => null,
        'lines' => [[
            'unit_id' => $unit->id,
            'variant_id' => null,
            'quantity' => 1,
            'unit_price' => 966_367_980,
            'discount_amount' => 0,
        ]],
        'payments' => [[
            'method' => 'cash',
            'amount' => 954_367_980,
            'account_id' => $cash->id,
        ]],
    ])->assertSessionHasNoErrors();

    /** @var Tenant $tenant */
    $tenant = test()->tenant;

    /** @var SalesInvoice $invoice */
    $invoice = inTenantContext($tenant, fn (): SalesInvoice => SalesInvoice::query()->latest('id')->firstOrFail());

    return $invoice;
}

/**
 * Every table cell on the page, with the geometry needed to judge it.
 *
 * Returned from one script call rather than several: each round trip is a message to the
 * browser, and a row's cells have to be measured in the same layout pass to be
 * comparable at all.
 */
const MEASURE_CELLS = <<<'JS'
new Promise((resolve) => {
    const deadline = Date.now() + 10000;

    const check = () => {
        const root = document.getElementById('app');

        if ((root === null || root.innerHTML.length === 0) && Date.now() < deadline) {
            setTimeout(check, 50);

            return;
        }

        const rows = [...document.querySelectorAll('table tbody tr')].map((row, index) => ({
            index,
            cells: [...row.children].map((cell) => {
                const box = cell.getBoundingClientRect();

                return {
                    text: (cell.innerText || '').trim().slice(0, 40),
                    left: box.left,
                    right: box.right,
                    width: box.width,
                    // The pair that catches the real defect: content wider than the box
                    // that owns it is a figure spilling into its neighbour.
                    scrollWidth: cell.scrollWidth,
                    clientWidth: cell.clientWidth,
                };
            }),
        }));

        resolve(JSON.stringify({ mounted: root !== null && root.innerHTML.length > 0, rows }));
    };

    check();
})
JS;

it('prints a long Persian product name without any cell overlapping its neighbour', function (string $paper): void {
    $invoice = printableInvoice();

    $this->actingAs($this->owner);

    $result = visit("/sales/invoices/{$invoice->id}/print/{$paper}")
        ->on()->desktop()
        ->assertNoJavascriptErrors()
        ->script(MEASURE_CELLS);

    expect($result)->toBeString();

    /** @var string $json */
    $json = $result;

    /** @var array{mounted: bool, rows: list<array{index: int, cells: list<array{text: string, left: float, right: float, width: float, scrollWidth: int, clientWidth: int}>}>} $page */
    $page = json_decode($json, true);

    // Nothing below means anything on a page that never rendered.
    expect($page['mounted'])->toBeTrue("[{$paper}] never mounted.")
        ->and($page['rows'])->not->toBeEmpty("[{$paper}] rendered no item rows to measure.");

    foreach ($page['rows'] as $row) {
        $cells = $row['cells'];

        usort($cells, fn (array $a, array $b): int => $a['left'] <=> $b['left']);

        for ($i = 1; $i < count($cells); $i++) {
            // Half a pixel of tolerance for sub-pixel rounding on adjacent borders.
            expect($cells[$i]['left'])->toBeGreaterThanOrEqual(
                $cells[$i - 1]['right'] - 0.5,
                sprintf(
                    '[%s] row %d: «%s» starts at %.1f, inside «%s» which ends at %.1f.',
                    $paper, $row['index'], $cells[$i]['text'], $cells[$i]['left'],
                    $cells[$i - 1]['text'], $cells[$i - 1]['right'],
                ),
            );
        }
    }
})->with(['a4', 'a5']);

it('keeps every figure inside the column that owns it', function (string $paper): void {
    // The assertion the source-level guard could not make, and the one that fails on
    // the original defect: `table-fixed` holds the boxes still while the text spills.
    $invoice = printableInvoice();

    $this->actingAs($this->owner);

    $result = visit("/sales/invoices/{$invoice->id}/print/{$paper}")
        ->on()->desktop()
        ->script(MEASURE_CELLS);

    expect($result)->toBeString();

    /** @var string $json */
    $json = $result;

    /** @var array{mounted: bool, rows: list<array{index: int, cells: list<array{text: string, scrollWidth: int, clientWidth: int}>}>} $page */
    $page = json_decode($json, true);

    expect($page['mounted'])->toBeTrue()
        ->and($page['rows'])->not->toBeEmpty();

    foreach ($page['rows'] as $row) {
        foreach ($row['cells'] as $cell) {
            expect($cell['scrollWidth'])->toBeLessThanOrEqual(
                $cell['clientWidth'] + 1,
                sprintf(
                    '[%s] row %d: «%s» needs %dpx in a %dpx column — it is printing over its neighbour.',
                    $paper, $row['index'], $cell['text'], $cell['scrollWidth'], $cell['clientWidth'],
                ),
            );
        }
    }
})->with(['a4', 'a5']);

/* ------------------------------------------------------- 11.1b: print smoke -- */

/**
 * Every paper size renders, in both themes, without logging anything.
 *
 * ## Why the console matters more here than anywhere else
 *
 * Nobody has devtools open when they print. The two tests above measure `a4` and `a5`
 * because those are the ones with a table to squeeze; `thermal80` uses no table at all
 * and was therefore covered by nothing — a receipt roll is what most of these shops
 * actually print on, and a page that throws while rendering it produces a blank strip
 * and no clue.
 *
 * ## What the dark half does and does not prove
 *
 * It proves the print *page* survives a shop working in dark mode: it mounts, it logs
 * nothing, and the sheet is still the width it claims to be. That is a real class of
 * defect — a themed token leaking into a layout that has no business being themed.
 *
 * It does **not** prove the receipt prints as ink on white. That rule lives in an
 * `@media print` block (resources/css/app.css), and Pest's browser plugin cannot
 * emulate print media — the same limitation that keeps the source-level layout test
 * alive, recorded against roadmap 11.1b. Until `emulateMedia({ media: 'print' })` is
 * reachable, "a dark-mode receipt does not print as a black rectangle" is guarded by
 * that CSS rule and by nothing else, and saying so is worth more than a test that
 * measures screen media and implies otherwise.
 */
it('renders every paper size in both themes with nothing in the console', function (string $paper, string $theme): void {
    $invoice = printableInvoice();

    $this->actingAs($this->owner);

    $pending = $theme === 'dark'
        ? visit("/sales/invoices/{$invoice->id}/print/{$paper}")->inDarkMode()
        : visit("/sales/invoices/{$invoice->id}/print/{$paper}")->inLightMode();

    $result = $pending->on()->desktop()
        ->assertNoJavascriptErrors()
        ->script(<<<'JS'
            new Promise((resolve) => {
                const deadline = Date.now() + 10000;

                const check = () => {
                    const root = document.getElementById('app');
                    const mounted = root !== null && root.innerHTML.length > 0;
                    const sheet = document.querySelector('[data-print-root]');

                    if ((mounted && sheet !== null) || Date.now() > deadline) {
                        resolve(JSON.stringify({
                            mounted,
                            dark: document.documentElement.classList.contains('dark'),
                            // The sheet has to exist: it is what the print stylesheet
                            // un-pads, and a page missing it prints inside the app
                            // shell's content column at a third of the paper width.
                            hasSheet: sheet !== null,
                            // Content wider than the sheet runs off the paper. Measured
                            // on the sheet rather than the viewport, because a sheet is
                            // deliberately a fixed width and a desktop viewport is not.
                            sheetScrollWidth: sheet === null ? 0 : sheet.scrollWidth,
                            sheetClientWidth: sheet === null ? 0 : sheet.clientWidth,
                        }));

                        return;
                    }

                    setTimeout(check, 50);
                };

                check();
            })
        JS);

    expect($result)->toBeString();

    /** @var string $json */
    $json = $result;

    /** @var array{mounted: bool, dark: bool, hasSheet: bool, sheetScrollWidth: int, sheetClientWidth: int} $measured */
    $measured = json_decode($json, true);

    expect($measured['mounted'])->toBeTrue("[{$paper}/{$theme}] never mounted.");

    // Same witness as the screen smoke suite: without it the dark half is the light
    // half run three more times.
    expect($measured['dark'])->toBe(
        $theme === 'dark',
        "[{$paper}] asked for the {$theme} theme and did not settle on it; this case proves nothing.",
    );

    expect($measured['hasSheet'])->toBeTrue(
        "[{$paper}/{$theme}] rendered no [data-print-root]; the print stylesheet has nothing to un-pad and the sheet will print inside the app shell.",
    );

    expect($measured['sheetScrollWidth'])->toBeLessThanOrEqual(
        $measured['sheetClientWidth'] + 1,
        sprintf(
            '[%s/%s] content needs %dpx on a %dpx sheet — it runs off the paper.',
            $paper, $theme, $measured['sheetScrollWidth'], $measured['sheetClientWidth'],
        ),
    );
})->with(['a4', 'a5', 'thermal80'])->with(['light', 'dark']);
