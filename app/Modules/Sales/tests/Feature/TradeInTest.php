<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\CRM\Models\Account;
use App\Modules\CRM\Models\LedgerEntry;
use App\Modules\CRM\Models\Party;
use App\Modules\CRM\Services\LedgerService;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Enums\UnitCondition;
use App\Modules\Inventory\Enums\UnitStatus;
use App\Modules\Inventory\Models\ProductUnit;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Platform\Services\PlanCatalogueSeeder;
use App\Modules\Platform\Services\SubscriptionResolver;
use App\Modules\Platform\Services\TenantProvisioner;
use App\Modules\Sales\Enums\PaymentMethod;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\TradeIn;
use App\Support\Tenancy\TenantContext;

/**
 * معاوضه — the shop buying a phone across the counter it is selling one over.
 *
 * The tests that matter are the ones proving it is a **tender and not a discount**: the
 * sale keeps its full price and VAT, the traded device becomes real stock with a cost and
 * a passport, and the ledger balances with inventory on the debit side.
 */
beforeEach(function (): void {
    app(PlanCatalogueSeeder::class)->sync();

    $this->tenant = Tenant::factory()->withDomain()->create();
    $this->url = tenantUrl($this->tenant);

    subscribe($this->tenant, 'pro');
    app(SubscriptionResolver::class)->forget();
    app(TenantProvisioner::class)->seedRoles($this->tenant);

    /** @var array{User, Warehouse, Account, Party, ProductUnit, ProductVariant} $fixtures */
    $fixtures = inTenantContext($this->tenant, function (): array {
        $owner = User::factory()->create();
        $owner->assignRole('Owner');

        $warehouse = Warehouse::factory()->create(['is_sellable' => true, 'is_default' => true]);

        $cash = Account::factory()->create(['type' => Account::TYPE_CASH, 'is_default' => true]);
        Account::factory()->create(['type' => Account::TYPE_SALES]);
        Account::factory()->create(['type' => Account::TYPE_INVENTORY]);

        // What the shop is selling.
        $newPhone = Product::factory()->serialized()->create(['name' => 'آیفون ۱۵ پرو']);
        $newVariant = ProductVariant::factory()->for($newPhone)->create();

        $unit = ProductUnit::factory()->for($newVariant, 'variant')->create([
            'warehouse_id' => $warehouse->id,
            'status' => UnitStatus::InStock,
            'imei1' => '356938035643809',
            'cost' => 40_000_000,
        ]);

        // The catalogue line the customer's old phone will be filed under.
        $oldPhone = Product::factory()->serialized()->create(['name' => 'آیفون ۱۳']);
        $oldVariant = ProductVariant::factory()->for($oldPhone)->create();

        return [$owner, $warehouse, $cash, Party::factory()->create(), $unit, $oldVariant];
    });

    [$this->owner, $this->warehouse, $this->cash, $this->party, $this->unit, $this->oldVariant] = $fixtures;

    $this->inTenant = fn (Closure $callback): mixed => inTenantContext($this->tenant, $callback);
});

afterEach(fn () => app(TenantContext::class)->forget());

/**
 * A sale of a 60,000,000 phone, part-settled by a trade-in.
 *
 * @param  array<string, mixed>  $tradeInOverrides
 * @param  list<array<string, mixed>>  $payments
 * @return array<string, mixed>
 */
function tradeInPayload(int $branchId, int $unitId, int $partyId, int $oldVariantId, array $tradeInOverrides = [], array $payments = []): array
{
    return [
        'branch_id' => $branchId,
        'party_id' => $partyId,
        'salesperson_id' => null,
        'unit' => 'rial',
        'action' => 'finalise',
        'vat_applied' => false,
        'discount_amount' => 0,
        'shipping_amount' => 0,
        'notes' => null,
        'lines' => [
            ['unit_id' => $unitId, 'variant_id' => null, 'quantity' => 1, 'unit_price' => 60_000_000, 'discount_amount' => 0],
        ],
        'payments' => $payments,
        'trade_in' => [
            'device_name' => 'آیفون ۱۳ سفید ۱۲۸',
            'product_variant_id' => $oldVariantId,
            'imei1' => '351756051523993',
            'grade' => 'B',
            'agreed_price' => 20_000_000,
            'hamta_ack' => true,
            ...$tradeInOverrides,
        ],
    ];
}

/* ------------------------------------------------------------ happy path -- */

it('takes the old phone in as real stock with its own cost and passport', function (): void {
    $this->actingAs($this->owner)
        ->post($this->url.'/sales/pos', tradeInPayload(
            $this->warehouse->branch_id,
            $this->unit->id,
            $this->party->id,
            $this->oldVariant->id,
            payments: [['method' => 'cash', 'amount' => 40_000_000, 'account_id' => $this->cash->id]],
        ))
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    ($this->inTenant)(function (): void {
        $tradeIn = TradeIn::query()->firstOrFail();

        expect($tradeIn->product_unit_id)->not->toBeNull();

        $received = ProductUnit::query()->findOrFail($tradeIn->product_unit_id);

        // A used handset like any other from here: gradeable, repairable, sellable.
        expect($received->status)->toBe(UnitStatus::InStock)
            ->and($received->condition)->toBe(UnitCondition::Used)
            ->and($received->grade)->toBe('B')
            // The agreed price IS the cost. Sell it for more and the margin is real.
            ->and($received->cost)->toBe(20_000_000)
            // Golden rule 4: "bought from whom" has an answer.
            ->and($received->acquired_from_party_id)->toBe($this->party->id)
            ->and($received->imei1)->toBe('351756051523993');

        // And its passport starts at the beginning rather than mid-story.
        expect($received->histories()->count())->toBeGreaterThan(0);
    });
});

it('is a tender, not a discount — the sale keeps its full price and VAT', function (): void {
    $payload = tradeInPayload(
        $this->warehouse->branch_id,
        $this->unit->id,
        $this->party->id,
        $this->oldVariant->id,
        payments: [['method' => 'cash', 'amount' => 44_000_000, 'account_id' => $this->cash->id]],
    );

    $payload['vat_applied'] = true;

    $this->actingAs($this->owner)->post($this->url.'/sales/pos', $payload)->assertSessionHasNoErrors();

    ($this->inTenant)(function (): void {
        $invoice = SalesInvoice::query()->latest('id')->firstOrFail();

        // VAT is 10% of 60,000,000 — computed on what was sold, not on what was sold
        // minus the old phone. A discount would have taxed 40,000,000 and understated
        // both the sale and the tax owed.
        expect($invoice->subtotal)->toBe(60_000_000)
            ->and($invoice->vat_amount)->toBe(6_000_000)
            ->and($invoice->total)->toBe(66_000_000);

        // Settled in full: 44,000,000 cash + 20,000,000 of old phone.
        expect($invoice->paid_total)->toBe(64_000_000)
            ->and($invoice->outstanding())->toBe(2_000_000);
    });
});

it('debits inventory for the traded device so the ledger balances', function (): void {
    $this->actingAs($this->owner)
        ->post($this->url.'/sales/pos', tradeInPayload(
            $this->warehouse->branch_id,
            $this->unit->id,
            $this->party->id,
            $this->oldVariant->id,
            payments: [['method' => 'cash', 'amount' => 40_000_000, 'account_id' => $this->cash->id]],
        ))
        ->assertSessionHasNoErrors();

    ($this->inTenant)(function (): void {
        $inventory = Account::query()->where('type', Account::TYPE_INVENTORY)->firstOrFail();

        // Value arrived, just not as money. Without this line the entry would not
        // balance and the shop's stock would be worth less on paper than on the shelf.
        expect((int) LedgerEntry::query()->where('account_id', $inventory->id)->sum('debit'))
            ->toBe(20_000_000);

        expect((int) LedgerEntry::query()->where('account_id', $this->cash->id)->sum('debit'))
            ->toBe(40_000_000);

        // Nothing left owing, so the customer's account is untouched.
        expect(app(LedgerService::class)->partyBalance($this->party))->toBe(0);

        // And the whole batch balances, which is what LedgerService enforces.
        expect((int) LedgerEntry::query()->sum('debit'))
            ->toBe((int) LedgerEntry::query()->sum('credit'));
    });
});

it('records the trade-in as its own payment row', function (): void {
    $this->actingAs($this->owner)
        ->post($this->url.'/sales/pos', tradeInPayload(
            $this->warehouse->branch_id,
            $this->unit->id,
            $this->party->id,
            $this->oldVariant->id,
            payments: [['method' => 'cash', 'amount' => 40_000_000, 'account_id' => $this->cash->id]],
        ));

    ($this->inTenant)(function (): void {
        $invoice = SalesInvoice::query()->latest('id')->firstOrFail();

        $tender = $invoice->payments()->where('method', PaymentMethod::TradeIn->value)->firstOrFail();

        // Written server-side and equal to the agreed price exactly: a customer told
        // their phone is worth 20,000,000 who gets 19,000,000 off is a customer arguing.
        expect($tender->amount)->toBe(20_000_000)
            ->and($tender->account_id)->toBeNull();
    });
});

/* -------------------------------------------- the phone is worth more --- */

it('credits the customer when their old phone is worth more than the new one', function (): void {
    $payload = tradeInPayload(
        $this->warehouse->branch_id,
        $this->unit->id,
        $this->party->id,
        $this->oldVariant->id,
        ['agreed_price' => 75_000_000],
    );

    $this->actingAs($this->owner)->post($this->url.'/sales/pos', $payload)->assertSessionHasNoErrors();

    ($this->inTenant)(function (): void {
        // The shop owes 15,000,000. Negative is بستانکار; paying it out is a Treasury
        // act (Phase 7), and quietly keeping it would be theft with good bookkeeping.
        expect(app(LedgerService::class)->partyBalance($this->party))->toBe(-15_000_000);

        expect((int) LedgerEntry::query()->sum('debit'))
            ->toBe((int) LedgerEntry::query()->sum('credit'));
    });
});

it('settles the trade-in first, so a slipped zero in the cash box cannot overpay', function (): void {
    $payload = tradeInPayload(
        $this->warehouse->branch_id,
        $this->unit->id,
        $this->party->id,
        $this->oldVariant->id,
        payments: [
            // A slipped zero on a 60,000,000 invoice.
            ['method' => 'cash', 'amount' => 400_000_000, 'account_id' => $this->cash->id],
        ],
    );

    $this->actingAs($this->owner)->post($this->url.'/sales/pos', $payload)->assertSessionHasNoErrors();

    ($this->inTenant)(function (): void {
        $invoice = SalesInvoice::query()->latest('id')->firstOrFail();

        $cash = $invoice->payments()->where('method', PaymentMethod::Cash->value)->firstOrFail();

        // The old phone is valued and agreed before anybody counts cash, which is the
        // order a real counter works in — so the trade-in takes its 20,000,000 first and
        // the cash can only settle the 40,000,000 that is left.
        expect($cash->amount)->toBe(40_000_000)
            // And no fictitious change: nobody recorded handing over 400,000,000.
            ->and($cash->tendered_amount)->toBeNull()
            ->and($cash->change())->toBe(0);

        expect($invoice->paid_total)->toBe(60_000_000)
            ->and($invoice->outstanding())->toBe(0);

        // The drawer is 40,000,000 heavier, not 400,000,000.
        expect((int) LedgerEntry::query()->where('account_id', $this->cash->id)->sum('debit'))
            ->toBe(40_000_000);
    });
});

/* -------------------------------------------------------------- refusals -- */

it('refuses a trade-in with no customer to buy the phone from', function (): void {
    $payload = tradeInPayload(
        $this->warehouse->branch_id,
        $this->unit->id,
        $this->party->id,
        $this->oldVariant->id,
        payments: [['method' => 'cash', 'amount' => 40_000_000, 'account_id' => $this->cash->id]],
    );

    $payload['party_id'] = null;

    $this->actingAs($this->owner)
        ->post($this->url.'/sales/pos', $payload)
        ->assertSessionHasErrors('trade_in.device_name');

    ($this->inTenant)(fn () => expect(TradeIn::query()->count())->toBe(0));
});

it('refuses a trade-in without the HAMTA acknowledgement', function (): void {
    $this->actingAs($this->owner)
        ->post($this->url.'/sales/pos', tradeInPayload(
            $this->warehouse->branch_id,
            $this->unit->id,
            $this->party->id,
            $this->oldVariant->id,
            ['hamta_ack' => false],
            [['method' => 'cash', 'amount' => 40_000_000, 'account_id' => $this->cash->id]],
        ))
        // The shop carries the liability when a stolen handset is traded in, so this is
        // a box somebody had to tick on purpose.
        ->assertSessionHasErrors('trade_in.hamta_ack');
});

it('refuses a trade-in whose IMEI fails its check digit', function (): void {
    $this->actingAs($this->owner)
        ->post($this->url.'/sales/pos', tradeInPayload(
            $this->warehouse->branch_id,
            $this->unit->id,
            $this->party->id,
            $this->oldVariant->id,
            // Same number with the check digit knocked off by one.
            ['imei1' => '351756051523991'],
            [['method' => 'cash', 'amount' => 40_000_000, 'account_id' => $this->cash->id]],
        ))
        // Invisible until the phone is sold or warranty-claimed, by which point the
        // paperwork trail is broken.
        ->assertSessionHasErrors('lines');

    ($this->inTenant)(fn () => expect(ProductUnit::query()->count())->toBe(1));
});

it('refuses a trade-in with no catalogue line to file the device under', function (): void {
    $payload = tradeInPayload(
        $this->warehouse->branch_id,
        $this->unit->id,
        $this->party->id,
        $this->oldVariant->id,
        payments: [['method' => 'cash', 'amount' => 40_000_000, 'account_id' => $this->cash->id]],
    );

    /** @var array<string, mixed> $tradeIn */
    $tradeIn = $payload['trade_in'];
    unset($tradeIn['product_variant_id']);
    $payload['trade_in'] = $tradeIn;

    $this->actingAs($this->owner)
        ->post($this->url.'/sales/pos', $payload)
        ->assertSessionHasErrors('trade_in.product_variant_id');
});
