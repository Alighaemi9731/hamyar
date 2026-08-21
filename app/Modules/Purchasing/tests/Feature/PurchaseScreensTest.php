<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\CRM\Models\Account;
use App\Modules\CRM\Models\Party;
use App\Modules\CRM\Services\LedgerService;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Models\ProductUnit;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockLedger;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Platform\Services\PlanCatalogueSeeder;
use App\Modules\Platform\Services\SubscriptionResolver;
use App\Modules\Platform\Services\TenantProvisioner;
use App\Modules\Purchasing\Models\PurchaseInvoice;
use App\Modules\Purchasing\Models\PurchaseUnitItem;
use App\Support\Money;
use App\Support\Tenancy\TenantContext;
use Database\Factories\ProductUnitFactory;

/**
 * The intake screens: opening a shipment, pasting IMEIs, and receiving.
 *
 * The tests that matter here are about the parse/commit boundary. A screen that shows
 * verdicts is only half the feature — the other half is that the server refuses to
 * trust them.
 */
beforeEach(function (): void {
    app(PlanCatalogueSeeder::class)->sync();

    $this->tenant = Tenant::factory()->withDomain()->create();
    $this->url = appUrl();

    subscribe($this->tenant, 'pro');
    app(SubscriptionResolver::class)->forget();

    app(TenantProvisioner::class)->seedRoles($this->tenant);

    [$this->keeper, $this->seller, $this->owner] = app(TenantContext::class)->runFor($this->tenant, function (): array {
        $keeper = User::factory()->create();
        $keeper->assignRole('Warehousekeeper');

        $seller = User::factory()->create();
        $seller->assignRole('Salesperson');

        $owner = User::factory()->create();
        $owner->assignRole('Owner');

        return [$keeper, $seller, $owner];
    });

    $this->inTenant = fn (Closure $callback): mixed => inTenantContext($this->tenant, $callback);

    /** @var array{Warehouse, ProductVariant, Party} $fixtures */
    $fixtures = inTenantContext($this->tenant, function (): array {
        $warehouse = Warehouse::factory()->create(['name' => 'انبار مرکزی']);
        $product = Product::factory()->serialized()->create(['name' => 'آیفون ۱۵ پرو']);
        $variant = ProductVariant::factory()->for($product)->create();
        $supplier = Party::factory()->supplier()->create();

        // Received stock debits inventory; without the account the posting would net
        // the supplier against itself and record nothing.
        Account::factory()->create(['type' => Account::TYPE_INVENTORY]);

        return [$warehouse, $variant, $supplier];
    });

    [$this->warehouse, $this->variant, $this->supplier] = $fixtures;
});

afterEach(fn () => app(TenantContext::class)->forget());

/**
 * Ten Luhn-valid IMEIs, one per line — the paste the DoD is written around.
 *
 * @return list<string>
 */
function tenImeis(): array
{
    return array_map(static fn (): string => ProductUnitFactory::validImei(), range(1, 10));
}

/**
 * The draft the last `POST /purchasing/invoices` produced.
 */
function openDraft(): PurchaseInvoice
{
    /** @var Tenant $tenant */
    $tenant = test()->tenant;

    /** @var PurchaseInvoice $invoice */
    $invoice = inTenantContext($tenant, fn () => PurchaseInvoice::query()->latest('id')->firstOrFail());

    return $invoice;
}

/* ------------------------------------------------------------------- open -- */

it('opens a numbered draft against a warehouse', function (): void {
    $this->actingAs($this->keeper)
        ->post($this->url.'/purchasing/invoices', [
            'warehouse_id' => $this->warehouse->id,
            'party_id' => $this->supplier->id,
        ])
        ->assertRedirect();

    ($this->inTenant)(function (): void {
        $invoice = PurchaseInvoice::query()->firstOrFail();

        expect($invoice->status)->toBe(PurchaseInvoice::STATUS_DRAFT);
        // Row-locked per branch, never MAX+1.
        expect($invoice->number)->toStartWith('PUR-');
        expect($invoice->branch_id)->toBe($this->warehouse->branch_id);
    });
});

it('refuses to open a shipment for a Salesperson', function (): void {
    $this->actingAs($this->seller)
        ->post($this->url.'/purchasing/invoices', ['warehouse_id' => $this->warehouse->id])
        ->assertForbidden();
});

/* ------------------------------------------------------------- imei paste -- */

it('reports a verdict per pasted line without writing anything', function (): void {
    $existing = ($this->inTenant)(fn () => ProductUnit::factory()->for($this->variant, 'variant')->create());

    $this->actingAs($this->keeper)->post($this->url.'/purchasing/invoices', [
        'warehouse_id' => $this->warehouse->id,
    ]);

    $invoice = openDraft();

    $good = ProductUnitFactory::validImei();

    $response = $this->actingAs($this->keeper)
        ->postJson($this->url."/purchasing/invoices/{$invoice->id}/imeis/parse", [
            // One good, one mistyped check digit, one repeated, one already ours —
            // and separators deliberately mixed, because a supplier's WhatsApp message
            // uses commas and a scanner emits newlines.
            'imeis' => implode("\n", [$good, '356938035643801', $good, $existing->imei1]),
        ])
        ->assertOk();

    expect($response->json('counts'))->toBe([
        'accepted' => 1,
        'invalid' => 1,
        'duplicate_in_batch' => 1,
        'exists' => 1,
    ]);
    expect($response->json('clean'))->toBeFalse();
    // The already-registered line carries the device id so the screen can link to it.
    expect($response->json('lines.3.unit_id'))->toBe($existing->id);

    ($this->inTenant)(fn () => expect(PurchaseUnitItem::query()->count())->toBe(0));
});

it('accepts Persian digits and any separator in one paste', function (): void {
    $this->actingAs($this->keeper)->post($this->url.'/purchasing/invoices', [
        'warehouse_id' => $this->warehouse->id,
    ]);

    $invoice = openDraft();

    $imeis = tenImeis();
    $persian = strtr($imeis[0], ['0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴',
        '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹']);

    $blob = $persian.', '.$imeis[1]."\t".$imeis[2].";\n".$imeis[3];

    $this->actingAs($this->keeper)
        ->postJson($this->url."/purchasing/invoices/{$invoice->id}/imeis/parse", ['imeis' => $blob])
        ->assertOk()
        ->assertJsonPath('counts.accepted', 4)
        ->assertJsonPath('clean', true);
});

it('refuses to commit a dirty batch unless the operator skips the bad rows', function (): void {
    $this->actingAs($this->keeper)->post($this->url.'/purchasing/invoices', [
        'warehouse_id' => $this->warehouse->id,
    ]);

    $invoice = openDraft();

    $payload = fn (bool $skip): array => [
        'imeis' => ProductUnitFactory::validImei()."\n356938035643801",
        'product_variant_id' => $this->variant->id,
        'amount' => 78_000_000,
        'unit' => Money::UNIT_TOMAN,
        'condition' => 'new',
        'skip_rejected' => $skip,
    ];

    // A half-received shipment is how stock stops reconciling, so nothing is written
    // until the batch is clean or someone says, in as many words, to skip.
    $this->actingAs($this->keeper)
        ->post($this->url."/purchasing/invoices/{$invoice->id}/imeis", $payload(false))
        ->assertSessionHasErrors('imeis');

    ($this->inTenant)(fn () => expect(PurchaseUnitItem::query()->count())->toBe(0));

    $this->actingAs($this->keeper)
        ->post($this->url."/purchasing/invoices/{$invoice->id}/imeis", $payload(true))
        ->assertRedirect();

    ($this->inTenant)(fn () => expect(PurchaseUnitItem::query()->count())->toBe(1));
});

it('re-parses on commit instead of trusting the browser', function (): void {
    // The screen's verdicts are a preview. Between the preview and the commit another
    // till can register a handset, and a client that decides which IMEIs are
    // acceptable is a client that can register the same device twice.
    $this->actingAs($this->keeper)->post($this->url.'/purchasing/invoices', [
        'warehouse_id' => $this->warehouse->id,
    ]);

    $invoice = openDraft();
    $imei = ProductUnitFactory::validImei();

    $this->actingAs($this->keeper)
        ->postJson($this->url."/purchasing/invoices/{$invoice->id}/imeis/parse", ['imeis' => $imei])
        ->assertJsonPath('clean', true);

    // …and now that IMEI turns up in the register, after the preview said it was fine.
    ($this->inTenant)(fn () => ProductUnit::factory()->for($this->variant, 'variant')->create(['imei1' => $imei]));

    $this->actingAs($this->keeper)
        ->post($this->url."/purchasing/invoices/{$invoice->id}/imeis", [
            'imeis' => $imei,
            'product_variant_id' => $this->variant->id,
            'amount' => 1_000_000,
            'unit' => Money::UNIT_RIAL,
            'condition' => 'new',
        ])
        ->assertSessionHasErrors('imeis');

    ($this->inTenant)(fn () => expect(PurchaseUnitItem::query()->count())->toBe(0));
});

/* ---------------------------------------------------------------- receive -- */

it('receives ten pasted handsets into stock with their landed cost', function (): void {
    $this->actingAs($this->keeper)->post($this->url.'/purchasing/invoices', [
        'warehouse_id' => $this->warehouse->id,
        'party_id' => $this->supplier->id,
    ]);

    $invoice = openDraft();
    $imeis = tenImeis();

    $this->actingAs($this->keeper)
        ->post($this->url."/purchasing/invoices/{$invoice->id}/imeis", [
            'imeis' => implode("\n", $imeis),
            'product_variant_id' => $this->variant->id,
            'amount' => 50_000_000,
            'unit' => Money::UNIT_TOMAN,
            'condition' => 'new',
        ])
        ->assertRedirect();

    // 10,000,000 rial of freight over ten devices: 1,000,000 each.
    $this->actingAs($this->keeper)
        ->post($this->url."/purchasing/invoices/{$invoice->id}/landed-costs", [
            'type' => 'freight',
            'amount' => 10_000_000,
            'unit' => Money::UNIT_RIAL,
            'allocation' => 'by_quantity',
        ])
        ->assertRedirect();

    $this->actingAs($this->keeper)
        ->post($this->url."/purchasing/invoices/{$invoice->id}/receive")
        ->assertRedirect();

    ($this->inTenant)(function () use ($imeis): void {
        $units = ProductUnit::query()->whereIn('imei1', $imeis)->get();

        expect($units)->toHaveCount(10);
        expect($units->first()?->cost)->toBe(501_000_000)->toBeRial();
        expect($units->first()?->warehouse_id)->toBe($this->warehouse->id);

        // Serialized stock deliberately writes NO quantity movement: a handset counted
        // in both the unit register and the quantity ledger is counted twice.
        expect(app(StockLedger::class)->onHand($this->variant->id, $this->warehouse->id))->toBe(0);

        // The shop now owes the supplier what the shipment cost: ten devices at
        // 500,000,000 rial plus 10,000,000 of freight. Negative means the shop owes.
        expect(app(LedgerService::class)->partyBalance($this->supplier->refresh()))
            ->toBe(-5_010_000_000);
    });
});

it('refuses a second receipt of the same shipment', function (): void {
    $this->actingAs($this->keeper)->post($this->url.'/purchasing/invoices', [
        'warehouse_id' => $this->warehouse->id,
    ]);

    $invoice = openDraft();

    $this->actingAs($this->keeper)->post($this->url."/purchasing/invoices/{$invoice->id}/imeis", [
        'imeis' => ProductUnitFactory::validImei(),
        'product_variant_id' => $this->variant->id,
        'amount' => 1_000_000,
        'unit' => Money::UNIT_RIAL,
        'condition' => 'new',
    ]);

    $this->actingAs($this->keeper)->post($this->url."/purchasing/invoices/{$invoice->id}/receive");

    // Received twice would double the stock and double the debt.
    $this->actingAs($this->keeper)
        ->post($this->url."/purchasing/invoices/{$invoice->id}/receive")
        ->assertForbidden();

    ($this->inTenant)(fn () => expect(ProductUnit::query()->count())->toBe(1));
});

it('will not edit a shipment that has already been received', function (): void {
    $this->actingAs($this->keeper)->post($this->url.'/purchasing/invoices', [
        'warehouse_id' => $this->warehouse->id,
    ]);

    $invoice = openDraft();

    $this->actingAs($this->keeper)->post($this->url."/purchasing/invoices/{$invoice->id}/imeis", [
        'imeis' => ProductUnitFactory::validImei(),
        'product_variant_id' => $this->variant->id,
        'amount' => 1_000_000,
        'unit' => Money::UNIT_RIAL,
        'condition' => 'new',
    ]);

    $this->actingAs($this->keeper)->post($this->url."/purchasing/invoices/{$invoice->id}/receive");

    // The document is now stock, a debt and an IMEI passport. Editing it would rewrite
    // all three.
    $this->actingAs($this->keeper)
        ->post($this->url."/purchasing/invoices/{$invoice->id}/imeis", [
            'imeis' => ProductUnitFactory::validImei(),
            'product_variant_id' => $this->variant->id,
            'amount' => 1_000_000,
            'unit' => Money::UNIT_RIAL,
            'condition' => 'new',
        ])
        ->assertForbidden();
});

/* --------------------------------------------------------------- returns -- */

it('sends a handset back and reverses exactly what it cost', function (): void {
    $this->actingAs($this->keeper)->post($this->url.'/purchasing/invoices', [
        'warehouse_id' => $this->warehouse->id,
        'party_id' => $this->supplier->id,
    ]);

    $invoice = openDraft();

    $this->actingAs($this->keeper)->post($this->url."/purchasing/invoices/{$invoice->id}/imeis", [
        'imeis' => ProductUnitFactory::validImei(),
        'product_variant_id' => $this->variant->id,
        'amount' => 40_000_000,
        'unit' => Money::UNIT_RIAL,
        'condition' => 'new',
    ]);

    $this->actingAs($this->keeper)->post($this->url."/purchasing/invoices/{$invoice->id}/receive");

    $unit = ($this->inTenant)(fn () => ProductUnit::query()->firstOrFail());

    $this->actingAs($this->keeper)
        ->post($this->url."/purchasing/invoices/{$invoice->id}/returns", [
            'reason' => 'صفحه‌نمایش خط داشت',
            'lines' => [['unit_id' => $unit->id]],
        ])
        ->assertRedirect();

    ($this->inTenant)(function () use ($unit): void {
        // Written off, never deleted: the device existed here and its passport has to
        // keep saying so.
        expect($unit->refresh()->status->value)->toBe('written_off');
        expect(app(LedgerService::class)->partyBalance($this->supplier->refresh()))->toBe(0);
    });
});

/* -------------------------------------------------------------- isolation -- */

it('does not expose another shop shipment', function (): void {
    $other = Tenant::factory()->withDomain()->create();

    $foreign = app(TenantContext::class)->runFor($other, function (): PurchaseInvoice {
        $warehouse = Warehouse::factory()->create();

        return PurchaseInvoice::query()->create([
            'branch_id' => $warehouse->branch_id,
            'warehouse_id' => $warehouse->id,
            'number' => 'PUR-000001',
            'status' => PurchaseInvoice::STATUS_DRAFT,
        ]);
    });

    $this->actingAs($this->keeper)
        ->get($this->url."/purchasing/invoices/{$foreign->id}")
        ->assertNotFound();

    $this->actingAs($this->keeper)
        ->post($this->url."/purchasing/invoices/{$foreign->id}/receive")
        ->assertNotFound();
})->group('isolation');

it('refuses an Owner the same closed-document edits it refuses everyone else', function (): void {
    // The regression this exists for: the Owner `Gate::before` override returns true
    // before any policy method runs, so a document-state check written in the policy is
    // silently dead for exactly the person most likely to click the button. The whole
    // suite used a Warehousekeeper, which is what hid it.
    $this->actingAs($this->owner)->post($this->url.'/purchasing/invoices', [
        'warehouse_id' => $this->warehouse->id,
    ]);

    $invoice = openDraft();

    $this->actingAs($this->owner)->post($this->url."/purchasing/invoices/{$invoice->id}/imeis", [
        'imeis' => ProductUnitFactory::validImei(),
        'product_variant_id' => $this->variant->id,
        'amount' => 1_000_000,
        'unit' => Money::UNIT_RIAL,
        'condition' => 'new',
    ]);

    $this->actingAs($this->owner)->post($this->url."/purchasing/invoices/{$invoice->id}/receive");

    // Well-formed payloads on purpose: a request that fails validation would be
    // refused for the wrong reason and would prove nothing about the state guard.
    $attempts = [
        "/purchasing/invoices/{$invoice->id}/receive" => [],
        "/purchasing/invoices/{$invoice->id}/imeis" => [
            'imeis' => ProductUnitFactory::validImei(),
            'product_variant_id' => $this->variant->id,
            'amount' => 1_000_000,
            'unit' => Money::UNIT_RIAL,
            'condition' => 'new',
        ],
        "/purchasing/invoices/{$invoice->id}/landed-costs" => [
            'type' => 'freight',
            'amount' => 1_000_000,
            'unit' => Money::UNIT_RIAL,
            'allocation' => 'by_value',
        ],
    ];

    foreach ($attempts as $path => $payload) {
        $this->actingAs($this->owner)->post($this->url.$path, $payload)->assertForbidden();
    }

    ($this->inTenant)(fn () => expect(ProductUnit::query()->count())->toBe(1));
});

it('stops offering the receive button once the shipment is stock', function (): void {
    $this->actingAs($this->owner)->post($this->url.'/purchasing/invoices', [
        'warehouse_id' => $this->warehouse->id,
    ]);

    $invoice = openDraft();

    $this->actingAs($this->owner)
        ->get($this->url."/purchasing/invoices/{$invoice->id}")
        ->assertInertia(fn ($page) => $page->where('can.receive', true)->where('can.edit', true));

    $this->actingAs($this->owner)->post($this->url."/purchasing/invoices/{$invoice->id}/imeis", [
        'imeis' => ProductUnitFactory::validImei(),
        'product_variant_id' => $this->variant->id,
        'amount' => 1_000_000,
        'unit' => Money::UNIT_RIAL,
        'condition' => 'new',
    ]);

    $this->actingAs($this->owner)->post($this->url."/purchasing/invoices/{$invoice->id}/receive");

    // An Owner is allowed the *permission* and refused the *state*; the screen has to
    // reflect the combination or it shows a button every layer beneath it will reject.
    $this->actingAs($this->owner)
        ->get($this->url."/purchasing/invoices/{$invoice->id}")
        ->assertInertia(fn ($page) => $page->where('can.receive', false)->where('can.edit', false));
});
