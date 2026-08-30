<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\CRM\Models\Account;
use App\Modules\CRM\Models\Party;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Models\ProductUnit;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Platform\Services\SubscriptionResolver;
use App\Modules\Platform\Services\TenantProvisioner;
use App\Modules\Purchasing\Models\PurchaseInvoice;
use App\Modules\Purchasing\Models\PurchaseUnitItem;
use App\Support\Money;
use App\Support\Tenancy\TenantContext;
use Database\Factories\ProductUnitFactory;

/**
 * The meter on the goods-in door, at the place a warehousekeeper actually meets it.
 *
 * ## Why this file exists separately from the guard's own suite
 *
 * `Platform/tests/Feature/Quota/*` proves the guard is correct against a synthetic
 * `quota.widgets` metric — it counts, it refuses at the ceiling, it is atomic under
 * concurrency. None of those tests touch a route, deliberately, so that they break when
 * the guard breaks rather than when Purchasing renames something. This file is the other
 * half: that `POST /purchasing/invoices/{invoice}/receive` itself refuses, that the
 * refusal reaches the operator as something a React component can render, and that a
 * refused delivery leaves behind neither a device nor a credit.
 *
 * ## Purchasing is the module that spends TWO credits, and the pairing is the point
 *
 * A receipt is one `purchasing.invoices` — the document — and `n` of `inventory.units`,
 * one per handset that entered the shop. Both are consumed inside the same transaction
 * that writes the stock, the passports and the supplier's credit, and the second is
 * consumed as **one batched spend of n** rather than n spends of one. A twenty-phone
 * delivery refused halfway would leave ten phones received on a document that says
 * twenty, and «همه یا هیچ» is the only honest shape for a delivery: the shop either took
 * it in or it did not.
 *
 * ## Where the meter is NOT
 *
 * Opening a draft (`POST /purchasing/invoices`) and pasting IMEIs onto it
 * (`POST /purchasing/invoices/{invoice}/imeis`) spend **nothing**, and two tests below
 * pin that down. Both write rows, so it would be easy to read the golden rule as
 * requiring a `consume()` in each; they are not metered because neither is stock yet. A
 * draft is a person typing, and a shop must be able to prepare a delivery it cannot
 * afford to receive — otherwise the credit is spent on the typing rather than on the
 * trade, and an abandoned draft costs the shop a phone it never took in.
 */
beforeEach(function (): void {
    $this->tenant = Tenant::factory()->withDomain()->create();
    $this->url = appUrl();

    subscribe($this->tenant, 'pro');
    app(SubscriptionResolver::class)->forget();
    app(TenantProvisioner::class)->seedRoles($this->tenant);

    /** @var array{User, Warehouse, ProductVariant, Party} $fixtures */
    $fixtures = inTenantContext($this->tenant, function (): array {
        $owner = User::factory()->create();
        $owner->assignRole('Owner');

        $warehouse = Warehouse::factory()->create(['name' => 'انبار مرکزی']);
        $product = Product::factory()->serialized()->create(['name' => 'آیفون ۱۵ پرو']);
        $variant = ProductVariant::factory()->for($product)->create();

        // Received stock debits inventory and credits the supplier. Without this account
        // the receipt refuses rather than posting the supplier against themselves — which
        // is what the last test in this file turns into a fixture.
        Account::factory()->create(['type' => Account::TYPE_INVENTORY, 'name' => 'ارزش موجودی انبار']);

        return [$owner, $warehouse, $variant, Party::factory()->supplier()->create()];
    });

    [$this->owner, $this->warehouse, $this->variant, $this->supplier] = $fixtures;
});

afterEach(fn () => app(TenantContext::class)->forget());

/**
 * A draft shipment with `$devices` handsets pasted onto it, built through the real
 * screens rather than the factory.
 *
 * Going through the routes matters here: the whole question this file asks is which
 * endpoint spends what, and a fixture that inserts `PurchaseUnitItem` rows directly would
 * answer it by assumption.
 *
 * @return array{PurchaseInvoice, list<string>}
 */
function openMeteredShipment(int $devices = 1): array
{
    /** @var User $owner */
    $owner = test()->owner;
    /** @var Warehouse $warehouse */
    $warehouse = test()->warehouse;
    /** @var ProductVariant $variant */
    $variant = test()->variant;
    /** @var Party $supplier */
    $supplier = test()->supplier;
    /** @var Tenant $tenant */
    $tenant = test()->tenant;
    /** @var string $url */
    $url = test()->url;

    test()->actingAs($owner)->post($url.'/purchasing/invoices', [
        'warehouse_id' => $warehouse->id,
        'party_id' => $supplier->id,
    ])->assertRedirect();

    /** @var PurchaseInvoice $invoice */
    $invoice = inTenantContext($tenant, fn (): PurchaseInvoice => PurchaseInvoice::query()->latest('id')->firstOrFail());

    /** @var list<string> $imeis */
    $imeis = array_map(static fn (): string => ProductUnitFactory::validImei(), range(1, $devices));

    test()->actingAs($owner)->post($url."/purchasing/invoices/{$invoice->id}/imeis", [
        'imeis' => implode("\n", $imeis),
        'product_variant_id' => $variant->id,
        'amount' => 50_000_000,
        'unit' => Money::UNIT_RIAL,
        'condition' => 'new',
    ])->assertRedirect();

    return [$invoice, $imeis];
}

/**
 * Receive it — the one endpoint in this module that spends anything.
 *
 * Deliberately asserts nothing about the response: every test here wants to say something
 * different about it, and a helper that asserted success could not be used by the tests
 * about refusal.
 *
 * @return Illuminate\Testing\TestResponse<Illuminate\Http\Response>
 */
function receiveShipmentNow(PurchaseInvoice $invoice): Illuminate\Testing\TestResponse
{
    /** @var User $owner */
    $owner = test()->owner;
    /** @var string $url */
    $url = test()->url;

    return test()->actingAs($owner)->post($url."/purchasing/invoices/{$invoice->id}/receive");
}

/**
 * How many handsets are actually in the register.
 *
 * The counter and this number are the two halves of every claim in this file: a spent
 * credit must have a device behind it, and a device must have a spent credit behind it.
 */
function unitsInStock(): int
{
    /** @var Tenant $tenant */
    $tenant = test()->tenant;

    /** @var int $count */
    $count = inTenantContext($tenant, fn (): int => ProductUnit::query()->count());

    return $count;
}

function shipmentStatus(PurchaseInvoice $invoice): string
{
    /** @var Tenant $tenant */
    $tenant = test()->tenant;

    /** @var string $status */
    $status = inTenantContext($tenant, fn (): string => PurchaseInvoice::query()->findOrFail($invoice->id)->status);

    return $status;
}

/* ------------------------------------------------------------- happy path -- */

it('spends one purchase-invoice credit for one received shipment', function (): void {
    [$invoice] = openMeteredShipment(1);

    receiveShipmentNow($invoice)->assertSessionHasNoErrors()->assertRedirect();

    expect(quotaUsed($this->tenant, 'purchasing.invoices'))->toBe(1);
});

it('spends one device credit per handset, in a single batched consume', function (): void {
    [$invoice, $imeis] = openMeteredShipment(10);

    // Nothing yet. Ten `purchase_unit_items` rows exist and not one credit has moved,
    // because a pasted IMEI is a person typing and a received IMEI is a phone in a
    // drawer. The absence of the row — rather than a row reading zero — is what says the
    // guard was never called on the paste path at all.
    expect(quotaRowExists($this->tenant, 'inventory.units'))->toBeFalse()
        ->and(quotaRowExists($this->tenant, 'purchasing.invoices'))->toBeFalse();

    receiveShipmentNow($invoice)->assertSessionHasNoErrors()->assertRedirect();

    expect(quotaUsed($this->tenant, 'inventory.units'))->toBe(10)
        // Ten devices on one document is one document. Charging the receipt per line
        // would make a shop that buys in bulk pay twice for the same act of buying.
        ->and(quotaUsed($this->tenant, 'purchasing.invoices'))->toBe(1)
        ->and(unitsInStock())->toBe(10);

    // And the ten spent credits are the ten handsets, not ten of something else.
    inTenantContext($this->tenant, function () use ($imeis): void {
        expect(ProductUnit::query()->whereIn('imei1', $imeis)->count())->toBe(10);
    });
});

/* ---------------------------------------------------------- at the ceiling -- */

it('refuses the receipt that would cross the ceiling, and takes in no stock', function (): void {
    capQuota($this->tenant, 'purchasing.invoices', 1);

    [$first] = openMeteredShipment(1);
    receiveShipmentNow($first)->assertSessionHasNoErrors();

    [$second] = openMeteredShipment(1);

    // The second one is the whole test. A shop at its cap must be *told*, not silently
    // handed a button that does nothing — see CLAUDE.md on the operator pressing submit
    // twice with a customer waiting.
    receiveShipmentNow($second)->assertSessionHasErrors('quota');

    expect(quotaUsed($this->tenant, 'purchasing.invoices'))->toBe(1)
        ->and(unitsInStock())->toBe(1)
        // Still a draft, so the same delivery can be received the moment the shop
        // upgrades. A refusal that closed the document would cost the operator the
        // twenty IMEIs they had already pasted.
        ->and(shipmentStatus($second))->toBe(PurchaseInvoice::STATUS_DRAFT);
});

it('refuses a whole delivery rather than the part of it that would fit', function (): void {
    // Twelve device credits, five already spent this month, and a ten-phone shipment
    // arriving. Five of them fit.
    capQuota($this->tenant, 'inventory.units', 12);
    spendQuota($this->tenant, 'inventory.units', 5);

    [$invoice] = openMeteredShipment(10);

    receiveShipmentNow($invoice)->assertSessionHasErrors('quota');

    // Read the SQL in `DatabaseQuotaGuard` for why this is what happens rather than a
    // partial spend: the bounded statement's update arm is guarded by
    // `used + EXCLUDED.used <= limit`, so a batch either lands whole or returns no row
    // at all. Nothing anywhere takes the five that fit.
    expect(quotaUsed($this->tenant, 'inventory.units'))->toBe(5)
        ->and(unitsInStock())->toBe(0)
        ->and(shipmentStatus($invoice))->toBe(PurchaseInvoice::STATUS_DRAFT);

    // The other half of all-or-nothing, and the reason both credits sit in one
    // transaction: `purchasing.invoices` was consumed successfully a few lines before
    // the device batch was refused, and it went back with everything else. A document
    // credit spent on a delivery the shop never took in is a charge for nothing.
    expect(quotaRowExists($this->tenant, 'purchasing.invoices'))->toBeFalse();

    inTenantContext($this->tenant, function (): void {
        // The draft's ten lines are untouched — they were never what was refused.
        expect(PurchaseUnitItem::query()->count())->toBe(10);
    });
});

it('hands the operator something to render, not just an error string', function (): void {
    capQuota($this->tenant, 'purchasing.invoices', 0);

    [$invoice] = openMeteredShipment(1);

    receiveShipmentNow($invoice);

    /** @var array<string, mixed> $block */
    $block = session('quota_block') ?? [];

    // These are the keys `quota-block.tsx` reads. A refusal that reached the browser
    // without them would render an empty card, which is worse than a 500 because nobody
    // would report it.
    expect($block)->toHaveKeys(['metric', 'label', 'message', 'used', 'limit', 'resets_at', 'next_plan'])
        ->and($block['metric'])->toBe('purchasing.invoices')
        // Persian, not the exception's English. `QuotaExceeded` stopped extending
        // `RuntimeException` precisely because a dozen controllers — this module's
        // `receive()` among them — catch that class and turn it into a field message
        // carrying the raw `Quota exceeded for [...]` string.
        ->and($block['message'])->toBeString()->not->toContain('Quota exceeded')
        // The cheapest rung that clears the wall the shop just hit, not simply the next
        // one up: aiming a shop at a plan that would block it again tomorrow is how an
        // upsell becomes a refund.
        ->and($block['next_plan'])->toBeArray();

    /** @var array{code?: string} $next */
    $next = $block['next_plan'];
    expect($next['code'] ?? null)->toBe('enterprise');
});

it('tells a blocked delivery how much of it would fit', function (): void {
    capQuota($this->tenant, 'inventory.units', 4);

    [$invoice] = openMeteredShipment(10);

    receiveShipmentNow($invoice)->assertSessionHasErrors('quota');

    /** @var array<string, mixed> $block */
    $block = session('quota_block') ?? [];

    // A bulk refusal is a different sentence from a full credit. «سهمیه‌ات تمام شد» is
    // not actionable when the operator is standing over an open box; "you asked for ten
    // and have four left" tells them how many to split off and receive now.
    expect($block['metric'])->toBe('inventory.units')
        ->and($block['requested'] ?? null)->toBe(10)
        ->and($block['limit'] ?? null)->toBe(4)
        ->and($block['message'])->toBeString()->not->toContain('Quota exceeded');

    /** @var string $message */
    $message = $block['message'];
    // Persian digits, because every number on every screen in this product is Persian
    // and a Latin «10» in the middle of a Persian sentence is the tell that a string was
    // assembled somewhere that forgot.
    expect($message)->toContain('۱۰')->toContain('۴');
});

/* ----------------------------------------------------- failing for itself -- */

it('spends nothing when the receipt fails inside the transaction', function (): void {
    [$invoice] = openMeteredShipment(3);

    // A shop whose chart of accounts has no inventory account. `postToSupplierLedger`
    // refuses rather than posting the supplier's credit against itself — which would
    // balance arithmetically and record nothing, the worst kind of wrong because the
    // books look fine. It throws AFTER both credits have been consumed, inside the same
    // transaction, which is the only reason this test can say anything the guard's own
    // suite cannot.
    inTenantContext($this->tenant, function (): void {
        Account::query()->where('type', Account::TYPE_INVENTORY)->delete();
    });

    receiveShipmentNow($invoice)->assertSessionHasErrors('receive');

    expect(unitsInStock())->toBe(0)
        ->and(shipmentStatus($invoice))->toBe(PurchaseInvoice::STATUS_DRAFT)
        // No row at all, rather than a row reading zero: the two are different claims and
        // only the first one says the transaction really rolled back.
        ->and(quotaRowExists($this->tenant, 'purchasing.invoices'))->toBeFalse()
        ->and(quotaRowExists($this->tenant, 'inventory.units'))->toBeFalse();
});

/* ---------------------------------------------------------- no double-spend -- */

it('does not spend a second document credit when the same shipment is received twice', function (): void {
    [$invoice] = openMeteredShipment(2);

    receiveShipmentNow($invoice)->assertSessionHasNoErrors()->assertRedirect();

    // Received twice would double the stock and the debt, so the controller refuses on
    // state before the service is reached. Somebody double-clicking «دریافت» on a slow
    // connection is not a rare event, and it must not cost them a credit for a delivery
    // that arrived once.
    receiveShipmentNow($invoice)->assertForbidden();

    expect(quotaUsed($this->tenant, 'purchasing.invoices'))->toBe(1)
        ->and(quotaUsed($this->tenant, 'inventory.units'))->toBe(2)
        ->and(unitsInStock())->toBe(2);
});

it('spends nothing for opening a draft or pasting IMEIs onto it', function (): void {
    [$invoice] = openMeteredShipment(6);

    // Six devices are written down and none of them exist yet. A shop planning next
    // week's order — or abandoning one — pays for neither, and the credit is spent on
    // the trade rather than on the typing.
    inTenantContext($this->tenant, function (): void {
        expect(PurchaseUnitItem::query()->count())->toBe(6);
    });

    expect(quotaRowExists($this->tenant, 'purchasing.invoices'))->toBeFalse()
        ->and(quotaRowExists($this->tenant, 'inventory.units'))->toBeFalse()
        ->and(shipmentStatus($invoice))->toBe(PurchaseInvoice::STATUS_DRAFT);
});
