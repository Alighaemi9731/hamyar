<?php

declare(strict_types=1);

use App\Modules\CRM\Models\Account;
use App\Modules\CRM\Models\LedgerEntry;
use App\Modules\CRM\Models\Party;
use App\Modules\CRM\Services\LedgerService;
use App\Modules\Identity\Models\User;
use App\Modules\Installments\Models\InstallmentCollection;
use App\Modules\Installments\Models\InstallmentPlan;
use App\Modules\Installments\Models\InstallmentRow;
use App\Modules\Installments\Services\CollectInstallment;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Platform\Services\PlanCatalogueSeeder;
use App\Modules\Platform\Services\SubscriptionResolver;
use App\Modules\Platform\Services\TenantProvisioner;
use App\Modules\Treasury\Services\AccountBalances;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;

/**
 * Taking money at the collection desk.
 *
 * The postings are `docs/specs/installment-collection.md` §5. The one that matters most is
 * the one that is NOT here: no revenue is recognised. The invoice already booked the sale
 * and debited the customer for the whole payable; a collection settles that debt and
 * nothing else. Booking income again as the customer pays would report the same sale twice.
 */
beforeEach(function (): void {
    app(PlanCatalogueSeeder::class)->sync();

    $this->tenant = Tenant::factory()->withDomain()->create();

    subscribe($this->tenant, 'pro');
    app(SubscriptionResolver::class)->forget();
    app(TenantProvisioner::class)->seedRoles($this->tenant);

    /** @var array{User, Party, Warehouse, Account} $fixtures */
    $fixtures = inTenantContext($this->tenant, function (): array {
        $owner = User::factory()->create();
        $owner->assignRole('Owner');

        return [
            $owner,
            Party::factory()->create(['name' => 'حسن رضایی']),
            Warehouse::factory()->create(),
            Account::factory()->create(['type' => Account::TYPE_CASH, 'name' => 'صندوق', 'is_default' => true]),
        ];
    });

    [$this->owner, $this->customer, $this->warehouse, $this->till] = $fixtures;

    $this->inTenant = fn (Closure $callback): mixed => inTenantContext($this->tenant, $callback);
});

afterEach(fn () => app(TenantContext::class)->forget());

/**
 * A plan whose sale has already been invoiced — 60,000,000 financed, 20% flat, 6 rows.
 *
 * The invoice debit is posted first, because that is what a plan always sits behind: the
 * customer owes 72,000,000 from the day of the sale, and the schedule only says when.
 */
function invoicedPlan(): InstallmentPlan
{
    /** @var Party $customer */
    $customer = test()->customer;
    /** @var Warehouse $warehouse */
    $warehouse = test()->warehouse;

    app(LedgerService::class)->post([
        ['party_id' => $customer->id, 'debit' => 72_000_000, 'description' => 'فاکتور اقساطی'],
        ['account_id' => (int) Account::factory()->create(['type' => Account::TYPE_SALES])->id, 'credit' => 72_000_000],
    ]);

    /** @var InstallmentPlan $plan */
    $plan = InstallmentPlan::query()->create([
        'branch_id' => $warehouse->branch_id,
        'party_id' => $customer->id,
        'number' => 'INS-000001',
        'down_payment' => 0,
        'principal' => 60_000_000,
        'profit_percent' => 20,
        'profit_amount' => 12_000_000,
        'total_payable' => 72_000_000,
        'installment_count' => 6,
        'interval_months' => 1,
        'first_due_at' => '2026-08-01',
        'status' => 'active',
    ]);

    for ($sequence = 1; $sequence <= 6; $sequence++) {
        InstallmentRow::query()->create([
            'installment_plan_id' => $plan->id,
            'sequence' => $sequence,
            'due_at' => CarbonImmutable::parse('2026-08-01')->addMonths($sequence - 1),
            'amount' => 12_000_000,
            'status' => InstallmentRow::STATUS_PENDING,
        ]);
    }

    return $plan->fresh() ?? $plan;
}

/* ------------------------------------------------ the postings -- */

it('settles the customer debt and recognises no revenue', function (): void {
    ($this->inTenant)(function (): void {
        $plan = invoicedPlan();

        /** @var InstallmentRow $row */
        $row = $plan->rows()->where('sequence', 1)->firstOrFail();

        $ledger = app(LedgerService::class);

        expect($ledger->partyBalance($this->customer))->toBe(72_000_000);

        app(CollectInstallment::class)->collect($row, $this->till, 12_000_000, CarbonImmutable::parse('2026-08-01'));

        expect($ledger->partyBalance($this->customer))->toBe(60_000_000)
            ->and(app(AccountBalances::class)->balanceOf($this->till))->toBe(12_000_000);

        // The sale was booked once, when it happened. A second credit to a sales account
        // here would report the same phone twice.
        $sales = Account::query()->where('type', Account::TYPE_SALES)->firstOrFail();

        expect(app(AccountBalances::class)->balanceOf($sales))->toBe(-72_000_000);
    });
});

it('marks the row paid and records how the payment was split', function (): void {
    ($this->inTenant)(function (): void {
        $plan = invoicedPlan();

        /** @var InstallmentRow $row */
        $row = $plan->rows()->where('sequence', 1)->firstOrFail();

        $collection = app(CollectInstallment::class)->collect($row, $this->till, 12_000_000, CarbonImmutable::parse('2026-08-01'));

        expect($collection->profit_part)->toBe(2_000_000)
            ->and($collection->principal_part)->toBe(10_000_000)
            ->and($collection->fee_part)->toBe(0)
            // The parts account for the whole payment — a receipt whose lines do not sum
            // to its total cannot be explained to the person holding it.
            ->and($collection->settledPart() + $collection->unapplied)->toBe($collection->amount)
            ->and(($row->fresh() ?? $row)->status)->toBe(InstallmentRow::STATUS_PAID);
    });
});

/* --------------------------------- THE PART-PAYMENT HAZARD -- */

it('does not let two half payments settle the same halves twice', function (): void {
    ($this->inTenant)(function (): void {
        $plan = invoicedPlan();

        /** @var InstallmentRow $row */
        $row = $plan->rows()->where('sequence', 1)->firstOrFail();

        $collect = app(CollectInstallment::class);
        $at = CarbonImmutable::parse('2026-08-01');

        // Two payments of 6,000,000 against a 12,000,000 row.
        $first = $collect->collect($row, $this->till, 6_000_000, $at);
        $second = $collect->collect($row->fresh() ?? $row, $this->till, 6_000_000, $at);

        // The first clears the 2,000,000 of profit and 4,000,000 of principal. The second
        // must be offered only the principal that is left — not the same profit again,
        // which would let 12,000,000 be settled by 12,000,000 that only covered 10,000,000
        // of actual obligation.
        expect($first->profit_part)->toBe(2_000_000)
            ->and($first->principal_part)->toBe(4_000_000)
            ->and($second->profit_part)->toBe(0)
            ->and($second->principal_part)->toBe(6_000_000);

        $fresh = $row->fresh() ?? $row;

        expect($collect->outstandingOn($fresh))->toBe(0)
            ->and($fresh->status)->toBe(InstallmentRow::STATUS_PAID);
    });
});

it('leaves a part-paid row open with its original amount intact', function (): void {
    ($this->inTenant)(function (): void {
        $plan = invoicedPlan();

        /** @var InstallmentRow $row */
        $row = $plan->rows()->where('sequence', 1)->firstOrFail();

        app(CollectInstallment::class)->collect($row, $this->till, 5_000_000, CarbonImmutable::parse('2026-08-01'));

        $fresh = $row->fresh() ?? $row;

        // The schedule the customer signed is not rewritten because they paid half of it.
        expect($fresh->amount)->toBe(12_000_000)
            ->and($fresh->status)->not->toBe(InstallmentRow::STATUS_PAID)
            ->and(app(CollectInstallment::class)->outstandingOn($fresh))->toBe(7_000_000);
    });
});

it('derives what is outstanding rather than storing it', function (): void {
    ($this->inTenant)(function (): void {
        $plan = invoicedPlan();

        /** @var InstallmentRow $row */
        $row = $plan->rows()->where('sequence', 1)->firstOrFail();

        $collect = app(CollectInstallment::class);
        $at = CarbonImmutable::parse('2026-08-01');

        // Three payments over five weeks — an ordinary way to settle an instalment here.
        $collect->collect($row, $this->till, 3_000_000, $at);
        $collect->collect($row->fresh() ?? $row, $this->till, 4_000_000, $at->addWeeks(2));
        $collect->collect($row->fresh() ?? $row, $this->till, 5_000_000, $at->addWeeks(5));

        expect(InstallmentCollection::query()->where('installment_row_id', $row->id)->count())->toBe(3)
            ->and($collect->outstandingOn($row->fresh() ?? $row))->toBe(0);
    });
});

/* ------------------------------------------------- late fees -- */

it('recognises a late fee as income, separately from the settlement', function (): void {
    $this->tenant->forceFill(['settings' => [
        'installments' => ['late_fee_percent_per_month' => 2, 'late_fee_grace_days' => 5],
    ]])->save();
    app(TenantContext::class)->forget();

    ($this->inTenant)(function (): void {
        $plan = invoicedPlan();

        /** @var InstallmentRow $row */
        $row = $plan->rows()->where('sequence', 1)->firstOrFail();

        // Due 1 August, paid 23 August: 17 chargeable days at 2%/month on 12,000,000.
        $collection = app(CollectInstallment::class)->collect(
            $row, $this->till, 12_200_000, CarbonImmutable::parse('2026-08-23'),
        );

        expect($collection->fee_part)->toBe(136_000);

        // Its own income account: "how much did we make on late payments" is a question
        // about customer behaviour, not about trading, and should not need a filter.
        $feeAccount = Account::query()->where('type', Account::TYPE_INCOME)->firstOrFail();

        expect(app(AccountBalances::class)->balanceOf($feeAccount))->toBe(-136_000);
    });
});

/* ------------------------------------------------- refusals -- */

it('refuses to collect twice on a settled row', function (): void {
    ($this->inTenant)(function (): void {
        $plan = invoicedPlan();

        /** @var InstallmentRow $row */
        $row = $plan->rows()->where('sequence', 1)->firstOrFail();

        $collect = app(CollectInstallment::class);
        $collect->collect($row, $this->till, 12_000_000, CarbonImmutable::parse('2026-08-01'));

        expect(fn () => $collect->collect($row->fresh() ?? $row, $this->till, 12_000_000, CarbonImmutable::parse('2026-08-02')))
            ->toThrow(RuntimeException::class);
    });
});

it('refuses an amount that could never be printed', function (): void {
    ($this->inTenant)(function (): void {
        $plan = invoicedPlan();

        /** @var InstallmentRow $row */
        $row = $plan->rows()->where('sequence', 1)->firstOrFail();

        // ADR 0009 — not a whole toman, so the receipt could not be rendered.
        expect(fn () => app(CollectInstallment::class)->collect($row, $this->till, 1_000_005))
            ->toThrow(RuntimeException::class);
    });
});

it('refuses to collect into a heading rather than a place', function (): void {
    ($this->inTenant)(function (): void {
        $plan = invoicedPlan();

        /** @var InstallmentRow $row */
        $row = $plan->rows()->where('sequence', 1)->firstOrFail();

        $sales = Account::query()->where('type', Account::TYPE_SALES)->firstOrFail();

        expect(fn () => app(CollectInstallment::class)->collect($row, $sales, 1_000_000))
            ->toThrow(RuntimeException::class);
    });
});

/* ------------------------------------------------ overpayment -- */

it('leaves an overpayment on the party as credit rather than absorbing it', function (): void {
    ($this->inTenant)(function (): void {
        $plan = invoicedPlan();

        /** @var InstallmentRow $row */
        $row = $plan->rows()->where('sequence', 1)->firstOrFail();

        $collection = app(CollectInstallment::class)->collect(
            $row, $this->till, 15_000_000, CarbonImmutable::parse('2026-08-01'),
        );

        expect($collection->unapplied)->toBe(3_000_000)
            // 72,000,000 owed, 15,000,000 handed over — all of it reduces the debt, the
            // extra simply runs ahead into the next instalment.
            ->and(app(LedgerService::class)->partyBalance($this->customer))->toBe(57_000_000)
            ->and(app(AccountBalances::class)->balanceOf($this->till))->toBe(15_000_000);
    });
});

/* --------------------------------------------------- tenancy -- */

it('balances every batch it writes', function (): void {
    ($this->inTenant)(function (): void {
        $plan = invoicedPlan();

        /** @var InstallmentRow $row */
        $row = $plan->rows()->where('sequence', 1)->firstOrFail();

        app(CollectInstallment::class)->collect($row, $this->till, 12_000_000, CarbonImmutable::parse('2026-08-01'));

        $unbalanced = LedgerEntry::query()
            ->groupBy('batch_id')
            ->havingRaw('coalesce(sum(debit), 0) <> coalesce(sum(credit), 0)')
            ->pluck('batch_id');

        expect($unbalanced)->toBeEmpty();
    });
});
