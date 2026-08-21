<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Cheques\Enums\ChequeDirection;
use App\Modules\Cheques\Models\Cheque;
use App\Modules\Cheques\Services\ChequeExposure;
use App\Modules\Cheques\Services\ChequeTransitions;
use App\Modules\CRM\Models\Account;
use App\Modules\CRM\Models\Party;
use App\Modules\CRM\Services\LedgerService;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Enums\MovementType;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockLedger;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Platform\Services\PlanCatalogueSeeder;
use App\Modules\Platform\Services\SubscriptionResolver;
use App\Modules\Platform\Services\TenantProvisioner;
use App\Modules\Sales\Enums\InvoiceStatus;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Services\DraftInvoiceWriter;
use App\Modules\Sales\Services\FinaliseInvoice;
use App\Modules\Sales\Services\VoidInvoice;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;

/**
 * The two things the cheque spec makes binding on code written before it.
 *
 * `docs/specs/cheques.md` §1 records both as requirements rather than observations,
 * because both are consequences of settle-on-receipt: the moment a cheque zeroes a
 * customer's balance, two pieces of existing code start telling the truth about the wrong
 * thing.
 *
 * Both tests were written against the unfixed code first and both failed. Keeping them
 * beside the fix is what stops the next person from "simplifying" either guard back out.
 */
beforeEach(function (): void {
    app(PlanCatalogueSeeder::class)->sync();

    $this->tenant = Tenant::factory()->withDomain()->create();
    $this->url = appUrl();

    subscribe($this->tenant, 'pro');
    app(SubscriptionResolver::class)->forget();
    app(TenantProvisioner::class)->seedRoles($this->tenant);

    /** @var array{User, Party, Account, Warehouse, ProductVariant} $fixtures */
    $fixtures = inTenantContext($this->tenant, function (): array {
        $owner = User::factory()->create();
        $owner->assignRole('Owner');

        $warehouse = Warehouse::factory()->create(['is_sellable' => true, 'is_default' => true]);

        $product = Product::factory()->create(['name' => 'گلس', 'type' => 'standard']);
        $variant = ProductVariant::factory()->for($product)->create();
        app(StockLedger::class)->record($variant->id, $warehouse->id, 20, MovementType::Purchase, unitCost: 200_000);

        Account::factory()->create(['type' => Account::TYPE_CASH, 'is_default' => true]);
        Account::factory()->create(['type' => Account::TYPE_SALES]);
        Account::factory()->create(['type' => Account::TYPE_INVENTORY]);

        return [
            $owner,
            // A customer with a 100,000,000 rial limit.
            Party::factory()->create(['name' => 'حسن رضایی', 'credit_limit' => 100_000_000]),
            Account::factory()->create(['type' => Account::TYPE_BANK, 'name' => 'بانک ملت']),
            $warehouse,
            $variant,
        ];
    });

    [$this->owner, $this->customer, $this->bank, $this->warehouse, $this->variant] = $fixtures;

    $this->inTenant = fn (Closure $callback): mixed => inTenantContext($this->tenant, $callback);
});

afterEach(fn () => app(TenantContext::class)->forget());

/**
 * A received cheque that has settled `$amount` of this customer's debt.
 */
function settlingCheque(int $amount): Cheque
{
    /** @var Party $customer */
    $customer = test()->customer;

    app(LedgerService::class)->post([
        ['party_id' => $customer->id, 'debit' => $amount, 'description' => 'فروش'],
        ['account_id' => (int) Account::query()->where('type', Account::TYPE_SALES)->firstOrFail()->id, 'credit' => $amount],
    ]);

    $cheque = Cheque::query()->create([
        'direction' => ChequeDirection::Received,
        'party_id' => $customer->id,
        'amount' => $amount,
        'bank_name' => 'ملت',
        'serial' => (string) random_int(100000, 999999),
        'due_date' => '2026-11-22',
    ]);

    return app(ChequeTransitions::class)->receive($cheque, CarbonImmutable::parse('2026-08-22'));
}

/* ======================================================================
 | BINDING CONSTRAINT 1 — the credit check must see uncleared cheques
 |
 | Settle-on-receipt zeroes the party balance. A check that reads the balance alone hands
 | unlimited further credit to a customer who has paid entirely in post-dated paper —
 | precisely the customer a limit exists to stop.
 |
 | This test failed on the unfixed code: `after` came back as 90,000,000 against a
 | 100,000,000 limit, `exceeds` false, on a customer holding 300,000,000 of uncleared
 | cheques.
 ====================================================================== */

it('counts uncleared cheques toward a customer credit exposure', function (): void {
    ($this->inTenant)(function (): void {
        // Three months of trading, all settled with post-dated paper.
        settlingCheque(150_000_000);
        settlingCheque(150_000_000);

        // The books are right: they owe nothing.
        expect(app(LedgerService::class)->partyBalance($this->customer))->toBe(0);

        // And the shop is carrying 300,000,000 of their paper.
        expect(app(ChequeExposure::class)->forParty($this->customer))->toBe(300_000_000);

        $check = app(LedgerService::class)->creditCheck($this->customer, 90_000_000);

        // Balance alone would say 90,000,000 against a 100,000,000 limit — comfortable.
        // Exposure says 390,000,000, which is the number a shopkeeper needed.
        expect($check['exposure'])->toBe(300_000_000)
            ->and($check['after'])->toBe(390_000_000)
            ->and($check['exceeds'])->toBeTrue();
    });
});

it('stops counting a cheque once the bank has actually paid', function (): void {
    ($this->inTenant)(function (): void {
        $cheque = settlingCheque(150_000_000);

        $transitions = app(ChequeTransitions::class);
        $transitions->deposit($cheque, $this->bank, CarbonImmutable::parse('2026-11-22'));
        $transitions->clear($cheque, at: CarbonImmutable::parse('2026-11-25'));

        // Cleared money is not exposure — it is in the bank.
        expect(app(ChequeExposure::class)->forParty($this->customer))->toBe(0)
            ->and(app(LedgerService::class)->creditCheck($this->customer, 50_000_000)['exceeds'])->toBeFalse();
    });
});

it('keeps counting a cheque the shop has endorsed onward', function (): void {
    ($this->inTenant)(function (): void {
        $cheque = settlingCheque(150_000_000);
        $supplier = Party::factory()->create(['name' => 'پخش ایرانیان']);

        app(ChequeTransitions::class)->endorse($cheque, $supplier->id, CarbonImmutable::parse('2026-08-25'));

        // Endorsement does not discharge recourse. If it bounces at the supplier, the shop
        // is liable and the drawer is still the drawer — so a shop that treats endorsed
        // paper as settled has quietly given this customer more credit than it knows.
        expect(app(ChequeExposure::class)->forParty($this->customer))->toBe(150_000_000);
    });
});

it('counts only the shortfall after a bank paid part of a cheque', function (): void {
    ($this->inTenant)(function (): void {
        $cheque = settlingCheque(150_000_000);

        $transitions = app(ChequeTransitions::class);
        $transitions->deposit($cheque, $this->bank, CarbonImmutable::parse('2026-11-22'));
        $transitions->bounce($cheque, 'کسر موجودی', recovered: 50_000_000, at: CarbonImmutable::parse('2026-11-25'));

        // The 50,000,000 the bank paid is in the shop's account and is not at risk.
        expect(app(ChequeExposure::class)->forParty($this->customer))->toBe(100_000_000);
    });
});

/* ======================================================================
 | BINDING CONSTRAINT 2 — voiding an invoice with a live cheque
 |
 | `VoidInvoice::reverseLedger()` reverses only batches whose `reference_type` is
 | `SalesInvoice`. A cheque posts its own batch against `Cheque`, so voiding used to credit
 | the customer the full amount and leave the cheque asset standing — the shop owing a
 | customer whose cheque was still in its drawer.
 |
 | This test failed on the unfixed code: the void succeeded and the customer's balance
 | went to -450,000,000.
 ====================================================================== */

it('refuses to void an invoice while its cheque is still live', function (): void {
    ($this->inTenant)(function (): void {
        $invoice = SalesInvoice::query()->create([
            'branch_id' => $this->warehouse->branch_id,
            'party_id' => $this->customer->id,
            'type' => SalesInvoice::TYPE_INVOICE,
            'status' => InvoiceStatus::Draft,
        ]);

        app(DraftInvoiceWriter::class)->write($invoice, [
            ['variant_id' => $this->variant->id, 'quantity' => 1, 'unit_price' => 20_000_000],
        ], []);

        app(FinaliseInvoice::class)->finalise($invoice, $this->owner->id);

        // The customer settles with a post-dated cheque against this invoice.
        $cheque = Cheque::query()->create([
            'direction' => ChequeDirection::Received,
            'party_id' => $this->customer->id,
            'amount' => 20_000_000,
            'bank_name' => 'ملت',
            'serial' => '778899',
            'due_date' => '2026-11-22',
            'reference_type' => SalesInvoice::class,
            'reference_id' => $invoice->id,
        ]);

        app(ChequeTransitions::class)->receive($cheque, CarbonImmutable::parse('2026-08-22'));

        // Voiding now would credit them for the sale and leave the cheque asset standing:
        // the shop would owe a customer whose paper is still in its drawer.
        expect(fn () => app(VoidInvoice::class)->void($invoice->fresh() ?? $invoice, 'اشتباه در ثبت', $this->owner->id))
            ->toThrow(RuntimeException::class);

        expect(($invoice->fresh() ?? $invoice)->status)->toBe(InvoiceStatus::Final);
    });
});

it('allows the void once the cheque has been handed back', function (): void {
    ($this->inTenant)(function (): void {
        $invoice = SalesInvoice::query()->create([
            'branch_id' => $this->warehouse->branch_id,
            'party_id' => $this->customer->id,
            'type' => SalesInvoice::TYPE_INVOICE,
            'status' => InvoiceStatus::Draft,
        ]);

        app(DraftInvoiceWriter::class)->write($invoice, [
            ['variant_id' => $this->variant->id, 'quantity' => 1, 'unit_price' => 20_000_000],
        ], []);

        app(FinaliseInvoice::class)->finalise($invoice, $this->owner->id);

        $cheque = Cheque::query()->create([
            'direction' => ChequeDirection::Received,
            'party_id' => $this->customer->id,
            'amount' => 20_000_000,
            'bank_name' => 'ملت',
            'serial' => '778900',
            'due_date' => '2026-11-22',
            'reference_type' => SalesInvoice::class,
            'reference_id' => $invoice->id,
        ]);

        app(ChequeTransitions::class)->receive($cheque, CarbonImmutable::parse('2026-08-22'));

        // Return the paper first — the operator is told to do exactly this.
        app(ChequeTransitions::class)->returnToDrawer($cheque, CarbonImmutable::parse('2026-08-23'));

        app(VoidInvoice::class)->void($invoice->fresh() ?? $invoice, 'اشتباه در ثبت', $this->owner->id);

        expect(($invoice->fresh() ?? $invoice)->status)->toBe(InvoiceStatus::Void)
            // Sale reversed, cheque returned: the customer owes nothing and is owed
            // nothing. Both halves unwound, in the right order.
            ->and(app(LedgerService::class)->partyBalance($this->customer))->toBe(0);
    });
});
