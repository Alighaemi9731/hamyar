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
use App\Modules\Platform\Services\PlanCatalogueSeeder;
use App\Modules\Platform\Services\SubscriptionResolver;
use App\Modules\Platform\Services\TenantProvisioner;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Services\RecordReturn;
use App\Support\Jalali;
use App\Support\Tenancy\TenantContext;

/**
 * گزارش Z — the figure somebody counts notes against.
 *
 * Every test here is about the expected-cash number or about what explains a difference
 * in it. A Z-report that is merely "today's sales" is a report nobody uses to close a
 * till.
 */
beforeEach(function (): void {
    app(PlanCatalogueSeeder::class)->sync();

    $this->tenant = Tenant::factory()->withDomain()->create();
    $this->url = tenantUrl($this->tenant);

    subscribe($this->tenant, 'pro');
    app(SubscriptionResolver::class)->forget();
    app(TenantProvisioner::class)->seedRoles($this->tenant);

    /** @var array{User, User, Warehouse, Account, Account, ProductVariant} $fixtures */
    $fixtures = inTenantContext($this->tenant, function (): array {
        $owner = User::factory()->create();
        $owner->assignRole('Owner');

        $seller = User::factory()->create();
        $seller->assignRole('Salesperson');

        $warehouse = Warehouse::factory()->create(['is_sellable' => true, 'is_default' => true]);

        $cash = Account::factory()->create(['type' => Account::TYPE_CASH, 'is_default' => true, 'name' => 'صندوق فروشگاه']);
        $terminal = Account::factory()->create(['type' => Account::TYPE_POS_TERMINAL, 'name' => 'کارتخوان ملت']);
        Account::factory()->create(['type' => Account::TYPE_SALES]);

        $product = Product::factory()->create(['name' => 'شارژر', 'type' => 'standard']);
        $variant = ProductVariant::factory()->for($product)->create();

        app(StockLedger::class)->record($variant->id, $warehouse->id, 100, MovementType::Purchase, unitCost: 200_000);

        return [$owner, $seller, $warehouse, $cash, $terminal, $variant];
    });

    [$this->owner, $this->seller, $this->warehouse, $this->cash, $this->terminal, $this->variant] = $fixtures;

    $this->inTenant = fn (Closure $callback): mixed => inTenantContext($this->tenant, $callback);
    $this->today = Jalali::today(Jalali::DATE, false);
});

afterEach(fn () => app(TenantContext::class)->forget());

/**
 * Sell some chargers, settled however the caller says.
 *
 * @param  list<array<string, mixed>>  $payments
 */
function sellToday(int $price, array $payments, ?int $partyId = null): void
{
    /** @var Warehouse $warehouse */
    $warehouse = test()->warehouse;
    /** @var ProductVariant $variant */
    $variant = test()->variant;
    /** @var User $owner */
    $owner = test()->owner;
    /** @var string $url */
    $url = test()->url;

    test()->actingAs($owner)->post($url.'/sales/pos', [
        'branch_id' => $warehouse->branch_id,
        'party_id' => $partyId,
        'salesperson_id' => null,
        'unit' => 'rial',
        'action' => 'finalise',
        'vat_applied' => false,
        'discount_amount' => 0,
        'shipping_amount' => 0,
        'notes' => null,
        'lines' => [
            ['unit_id' => null, 'variant_id' => $variant->id, 'quantity' => 1, 'unit_price' => $price, 'discount_amount' => 0],
        ],
        'payments' => $payments,
    ])->assertSessionHasNoErrors();
}

/**
 * @return array<string, mixed>
 */
function closeReport(): array
{
    /** @var User $owner */
    $owner = test()->owner;
    /** @var string $url */
    $url = test()->url;
    /** @var string $today */
    $today = test()->today;

    $response = test()->actingAs($owner)->get($url.'/sales/close?date='.$today);

    $response->assertSuccessful();

    /** @var array<string, mixed> $props */
    $props = $response->viewData('page')['props']['report'];

    return $props;
}

/**
 * One rial figure out of the report.
 *
 * The props cross as `mixed`, and reaching into `['net']['value']` at twenty call sites
 * would need a cast at every one. Narrowed here instead, so a shape change surfaces as
 * one failing helper rather than twenty unreadable analyser errors.
 *
 * @param  array<string, mixed>  $report
 */
function reportRial(array $report, string $key): int
{
    $value = $report[$key] ?? null;

    if (! is_array($value) || ! is_int($value['value'] ?? null)) {
        throw new RuntimeException("Report key [{$key}] is not a money value.");
    }

    return $value['value'];
}

/**
 * One payment-method row.
 *
 * @param  array<string, mixed>  $report
 * @return array<string, mixed>
 */
function paymentRow(array $report, string $method): array
{
    /** @var list<array<string, mixed>> $rows */
    $rows = is_array($report['payments'] ?? null) ? $report['payments'] : [];

    foreach ($rows as $row) {
        if (($row['method'] ?? null) === $method) {
            return $row;
        }
    }

    throw new RuntimeException("No payment row for [{$method}].");
}

/* --------------------------------------------------------- expected cash -- */

it('expects only the cash in the drawer, not the card takings', function (): void {
    sellToday(3_000_000, [['method' => 'cash', 'amount' => 3_000_000, 'account_id' => $this->cash->id]]);
    sellToday(5_000_000, [['method' => 'pos_terminal', 'amount' => 5_000_000, 'account_id' => $this->terminal->id]]);

    $report = closeReport();

    // Eight million was sold; three million is in the drawer. A single "collected"
    // figure would reconcile against neither pile.
    expect(reportRial($report, 'net'))->toBe(8_000_000)
        ->and(reportRial($report, 'expected_cash'))->toBe(3_000_000);
});

it('keeps a cheque out of the drawer while still counting the sale', function (): void {
    $party = ($this->inTenant)(fn () => Party::factory()->create());

    sellToday(4_000_000, [
        ['method' => 'cash', 'amount' => 1_000_000, 'account_id' => $this->cash->id],
        ['method' => 'cheque', 'amount' => 3_000_000, 'reference' => '445566'],
    ], $party->id);

    $report = closeReport();

    // A cheque in hand is an asset, not cash on hand — treating it as cash overstates
    // the till every time one bounces.
    expect(reportRial($report, 'net'))->toBe(4_000_000)
        ->and(reportRial($report, 'expected_cash'))->toBe(1_000_000);

    $cheque = paymentRow($report, 'cheque');

    expect(reportRial($cheque, 'amount'))->toBe(3_000_000)
        ->and($cheque['settles_now'])->toBeFalse();
});

it('subtracts a cash refund to a walk-in, and shows it', function (): void {
    sellToday(3_000_000, [['method' => 'cash', 'amount' => 3_000_000, 'account_id' => $this->cash->id]]);

    ($this->inTenant)(function (): void {
        $invoice = SalesInvoice::query()->latest('id')->firstOrFail();
        $item = $invoice->items()->firstOrFail();

        app(RecordReturn::class)->record($invoice, [
            ['item_id' => $item->id, 'quantity' => 1],
        ], 'مشتری پشیمان شد', $this->owner->id);
    });

    $report = closeReport();

    // The money left the drawer, so the expected figure has to come down — and the
    // refund has to be visible, or a till that is 3,000,000 short has no explanation.
    expect(reportRial($report, 'refunded'))->toBe(3_000_000)
        ->and(reportRial($report, 'expected_cash'))->toBe(0)
        ->and($report['return_count'])->toBe(1);
});

it('does not subtract a refund that was credited to a customer account', function (): void {
    $party = ($this->inTenant)(fn () => Party::factory()->create());

    sellToday(3_000_000, [['method' => 'cash', 'amount' => 3_000_000, 'account_id' => $this->cash->id]], $party->id);

    ($this->inTenant)(function (): void {
        $invoice = SalesInvoice::query()->latest('id')->firstOrFail();
        $item = $invoice->items()->firstOrFail();

        app(RecordReturn::class)->record($invoice, [
            ['item_id' => $item->id, 'quantity' => 1],
        ], null, $this->owner->id);
    });

    $report = closeReport();

    // Nothing came out of the drawer — the customer is in credit instead. Subtracting it
    // would make the till look short by exactly the refund.
    expect(reportRial($report, 'refunded'))->toBe(0)
        ->and(reportRial($report, 'expected_cash'))->toBe(3_000_000);
});

/* ------------------------------------------------------------- the rest -- */

it('reports credit extended today', function (): void {
    $party = ($this->inTenant)(fn () => Party::factory()->create());

    sellToday(6_000_000, [['method' => 'cash', 'amount' => 2_000_000, 'account_id' => $this->cash->id]], $party->id);

    $report = closeReport();

    expect(reportRial($report, 'credit_extended'))->toBe(4_000_000);
});

it('counts voided invoices rather than hiding them', function (): void {
    sellToday(3_000_000, [['method' => 'cash', 'amount' => 3_000_000, 'account_id' => $this->cash->id]]);

    $invoice = ($this->inTenant)(fn () => SalesInvoice::query()->latest('id')->firstOrFail());

    $this->actingAs($this->owner)
        ->post($this->url.'/sales/invoices/'.$invoice->id.'/void', ['reason' => 'اشتباه ثبت شد']);

    $report = closeReport();

    // The money is gone from the totals, but "we voided one invoice today" is exactly
    // what an owner wants to see — hiding it is how a till gets quietly abused.
    expect($report['void_count'])->toBe(1)
        ->and(reportRial($report, 'net'))->toBe(0)
        ->and($report['invoice_count'])->toBe(0);
});

it('does not book a cheque against the cash box it never reached', function (): void {
    $party = ($this->inTenant)(fn () => Party::factory()->create());

    // What the POS actually posts: every payment row is pre-filled with the default
    // cash account, and the account field is then hidden when the operator picks چک —
    // so the id rides along on a payment that puts nothing in the drawer.
    sellToday(4_000_000, [
        ['method' => 'cash', 'amount' => 1_000_000, 'account_id' => $this->cash->id],
        ['method' => 'cheque', 'amount' => 3_000_000, 'account_id' => $this->cash->id, 'reference' => '778899'],
    ], $party->id);

    ($this->inTenant)(function (): void {
        $cheque = App\Modules\Sales\Models\InvoicePayment::query()
            ->where('method', 'cheque')
            ->firstOrFail();

        // Stripped on the way in, by the server rather than only by the browser.
        expect($cheque->account_id)->toBeNull();
    });

    $report = closeReport();

    /** @var list<array<string, mixed>> $rows */
    $rows = is_array($report['accounts'] ?? null) ? $report['accounts'] : [];

    $cash = null;

    foreach ($rows as $row) {
        if (($row['name'] ?? null) === 'صندوق فروشگاه') {
            $cash = reportRial($row, 'amount');
        }
    }

    // The by-account panel sits directly under the expected-cash figure. If a cheque
    // lands in it, the two contradict each other on the same screen — which is how the
    // person closing the till stops trusting either number.
    expect($cash)->toBe(1_000_000)
        ->and(reportRial($report, 'expected_cash'))->toBe(1_000_000);
});

it('breaks the takings down by account', function (): void {
    sellToday(3_000_000, [['method' => 'cash', 'amount' => 3_000_000, 'account_id' => $this->cash->id]]);
    sellToday(5_000_000, [['method' => 'pos_terminal', 'amount' => 5_000_000, 'account_id' => $this->terminal->id]]);

    $report = closeReport();

    /** @var list<array<string, mixed>> $rows */
    $rows = is_array($report['accounts'] ?? null) ? $report['accounts'] : [];

    $accounts = [];

    foreach ($rows as $row) {
        $name = $row['name'] ?? '';
        $accounts[is_string($name) ? $name : ''] = reportRial($row, 'amount');
    }

    expect($accounts['صندوق فروشگاه'])->toBe(3_000_000)
        ->and($accounts['کارتخوان ملت'])->toBe(5_000_000);
});

it('lists every payment method, including the ones nobody used today', function (): void {
    sellToday(3_000_000, [['method' => 'cash', 'amount' => 3_000_000, 'account_id' => $this->cash->id]]);

    $report = closeReport();

    /** @var list<array<string, mixed>> $rows */
    $rows = is_array($report['payments'] ?? null) ? $report['payments'] : [];

    // A row that vanishes on a day with no cheques is a row people stop looking for.
    expect(array_map(fn (array $row): mixed => $row['method'], $rows))
        ->toBe(['cash', 'pos_terminal', 'card_to_card', 'cheque', 'credit', 'trade_in']);
});

/* ------------------------------------------------------------ permissions -- */

it('withholds the day profit from a salesperson', function (): void {
    sellToday(3_000_000, [['method' => 'cash', 'amount' => 3_000_000, 'account_id' => $this->cash->id]]);

    $this->actingAs($this->owner)
        ->get($this->url.'/sales/close?date='.$this->today)
        ->assertInertia(fn ($page) => $page->where('report.profit.profit.value', 2_800_000));

    // 3,000,000 sold, 200,000 cost. Absent, not zeroed, for staff without the
    // permission (Gate 1).
    $this->actingAs($this->seller)
        ->get($this->url.'/sales/close?date='.$this->today)
        ->assertInertia(fn ($page) => $page->where('report.profit', null));
});

/* -------------------------------------------------------------- isolation -- */

it('shows another shop nothing of this one', function (): void {
    sellToday(3_000_000, [['method' => 'cash', 'amount' => 3_000_000, 'account_id' => $this->cash->id]]);

    $other = Tenant::factory()->withDomain()->create();
    subscribe($other, 'pro');
    app(SubscriptionResolver::class)->forget();
    app(TenantProvisioner::class)->seedRoles($other);

    $intruder = inTenantContext($other, function (): User {
        $user = User::factory()->create();
        $user->assignRole('Owner');

        Warehouse::factory()->create(['is_sellable' => true, 'is_default' => true]);

        return $user;
    });

    $this->actingAs($intruder)
        ->get(tenantUrl($other).'/sales/close?date='.$this->today)
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->where('report.net.value', 0)
            ->where('report.expected_cash.value', 0)
            ->where('report.invoice_count', 0)
        );
});
