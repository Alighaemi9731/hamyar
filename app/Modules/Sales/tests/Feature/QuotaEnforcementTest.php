<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\CRM\Models\Account;
use App\Modules\CRM\Models\Party;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Enums\MovementType;
use App\Modules\Inventory\Enums\UnitStatus;
use App\Modules\Inventory\Models\ProductUnit;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockLedger;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Platform\Services\SubscriptionResolver;
use App\Modules\Platform\Services\TenantProvisioner;
use App\Modules\Sales\Models\SalesInvoice;
use App\Support\Tenancy\TenantContext;

/**
 * The meter, at the place a shopkeeper actually meets it.
 *
 * ## Why this file exists separately from the guard's own suite
 *
 * `Platform/tests/Feature/Quota/*` proves the guard is correct: it counts, it refuses at
 * the ceiling, it is atomic under concurrency. Every one of those tests meters a synthetic
 * `quota.widgets` metric, deliberately, so that the guard's tests break when the guard
 * breaks rather than when Sales renames something.
 *
 * The cost of that isolation is that **none of them touch a route**. A guard that is
 * perfect and never called is indistinguishable, from the shop floor, from no guard at
 * all — and the phase's Definition of Done asks for the other half: that `POST /sales/pos`
 * itself refuses, that the refusal reaches the operator as something a React component can
 * render, and that nothing was written on the way.
 *
 * ## What each test is really asserting
 *
 * The interesting claim is never "the number went up". It is the **pairing**: a spent
 * credit always has a row behind it, and a row always has a spent credit behind it.
 * `QuotaGuard::consume()` runs inside the transaction that writes the invoice precisely so
 * that neither can happen without the other, and the only way to see that hold is to drive
 * the real endpoint and then look at both sides.
 */
beforeEach(function (): void {
    $this->tenant = Tenant::factory()->withDomain()->create();
    $this->url = appUrl();

    subscribe($this->tenant, 'pro');
    app(SubscriptionResolver::class)->forget();
    app(TenantProvisioner::class)->seedRoles($this->tenant);

    /** @var array{User, Warehouse, Account, ProductVariant, Party} $fixtures */
    $fixtures = inTenantContext($this->tenant, function (): array {
        $owner = User::factory()->create();
        $owner->assignRole('Owner');

        $warehouse = Warehouse::factory()->create(['is_sellable' => true, 'is_default' => true]);

        $cash = Account::factory()->create(['type' => Account::TYPE_CASH, 'is_default' => true]);
        Account::factory()->create(['type' => Account::TYPE_SALES]);

        $product = Product::factory()->create(['name' => 'شارژر', 'type' => 'standard']);
        $variant = ProductVariant::factory()->for($product)->create();

        app(StockLedger::class)->record($variant->id, $warehouse->id, 100, MovementType::Purchase, unitCost: 60_000_000);

        return [$owner, $warehouse, $cash, $variant, Party::factory()->create()];
    });

    [$this->owner, $this->warehouse, $this->cash, $this->variant, $this->party] = $fixtures;
});

afterEach(fn () => app(TenantContext::class)->forget());

/**
 * One sale on credit, through the real POS endpoint.
 *
 * Deliberately not asserting anything about the response — every test here wants to say
 * something different about it, and a helper that asserted success could not be used by
 * the tests about refusal.
 *
 * @return Illuminate\Testing\TestResponse<Illuminate\Http\Response>
 */
function sellOnce(string $action = 'finalise'): Illuminate\Testing\TestResponse
{
    /** @var Warehouse $warehouse */
    $warehouse = test()->warehouse;
    /** @var User $owner */
    $owner = test()->owner;
    /** @var ProductVariant $variant */
    $variant = test()->variant;
    /** @var Party $party */
    $party = test()->party;
    /** @var string $url */
    $url = test()->url;

    return test()->actingAs($owner)->post($url.'/sales/pos', [
        'branch_id' => $warehouse->branch_id,
        'party_id' => $party->id,
        'salesperson_id' => $owner->id,
        'unit' => 'rial',
        'action' => $action,
        'vat_applied' => false,
        'discount_amount' => 0,
        'shipping_amount' => 0,
        'notes' => null,
        'lines' => [
            ['unit_id' => null, 'variant_id' => $variant->id, 'quantity' => 1, 'unit_price' => 100_000_000, 'discount_amount' => 0],
        ],
        'payments' => [],
    ]);
}

function invoiceCount(): int
{
    /** @var Tenant $tenant */
    $tenant = test()->tenant;

    /** @var int $count */
    $count = inTenantContext($tenant, fn (): int => SalesInvoice::query()->count());

    return $count;
}

it('spends one invoice credit for one finalised sale', function (): void {
    sellOnce()->assertSessionHasNoErrors()->assertRedirect();

    expect(quotaUsed($this->tenant, 'sales.invoices'))->toBe(1);
});

it('refuses the sale that would cross the ceiling, and writes no invoice', function (): void {
    capQuota($this->tenant, 'sales.invoices', 1);

    sellOnce()->assertSessionHasNoErrors();
    expect(invoiceCount())->toBe(1);

    // The second one is the whole test. A shop at its cap must be *told*, not silently
    // given a form that does nothing — see CLAUDE.md on the operator pressing submit
    // twice with a customer at the counter.
    $blocked = sellOnce();

    $blocked->assertSessionHasErrors('quota');
    expect(invoiceCount())->toBe(1)
        ->and(quotaUsed($this->tenant, 'sales.invoices'))->toBe(1);
});

it('hands the operator something to render, not just an error string', function (): void {
    capQuota($this->tenant, 'sales.invoices', 0);

    sellOnce();

    /** @var array<string, mixed> $block */
    $block = session('quota_block') ?? [];

    // These are the keys `quota-block.tsx` reads. A refusal that reached the browser
    // without them would render an empty card, which is worse than a 500 because nobody
    // would report it.
    expect($block)->toHaveKeys(['metric', 'label', 'message', 'used', 'limit', 'resets_at', 'next_plan'])
        ->and($block['metric'])->toBe('sales.invoices')
        // Persian, not the exception's English. The whole reason `QuotaExceeded` no longer
        // extends `RuntimeException` is that a dozen controllers used to convert it into a
        // field message carrying exactly that English string.
        ->and($block['message'])->toBeString()->not->toContain('Quota exceeded');
});

it('names the cheapest plan that would actually fit', function (): void {
    capQuota($this->tenant, 'sales.invoices', 0);

    sellOnce();

    /** @var array{next_plan?: array{code?: string, due?: array<string, mixed>}} $block */
    $block = session('quota_block') ?? [];

    // Not "the next one up the list" — the cheapest rung whose limit clears the wall the
    // shop just hit. Aiming a shop at a plan that would block it again tomorrow is how an
    // upsell becomes a refund.
    expect($block['next_plan']['code'] ?? null)->toBe('enterprise')
        // And it quotes the prorated amount, not the sticker price: the shop is mid-period
        // on «حرفه‌ای» and is owed credit for the days it already paid for (ADR 0006).
        ->and($block['next_plan']['due'] ?? null)->toBeArray();
});

it('spends nothing when the sale fails inside the transaction', function (): void {
    /** @var Warehouse $warehouse */
    $warehouse = $this->warehouse;

    /** @var ProductUnit $unit */
    $unit = inTenantContext($this->tenant, fn (): ProductUnit => ProductUnit::factory()
        ->for($this->variant, 'variant')
        ->create([
            'warehouse_id' => $warehouse->id,
            'status' => UnitStatus::Sold,
            'cost' => 40_000_000,
        ]));

    // A phone already sold. `FinaliseInvoice` locks the unit, sees the status and throws
    // `UnitNoLongerAvailable` — inside the same transaction the credit was consumed in.
    // This is the race a second till wins, and the shop that loses it must not be charged
    // a credit for a sale it did not make.
    $this->actingAs($this->owner)->post($this->url.'/sales/pos', [
        'branch_id' => $warehouse->branch_id,
        'party_id' => $this->party->id,
        'salesperson_id' => $this->owner->id,
        'unit' => 'rial',
        'action' => 'finalise',
        'vat_applied' => false,
        'discount_amount' => 0,
        'shipping_amount' => 0,
        'notes' => null,
        'lines' => [
            ['unit_id' => $unit->id, 'variant_id' => $this->variant->id, 'quantity' => 1, 'unit_price' => 100_000_000, 'discount_amount' => 0],
        ],
        'payments' => [],
    ])->assertSessionHasErrors('lines');

    expect(invoiceCount())->toBe(0)
        // No row at all, rather than a row reading zero: the two are different claims and
        // only the first one says the transaction really rolled back.
        ->and(quotaRowExists($this->tenant, 'sales.invoices'))->toBeFalse();
});

it('meters a quote against quotes, never against invoices', function (): void {
    sellOnce('quote')->assertSessionHasNoErrors();

    expect(quotaUsed($this->tenant, 'sales.quotes'))->toBe(1)
        // A quote is not a sale. Charging it to the invoice credit would make a shop pay
        // for the negotiation as well as the deal — and «پیش‌فاکتور» is exactly the thing
        // a shop does ten of to close one.
        ->and(quotaRowExists($this->tenant, 'sales.invoices'))->toBeFalse();
});

it('blocks a second time in the same month without falling over', function (): void {
    capQuota($this->tenant, 'sales.invoices', 0);

    sellOnce()->assertSessionHasErrors('quota');

    /*
    | The second block, and it used to be a white 500.
    |
    | The exception renderer records a `usage_events` row every time a shop is blocked, and
    | a unique index on `(tenant, metric, period, kind)` means the second block of the month
    | is always a duplicate. `UsageEvents::write()` catches that — except the insert ran
    | inside `transaction()` with a `runAsPlatform()` wrapped *around* it, and
    | `runAsPlatform()` restores its flag in a `finally`. A `finally` is not a `catch`: it
    | fired while the Postgres transaction was still aborted, `set_config` died with 25P02,
    | and that exception replaced the duplicate-key one so the catch never matched.
    |
    | The shop-visible cost was the worst possible shape: the block and its upgrade button
    | worked exactly once per metric per month, and every attempt after that — the operator
    | pressing submit again with a customer at the counter — was a blank error page. Every
    | enforcement test in this repository blocked only once, which is why none of them saw it.
    */
    sellOnce()->assertSessionHasErrors('quota');

    /** @var array<string, mixed> $block */
    $block = session('quota_block') ?? [];

    expect($block['metric'] ?? null)->toBe('sales.invoices')
        ->and(invoiceCount())->toBe(0);
});
