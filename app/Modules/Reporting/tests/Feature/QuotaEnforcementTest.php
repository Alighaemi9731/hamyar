<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\CRM\Models\Account;
use App\Modules\CRM\Models\Party;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Enums\MovementType;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockLedger;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Platform\Services\SubscriptionResolver;
use App\Modules\Platform\Services\TenantProvisioner;
use App\Support\Tenancy\TenantContext;

/**
 * The meter on «خروجی اکسل», at the place a shopkeeper actually meets it.
 *
 * ## Why this file exists separately from the guard's own suite
 *
 * `Platform/tests/Feature/Quota/*` proves the guard is correct — it counts, it refuses at
 * the ceiling, it is atomic under concurrency — and every one of those tests meters a
 * synthetic `quota.widgets` metric on purpose, so the guard's suite breaks when the guard
 * breaks rather than when Reporting renames something. The cost is that none of them touch
 * a route, and a guard that is perfect and never called is indistinguishable, from the shop
 * floor, from no guard at all.
 *
 * ## The claim this module owns, above every other module's
 *
 * **Looking is free.** `reporting.exports` meters the workbook and nothing else: a shop
 * that has spent its last export credit must still open every report and read every figure
 * it could read yesterday. That is not a nicety, it is the difference between a limit and
 * a lock-out — reads are never blocked (ADR 0018) — and it is the single easiest thing in
 * this module to break by accident, because the report screen and the download are the
 * same controller, one method apart, sharing one `rows()` call. A `meterExport()` that
 * drifted up into `index()` would still pass every existing report test, since those assert
 * figures rather than counters. The test below is the thing that would notice.
 *
 * ## The unusual shape: enforcement on a GET
 *
 * Everywhere else the meter sits on a POST that redirects. Here it sits on a GET whose
 * success path is a file — `Excel::download()`, a `BinaryFileResponse` carrying a
 * `Content-Disposition` — while the refusal renderer in `bootstrap/app.php` answers with
 * `back()`. So a blocked export is a 302 rather than a workbook, which turns out to be the
 * right answer for how the button is really built: `Sales.tsx` renders it as an `<a href>`
 * with the comment "a real navigation, not an Inertia visit: the response is a file", so the
 * browser arrives carrying a Referer and `back()` lands it on the report screen it left,
 * where the shell renders `quota_block` above the figures already on it. Two tests below
 * pin both halves — the file that is not streamed, and the page the shopkeeper ends up on.
 */
beforeEach(function (): void {
    $this->tenant = Tenant::factory()->withDomain()->create();
    $this->url = appUrl();

    subscribe($this->tenant, 'pro');
    app(SubscriptionResolver::class)->forget();
    app(TenantProvisioner::class)->seedRoles($this->tenant);

    /** @var array{User, User, Warehouse, ProductVariant, Party} $fixtures */
    $fixtures = inTenantContext($this->tenant, function (): array {
        $owner = User::factory()->create();
        $owner->assignRole('Owner');

        // A Cashier holds `reporting.view` and not `reporting.export` — the one role that
        // may read a report and not take it away, which is what the last test needs.
        $cashier = User::factory()->create();
        $cashier->assignRole('Cashier');

        $warehouse = Warehouse::factory()->create(['is_sellable' => true, 'is_default' => true]);

        Account::factory()->create(['type' => Account::TYPE_CASH, 'is_default' => true]);
        Account::factory()->create(['type' => Account::TYPE_SALES]);

        $variant = ProductVariant::factory()
            ->for(Product::factory()->create(['name' => 'باتری', 'type' => 'standard']))
            ->create();

        app(StockLedger::class)->record($variant->id, $warehouse->id, 50, MovementType::Purchase, unitCost: 60_000_000);

        return [$owner, $cashier, $warehouse, $variant, Party::factory()->create()];
    });

    [$this->owner, $this->cashier, $this->warehouse, $this->variant, $this->party] = $fixtures;

    // Two batteries at 100,000,000 each, on credit. A round figure the tests below can name,
    // so that "the shop can still see its figures" is a claim about a number rather than
    // about an empty page — a blank report satisfies `assertOk()` and proves nothing.
    test()->actingAs($this->owner)->post($this->url.'/sales/pos', [
        'branch_id' => $this->warehouse->branch_id,
        'party_id' => $this->party->id,
        'salesperson_id' => $this->owner->id,
        'unit' => 'rial',
        'action' => 'finalise',
        'vat_applied' => false,
        'discount_amount' => 0,
        'shipping_amount' => 0,
        'notes' => null,
        'lines' => [
            ['unit_id' => null, 'variant_id' => $this->variant->id, 'quantity' => 2, 'unit_price' => 100_000_000, 'discount_amount' => 0],
        ],
        'payments' => [],
    ])->assertSessionHasNoErrors();
});

afterEach(fn () => app(TenantContext::class)->forget());

/**
 * Ask for a workbook, through the real endpoint.
 *
 * `$referer` defaults to none, which is the bare case. The test about where a blocked
 * shopkeeper lands passes the report screen instead, which is what a browser sends when the
 * `<a href>` export button is clicked.
 *
 * @return Illuminate\Testing\TestResponse<Illuminate\Http\Response>
 */
function exportReport(string $report = 'sales', ?string $referer = null): Illuminate\Testing\TestResponse
{
    /** @var User $owner */
    $owner = test()->owner;
    /** @var string $url */
    $url = test()->url;

    return test()->actingAs($owner)->get(
        $url.'/reporting/'.$report.'/export',
        $referer === null ? [] : ['referer' => $referer],
    );
}

it('spends one export credit for one workbook', function (): void {
    $response = exportReport();

    $response->assertOk();

    expect($response->headers->get('content-disposition'))->toContain('.xlsx')
        ->and(quotaUsed($this->tenant, 'reporting.exports'))->toBe(1);
});

it('charges every report to the one credit, not one credit per report', function (): void {
    exportReport('sales')->assertOk();
    exportReport('profit')->assertOk();

    // A shopkeeper thinks «امروز چهار تا خروجی گرفتم», not "two sales exports and two tax
    // exports". The eight export routes share `reporting.exports` precisely so the number
    // printed on the plan page means what a shop would itself count.
    expect(quotaUsed($this->tenant, 'reporting.exports'))->toBe(2);
});

it('spends nothing at all for looking at a report on screen', function (): void {
    // Every report screen this module serves, opened one after another.
    foreach (['', '/sales', '/profit', '/inventory', '/financial', '/tax', '/operations', '/technicians'] as $screen) {
        $this->actingAs($this->owner)->get($this->url.'/reporting'.$screen)->assertOk();
    }

    // Not "the counter is still zero" — no counter AT ALL. A read that created a row
    // reading zero would be indistinguishable from a read that was metered and refunded,
    // and the presence of the row is the only place that difference is visible.
    expect(quotaRowExists($this->tenant, 'reporting.exports'))->toBeFalse();
});

it('shows a shop with no export credit left every figure it could see yesterday', function (): void {
    capQuota($this->tenant, 'reporting.exports', 0);

    // A report is a question, not a file. Refusing to answer it because the workbook credit
    // is spent would turn a metered plan into a lock-out — the failure ADR 0018 was written
    // to avoid, and the one that produces a support ticket beginning «نرم‌افزارتان از کار
    // افتاده».
    $this->actingAs($this->owner)
        ->get($this->url.'/reporting/sales')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Reporting::Reports/Sales')
            ->where('summary.revenue.value', 200_000_000)
            ->where('summary.invoice_count', 1)
            ->etc()
        );

    expect(quotaRowExists($this->tenant, 'reporting.exports'))->toBeFalse();
});

it('refuses the export at the ceiling and streams no file', function (): void {
    capQuota($this->tenant, 'reporting.exports', 1);

    exportReport()->assertOk();

    // The second one is the whole test. On a POST the refusal replaces a redirect back to a
    // form; here it has to replace a download, and the thing to be sure of is that the
    // shopkeeper does not receive a *file*. An empty or half-built workbook lands in the
    // downloads folder and is discovered as "the export is broken" weeks later, with no
    // message anywhere near it.
    $blocked = exportReport();

    $blocked->assertRedirect()->assertSessionHasErrors('quota');

    expect($blocked->headers->get('content-disposition'))->toBeNull()
        ->and(quotaUsed($this->tenant, 'reporting.exports'))->toBe(1);
});

it('sends a blocked shopkeeper back to the report they were looking at', function (): void {
    capQuota($this->tenant, 'reporting.exports', 0);

    // The Referer a browser sends when the export button — an `<a href>`, a real navigation
    // — is clicked from the sales report. `back()` prefers it over the session's previous
    // URL, so the operator lands on the screen they left, where the shell renders
    // `quota_block` above the figures they were already reading. Without it they would be
    // returned to whatever GET the session saw last, which after a download is the export
    // URL itself.
    exportReport('sales', referer: $this->url.'/reporting/sales')
        ->assertRedirect($this->url.'/reporting/sales')
        ->assertSessionHasErrors('quota');
});

it('hands the operator something to render, not just an error string', function (): void {
    capQuota($this->tenant, 'reporting.exports', 0);

    exportReport();

    /** @var array<string, mixed> $block */
    $block = session('quota_block') ?? [];

    // These are the keys `quota-block.tsx` reads. A refusal that reached the browser without
    // them would render an empty card, which is worse than a 500 because nobody would
    // report it.
    expect($block)->toHaveKeys(['metric', 'label', 'message', 'used', 'limit', 'resets_at', 'next_plan'])
        ->and($block['metric'])->toBe('reporting.exports')
        // Persian, not the exception's English. `QuotaExceeded` stopped extending
        // `RuntimeException` because a dozen controllers used to convert it into a field
        // message carrying exactly that English string.
        ->and($block['message'])->toBeString()->not->toContain('Quota exceeded');
});

it('names the cheapest plan that would actually fit', function (): void {
    capQuota($this->tenant, 'reporting.exports', 0);

    exportReport();

    /** @var array{next_plan?: array{code?: string, due?: array<string, mixed>}} $block */
    $block = session('quota_block') ?? [];

    // Not "the next one up the list" — the cheapest rung above «حرفه‌ای» whose limit clears
    // the wall the shop just hit. Aiming a shop at a plan that would block it again
    // tomorrow is how an upsell becomes a refund.
    expect($block['next_plan']['code'] ?? null)->toBe('enterprise')
        // And it quotes the prorated amount rather than the sticker price: the shop is
        // mid-period and is owed credit for the days it already paid for (ADR 0006).
        ->and($block['next_plan']['due'] ?? null)->toBeArray();
});

/**
 * An export refused for its own reasons costs the shop nothing.
 *
 * ## Why this is the weaker half of the claim, and cannot honestly be the stronger one
 *
 * Everywhere else the enforcement-site suite states this as "the domain write failed inside
 * the transaction, so the credit unwound with it". Here there is no domain write.
 * `MetersExports` says so in as many words — *there is no row; the count IS the only write*
 * — so it opens a transaction containing nothing but `consume()`, after the workbook is
 * built. There is therefore no such thing as an in-transaction failure for
 * `reporting.exports`, and manufacturing one would mean breaking the counter rather than
 * breaking the export.
 *
 * What can be shown, and matters, is the ordering: everything that can refuse an export
 * refuses it *before* `meterExport()` runs, so a shopkeeper told «اجازهٔ خروجی ندارید» is
 * not also charged for the file they did not get.
 */
it('spends nothing when the export is refused for its own reasons', function (): void {
    // A Cashier may read the report and may not take it away.
    // `SalesReportController::export()` aborts 403 well before the workbook, and so well
    // before the meter that follows it.
    $this->actingAs($this->cashier)
        ->get($this->url.'/reporting/sales/export')
        ->assertForbidden();

    // No row at all, rather than a row reading zero: the two are different claims, and only
    // the first says the shop was never charged for the attempt.
    expect(quotaRowExists($this->tenant, 'reporting.exports'))->toBeFalse();
});
