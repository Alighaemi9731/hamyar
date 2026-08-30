<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\CRM\Models\Account;
use App\Modules\CRM\Models\Party;
use App\Modules\Identity\Models\User;
use App\Modules\Installments\Models\InstallmentCollection;
use App\Modules\Installments\Models\InstallmentPlan;
use App\Modules\Installments\Models\InstallmentRow;
use App\Modules\Inventory\Enums\UnitStatus;
use App\Modules\Inventory\Models\ProductUnit;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Platform\Services\SubscriptionResolver;
use App\Modules\Platform\Services\TenantProvisioner;
use App\Modules\Sales\Models\SalesInvoice;
use App\Support\Tenancy\TenantContext;

/**
 * The meter on «قرارداد اقساطی», at the place a shopkeeper actually meets it.
 *
 * ## Why this file exists separately from the guard's own suite
 *
 * `Platform/tests/Feature/Quota/*` proves the guard is correct — it counts, it refuses at
 * the ceiling, it is atomic under concurrency — and every one of those tests meters a
 * synthetic `quota.widgets` metric on purpose, so the guard's suite breaks when the guard
 * breaks rather than when Installments renames something. The cost is that none of them
 * touch a route, and a guard that is perfect and never called is indistinguishable, from
 * the shop floor, from no guard at all.
 *
 * ## The claim this module owns, above every other module's
 *
 * **Collecting is free.** `installments.plans` meters the signing of a contract and
 * nothing that happens afterwards, which is the difference between selling a shop a
 * quantity and holding its own money hostage. A shop that has written its twentieth plan
 * of the month on the free rung is at its cap for *writing new contracts* — and must
 * still, that same afternoon, take the instalment a customer walks in to pay on a
 * contract signed in Ordibehesht. That money is already owed to them; a limit that
 * blocked its collection would not be a limit, it would be a product holding a shop's
 * receivables to ransom, and it is the single fastest way to turn a pricing tier into a
 * refund and a bad review. `CollectInstallment` contains no `consume()` call at all, and
 * the test at the bottom of this file is what keeps that true when somebody later decides
 * collections look countable.
 *
 * ## What the credit is spent on, and what it is not spent on
 *
 * One contract, one credit — not one per row. A twelve-month schedule and a three-month
 * one are one decision each, and metering rows would make the longer contract cost four
 * times as much for the same act of selling one phone on terms. `CreateInstallmentPlan`
 * says so where it calls `consume()`; the second test says it in a way that breaks if the
 * call ever moves inside the `foreach`.
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

        $variant = ProductVariant::factory()
            ->for(Product::factory()->serialized()->create(['name' => 'آیفون ۱۵ پرو']))
            ->create();

        return [$owner, $warehouse, $cash, $variant, Party::factory()->create()];
    });

    [$this->owner, $this->warehouse, $this->cash, $this->variant, $this->party] = $fixtures;
});

afterEach(fn () => app(TenantContext::class)->forget());

/**
 * Sell a handset for 60,000,000 with nothing down, and hand back the invoice.
 *
 * A fresh serialised unit each time, because a plan hangs off an invoice and there is one
 * plan per invoice — so a test that writes two contracts needs two sales behind them, the
 * same way a shop does.
 */
function financedSale(): SalesInvoice
{
    /** @var Tenant $tenant */
    $tenant = test()->tenant;
    /** @var User $owner */
    $owner = test()->owner;
    /** @var Warehouse $warehouse */
    $warehouse = test()->warehouse;
    /** @var ProductVariant $variant */
    $variant = test()->variant;
    /** @var Party $party */
    $party = test()->party;
    /** @var string $url */
    $url = test()->url;

    /** @var ProductUnit $unit */
    $unit = inTenantContext($tenant, fn (): ProductUnit => ProductUnit::factory()
        ->for($variant, 'variant')
        ->create([
            'warehouse_id' => $warehouse->id,
            'status' => UnitStatus::InStock,
            'cost' => 40_000_000,
        ]));

    test()->actingAs($owner)->post($url.'/sales/pos', [
        'branch_id' => $warehouse->branch_id,
        'party_id' => $party->id,
        'salesperson_id' => null,
        'unit' => 'rial',
        'action' => 'finalise',
        'vat_applied' => false,
        'discount_amount' => 0,
        'shipping_amount' => 0,
        'notes' => null,
        'lines' => [
            ['unit_id' => $unit->id, 'variant_id' => null, 'quantity' => 1, 'unit_price' => 60_000_000, 'discount_amount' => 0],
        ],
        'payments' => [],
    ])->assertSessionHasNoErrors();

    /** @var SalesInvoice $invoice */
    $invoice = inTenantContext($tenant, fn (): SalesInvoice => SalesInvoice::query()->latest('id')->firstOrFail());

    return $invoice;
}

/**
 * Write the contract, through the real wizard endpoint.
 *
 * Six instalments at 20% — 60,000,000 financed becomes 72,000,000 payable in rows of
 * 12,000,000, which divides exactly, so no test below has to reason about rounding to say
 * what a row is worth.
 *
 * Deliberately asserts nothing about the response: every test here wants to say something
 * different about it, and a helper that asserted success could not be used by the tests
 * about refusal.
 *
 * @return Illuminate\Testing\TestResponse<Illuminate\Http\Response>
 */
function writePlan(SalesInvoice $invoice, int $count = 6, int $profitPercent = 20): Illuminate\Testing\TestResponse
{
    /** @var User $owner */
    $owner = test()->owner;
    /** @var string $url */
    $url = test()->url;

    return test()->actingAs($owner)->post($url.'/installments/invoices/'.$invoice->id.'/plan', [
        'count' => $count,
        'profit_percent' => $profitPercent,
        'interval_months' => 1,
        'first_due' => '1405/06/15',
    ]);
}

function planCount(): int
{
    /** @var Tenant $tenant */
    $tenant = test()->tenant;

    /** @var int $count */
    $count = inTenantContext($tenant, fn (): int => InstallmentPlan::query()->count());

    return $count;
}

it('spends one plan credit for one signed contract', function (): void {
    writePlan(financedSale())->assertSessionHasNoErrors()->assertRedirect();

    expect(quotaUsed($this->tenant, 'installments.plans'))->toBe(1)
        // The pairing is the interesting claim, not the number: a spent credit always has a
        // contract behind it, because `consume()` runs inside the transaction that writes
        // it.
        ->and(planCount())->toBe(1);
});

it('charges one credit for the contract however many instalments it has', function (): void {
    // The longest schedule this product will write, against the shortest possible credit.
    writePlan(financedSale(), count: 12)->assertSessionHasNoErrors();

    expect(quotaUsed($this->tenant, 'installments.plans'))->toBe(1);

    inTenantContext($this->tenant, function (): void {
        // Twelve rows, one credit. If `consume()` ever slips inside the `foreach` that
        // writes them, this is the number that changes and the one above that goes to 12 —
        // and a shop selling on twelve months would pay four times what a shop selling on
        // three pays, for the same single act of agreeing terms.
        expect(InstallmentRow::query()->count())->toBe(12);
    });
});

it('refuses the contract that would cross the ceiling, and writes no schedule', function (): void {
    capQuota($this->tenant, 'installments.plans', 1);

    $first = financedSale();
    $second = financedSale();

    writePlan($first)->assertSessionHasNoErrors();
    expect(planCount())->toBe(1);

    // The second one is the whole test. A shop at its cap must be *told*, not handed a
    // wizard whose submit button does nothing — see CLAUDE.md on the operator pressing
    // submit twice with a customer at the counter, and note that the customer here is one
    // who has just been sold a phone and is waiting to sign.
    writePlan($second)->assertSessionHasErrors('quota');

    expect(planCount())->toBe(1)
        ->and(quotaUsed($this->tenant, 'installments.plans'))->toBe(1);

    inTenantContext($this->tenant, function (): void {
        // Six rows, not twelve. A refused contract that left its schedule behind would
        // produce instalments belonging to no plan, and the collection desk chases rows.
        expect(InstallmentRow::query()->count())->toBe(6);
    });
});

it('refuses through the controller arm that converts everything else into a field message', function (): void {
    capQuota($this->tenant, 'installments.plans', 0);

    writePlan(financedSale())
        ->assertSessionHasErrors('quota')
        // `InstallmentPlanController::store()` turns
        // `RuntimeException|InvalidArgumentException` into
        // `ValidationException::withMessages(['count' => …])`. A quota refusal must NOT
        // arrive on `count`: «تعداد اقساط را وارد کنید» and «سهمیهٔ شما تمام شد» are
        // different problems with different answers, and the second has an upgrade button
        // behind it that the first must never show. `QuotaExceeded extends Exception` is
        // what keeps it out of that arm.
        ->assertSessionDoesntHaveErrors('count');
});

it('hands the operator something to render, not just an error string', function (): void {
    capQuota($this->tenant, 'installments.plans', 0);

    writePlan(financedSale());

    /** @var array<string, mixed> $block */
    $block = session('quota_block') ?? [];

    // These are the keys `quota-block.tsx` reads. A refusal that reached the browser without
    // them would render an empty card, which is worse than a 500 because nobody would
    // report it.
    expect($block)->toHaveKeys(['metric', 'label', 'message', 'used', 'limit', 'resets_at', 'next_plan'])
        ->and($block['metric'])->toBe('installments.plans')
        // Persian, not the exception's English. `QuotaExceeded` stopped extending
        // `RuntimeException` because a dozen controllers used to convert it into a field
        // message carrying exactly that English string.
        ->and($block['message'])->toBeString()->not->toContain('Quota exceeded');
});

it('names the cheapest plan that would actually fit', function (): void {
    capQuota($this->tenant, 'installments.plans', 0);

    writePlan(financedSale());

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

it('spends nothing when the contract fails inside the transaction', function (): void {
    $invoice = financedSale();

    inTenantContext($this->tenant, function (): void {
        // The shop's sales account, gone after the sale was made. Contrived-looking and
        // not contrived: `postProfit()` looks it up by type and throws «حساب فروش تعریف
        // نشده است» when it is missing, and a shop that has been reorganising its chart of
        // accounts is exactly how that happens on a Tuesday afternoon.
        Account::query()->where('type', Account::TYPE_SALES)->firstOrFail()->delete();
    });

    // 20% profit, so `postProfit()` actually runs — it returns early on a zero-profit plan,
    // and a test that quietly took that branch would prove nothing.
    //
    // The failure lands *after* `consume()`, after the plan row and after all six schedule
    // rows, inside the same transaction all four share. That is the race a shop loses to
    // its own bookkeeping, and it must not be charged a credit for a contract it does not
    // have.
    writePlan($invoice)->assertSessionHasErrors('count');

    expect(planCount())->toBe(0)
        // No row at all, rather than a row reading zero: the two are different claims and
        // only the first says the transaction really rolled back — `usage_counters` is
        // written by the same statement stream as the plan, so a surviving counter row here
        // would mean the guard had escaped the transaction it is supposed to live in.
        ->and(quotaRowExists($this->tenant, 'installments.plans'))->toBeFalse();

    inTenantContext($this->tenant, function (): void {
        expect(InstallmentRow::query()->count())->toBe(0);
    });
});

/**
 * The one that matters most in this file.
 *
 * A shop at its contract ceiling walks a customer to the counter who wants to pay
 * instalment three of six on a contract signed months ago. Nothing about that act is new
 * business, nothing about it costs us anything, and the money is already the shop's. If the
 * meter refused it, the plan model would not be selling capacity — it would be withholding
 * a shop's own receivables until they upgrade, which is the kind of thing a shopkeeper
 * tells other shopkeepers about.
 *
 * So the shop is put exactly at its cap first, deliberately, and then asked to collect.
 */
it('lets a shop at its contract cap still take the money it is already owed', function (): void {
    capQuota($this->tenant, 'installments.plans', 1);

    writePlan(financedSale())->assertSessionHasNoErrors();

    // At the ceiling now: one written, one allowed. Any further contract would be refused,
    // and this is the moment the customer walks in.
    expect(quotaUsed($this->tenant, 'installments.plans'))->toBe(1);

    /** @var InstallmentRow $row */
    $row = inTenantContext($this->tenant, fn (): InstallmentRow => InstallmentRow::query()
        ->where('sequence', 1)
        ->firstOrFail());

    $this->actingAs($this->owner)
        ->post($this->url.'/installments/rows/'.$row->id.'/collect', [
            'account_id' => $this->cash->id,
            'amount' => $row->amount,
            'method' => 'cash',
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    inTenantContext($this->tenant, function () use ($row): void {
        // The money is in the till and the instalment is settled — not "the request did not
        // 500", which a refusal rendered as a redirect would also satisfy.
        $collection = InstallmentCollection::query()->firstOrFail();

        expect($collection->amount)->toBe($row->amount)
            ->and(InstallmentRow::query()->findOrFail($row->id)->status)->toBe(InstallmentRow::STATUS_PAID);
    });

    // And it cost nothing. Not "one more than before" — the same number as before the
    // customer walked in, because a collection is not a contract.
    expect(quotaUsed($this->tenant, 'installments.plans'))->toBe(1);
});
