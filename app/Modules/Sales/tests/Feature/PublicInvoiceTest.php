<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\CRM\Models\Account;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Enums\MovementType;
use App\Modules\Inventory\Enums\UnitStatus;
use App\Modules\Inventory\Models\ProductUnit;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockLedger;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Platform\Services\PlanCatalogueSeeder;
use App\Modules\Platform\Services\SubscriptionResolver;
use App\Modules\Platform\Services\TenantProvisioner;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Services\PublicInvoiceLink;
use App\Support\QrRenderer;
use App\Support\Tenancy\TenantContext;

/**
 * The QR on the receipt, and the page it opens.
 *
 * The security property under test is narrow and total: **the signature is the only
 * thing standing between a URL and a shop's sales record.** Every test here is either
 * about that, or about what the page must not say.
 */
beforeEach(function (): void {
    app(PlanCatalogueSeeder::class)->sync();

    $this->tenant = Tenant::factory()->withDomain()->create();
    $this->url = appUrl();

    subscribe($this->tenant, 'pro');
    app(SubscriptionResolver::class)->forget();
    app(TenantProvisioner::class)->seedRoles($this->tenant);

    /** @var array{User, Warehouse, Account, ProductVariant, ProductUnit} $fixtures */
    $fixtures = inTenantContext($this->tenant, function (): array {
        $owner = User::factory()->create();
        $owner->assignRole('Owner');

        $warehouse = Warehouse::factory()->create(['is_sellable' => true, 'is_default' => true]);

        $cash = Account::factory()->create(['type' => Account::TYPE_CASH, 'is_default' => true]);
        Account::factory()->create(['type' => Account::TYPE_SALES]);
        Account::factory()->create(['type' => Account::TYPE_INVENTORY]);

        $product = Product::factory()->create(['name' => 'شارژر', 'type' => 'standard']);
        $variant = ProductVariant::factory()->for($product)->create();
        app(StockLedger::class)->record($variant->id, $warehouse->id, 50, MovementType::Purchase, unitCost: 200_000);

        $phone = Product::factory()->serialized()->create(['name' => 'آیفون ۱۵ پرو']);
        $phoneVariant = ProductVariant::factory()->for($phone)->create();
        $unit = ProductUnit::factory()->for($phoneVariant, 'variant')->create([
            'warehouse_id' => $warehouse->id,
            'status' => UnitStatus::InStock,
            'imei1' => '356938035643809',
            'cost' => 40_000_000,
        ]);

        return [$owner, $warehouse, $cash, $variant, $unit];
    });

    [$this->owner, $this->warehouse, $this->cash, $this->variant, $this->unit] = $fixtures;

    $this->inTenant = fn (Closure $callback): mixed => inTenantContext($this->tenant, $callback);
});

afterEach(fn () => app(TenantContext::class)->forget());

/**
 * Sell the seeded handset, and hand back the issued invoice.
 */
function issuedInvoice(): SalesInvoice
{
    /** @var Warehouse $warehouse */
    $warehouse = test()->warehouse;
    /** @var Tenant $tenant */
    $tenant = test()->tenant;
    /** @var User $owner */
    $owner = test()->owner;
    /** @var Account $cash */
    $cash = test()->cash;
    /** @var ProductUnit $unit */
    $unit = test()->unit;
    /** @var string $url */
    $url = test()->url;

    test()->actingAs($owner)->post($url.'/sales/pos', [
        'branch_id' => $warehouse->branch_id,
        'party_id' => null,
        'salesperson_id' => null,
        'unit' => 'rial',
        'action' => 'finalise',
        'vat_applied' => false,
        'discount_amount' => 0,
        'shipping_amount' => 0,
        'notes' => null,
        'lines' => [[
            'unit_id' => $unit->id,
            'variant_id' => null,
            'quantity' => 1,
            'unit_price' => 60_000_000,
            'discount_amount' => 0,
        ]],
        'payments' => [[
            'method' => 'cash',
            'amount' => 60_000_000,
            'account_id' => $cash->id,
        ]],
    ])->assertSessionHasNoErrors();

    /** @var SalesInvoice $invoice */
    $invoice = inTenantContext($tenant, fn () => SalesInvoice::query()->latest('id')->firstOrFail());

    return $invoice;
}

/* ---------------------------------------------------------------- the QR -- */

it('renders a QR that encodes a signed link on the shop own hostname', function (): void {
    $invoice = issuedInvoice();

    $link = ($this->inTenant)(fn () => app(PublicInvoiceLink::class)->for($invoice));

    expect($link)->toBeString()
        // The apex is never assembled from a literal — it comes from the shop's
        // `domains` row (golden rule 1b). A receipt is the worst place to bake one in:
        // it is printed on paper that outlives the deploy that changes it.
        ->and($link)->toContain($this->tenant->domains()->value('hostname'))
        ->and($link)->toContain('/i/'.$invoice->id)
        ->and($link)->toContain('signature=');

    $svg = app(QrRenderer::class)->svg($link);

    expect($svg)->toBeString()
        ->and($svg)->toStartWith('<svg')
        // An explicit white ground, or a receipt exported from a dark-mode browser is
        // dark modules on dark nothing.
        ->and($svg)->toContain('fill="#ffffff"');
});

it('puts the QR and the shop terms on the printed invoice', function (): void {
    // Set before the sale: the invoice snapshots the template at issue, so a shop that
    // writes its terms after selling does not retroactively change what it printed.
    $this->tenant->forceFill(['settings' => [
        'print' => [
            'footer_terms' => "۱. گارانتی تعویض هفت روزه\n۲. کالای فروخته‌شده پس گرفته نمی‌شود",
            'logo_url' => 'https://cdn.example.test/logo.png',
        ],
    ]])->save();
    app(TenantContext::class)->forget();

    $invoice = issuedInvoice();

    $this->actingAs($this->owner)
        ->get($this->url.'/sales/invoices/'.$invoice->id.'/print/thermal80')
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->where('template.footer_terms', "۱. گارانتی تعویض هفت روزه\n۲. کالای فروخته‌شده پس گرفته نمی‌شود")
            ->where('template.logo_url', 'https://cdn.example.test/logo.png')
            ->has('template.qr_svg')
        );
});

it('prints the terms that were in force on the day, not the ones rewritten since', function (): void {
    // The shop's wording when the sale happened.
    $this->tenant->forceFill(['settings' => ['print' => ['footer_terms' => 'شرط قدیمی']]])->save();
    app(TenantContext::class)->forget();

    $invoice = issuedInvoice();

    // …and the wording after they changed it.
    $this->tenant->forceFill(['settings' => ['print' => ['footer_terms' => 'شرط جدید']]])->save();
    app(TenantContext::class)->forget();

    // A reprint has to carry the version that governs the argument being had about it.
    $this->actingAs($this->owner)
        ->get($this->url.'/sales/invoices/'.$invoice->id.'/print/a4')
        ->assertInertia(fn ($page) => $page->where('template.footer_terms', 'شرط قدیمی'));
});

it('drops the QR when the shop has switched it off', function (): void {
    $this->tenant->forceFill(['settings' => ['print' => ['show_qr' => false]]])->save();
    app(TenantContext::class)->forget();

    $invoice = issuedInvoice();

    $this->actingAs($this->owner)
        ->get($this->url.'/sales/invoices/'.$invoice->id.'/print/thermal80')
        ->assertInertia(fn ($page) => $page
            ->where('template.qr_svg', null)
            ->where('template.public_url', null)
        );
});

/* ------------------------------------------------------------ the page -- */

it('opens for a customer with no account, on a signed link', function (): void {
    $invoice = issuedInvoice();

    $link = ($this->inTenant)(fn () => app(PublicInvoiceLink::class)->for($invoice));

    // Nobody signed in. This is a phone that scanned a piece of paper.
    $this->get($link)
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('Sales::Invoices/Public')
            ->where('invoice.number', $invoice->number)
            ->where('invoice.total.value', 60_000_000)
        );
});

it('refuses an unsigned or tampered link', function (): void {
    $invoice = issuedInvoice();

    // The bare path — what somebody gets by walking ids.
    $this->get($this->url.'/i/'.$invoice->id)->assertForbidden();

    $link = ($this->inTenant)(fn () => app(PublicInvoiceLink::class)->for($invoice));

    // A signature that was minted for this invoice, pointed at another id.
    $tampered = str_replace('/i/'.$invoice->id, '/i/'.($invoice->id + 1), (string) $link);
    $this->get($tampered)->assertForbidden();
});

it('tells nobody the cost, the margin or the IMEI', function (): void {
    $invoice = issuedInvoice();

    $link = ($this->inTenant)(fn () => app(PublicInvoiceLink::class)->for($invoice));

    // Genuinely anonymous. `issuedInvoice()` signed in as the owner to make the sale,
    // and without this the assertions below would be checking what a shop employee
    // sees — which is not the scenario this page exists for.
    auth()->logout();

    $response = $this->get($link);

    /** @var array<string, mixed> $props */
    $props = $response->viewData('page')['props'];

    $encoded = json_encode($props, JSON_UNESCAPED_UNICODE);

    // The handset's cost is the shop's buying price. The IMEI is what a stolen-device
    // check keys on. A signed link that leaks is a signed link somebody else can read.
    expect($encoded)->not->toContain('40000000')
        ->not->toContain('356938035643809')
        ->not->toContain('cost')
        ->not->toContain('profit');

    // And nothing about the shop's staff or its subscription. This page renders through
    // the same shared-prop middleware as the app, so the staff-only props have to be
    // gated on there being staff — see HandleInertiaRequests::isStaff().
    /** @var array<string, mixed> $auth */
    $auth = $props['auth'];
    /** @var array<string, mixed> $tenantProp */
    $tenantProp = $props['tenant'];

    expect($auth['user'])->toBeNull()
        ->and($props['announcements'])->toBe([])
        ->and($props['features'])->toBe([]);

    // The shop's own name and money formatting DO travel: the page has to render the
    // total the way the paper in the customer's hand does.
    expect($tenantProp['name'])->toBe($this->tenant->name);
});

it('says a voided invoice is void rather than pretending it never existed', function (): void {
    $invoice = issuedInvoice();

    $this->actingAs($this->owner)
        ->post($this->url.'/sales/invoices/'.$invoice->id.'/void', ['reason' => 'اشتباه ثبت شد']);

    $link = ($this->inTenant)(fn () => app(PublicInvoiceLink::class)->for($invoice));

    // A 404 would read as "the shop lost your record" to somebody holding the paper.
    $this->get($link)
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page->where('invoice.is_void', true));
});

it('will not open another shop invoice, even with a valid signature', function (): void {
    $invoice = issuedInvoice();

    $link = ($this->inTenant)(fn () => app(PublicInvoiceLink::class)->for($invoice));

    $other = Tenant::factory()->withDomain()->create();

    // The same signed path, moved to the other shop's hostname. The signature covers the
    // full URL, so this fails on the signature first — and RLS behind it would refuse the
    // row anyway. Both locks, in that order.
    $host = parse_url((string) $link, PHP_URL_HOST);
    $otherHost = $other->domains()->value('hostname');

    $moved = str_replace(
        is_string($host) ? $host : '',
        is_string($otherHost) ? $otherHost : '',
        (string) $link,
    );

    $this->get($moved)->assertForbidden();
});
