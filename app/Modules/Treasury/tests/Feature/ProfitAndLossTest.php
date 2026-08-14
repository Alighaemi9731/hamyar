<?php

declare(strict_types=1);

use App\Modules\CRM\Models\Account;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Platform\Services\PlanCatalogueSeeder;
use App\Modules\Platform\Services\SubscriptionResolver;
use App\Modules\Platform\Services\TenantProvisioner;
use App\Modules\Treasury\Enums\CashDirection;
use App\Modules\Treasury\Models\TransactionCategory;
use App\Modules\Treasury\Services\AccountBalances;
use App\Modules\Treasury\Services\DailyClose;
use App\Modules\Treasury\Services\ProfitAndLoss;
use App\Modules\Treasury\Services\RecordCashTransaction;
use App\Modules\Treasury\Services\TransferBetweenAccounts;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;

/**
 * The two reports an owner reads, and the one property both must have.
 *
 * A report whose headline does not equal the rows beneath it is worse than no report: the
 * shop stops believing all of them, including the ones that were right. So the assertions
 * here are mostly about internal consistency rather than about specific figures.
 */
beforeEach(function (): void {
    app(PlanCatalogueSeeder::class)->sync();

    $this->tenant = Tenant::factory()->withDomain()->create();

    subscribe($this->tenant, 'pro');
    app(SubscriptionResolver::class)->forget();
    app(TenantProvisioner::class)->seedRoles($this->tenant);

    /** @var array{User, Account, Account, TransactionCategory, TransactionCategory} $fixtures */
    $fixtures = inTenantContext($this->tenant, function (): array {
        $owner = User::factory()->create();
        $owner->assignRole('Owner');

        Warehouse::factory()->create(['is_sellable' => true, 'is_default' => true]);

        $till = Account::factory()->create([
            'type' => Account::TYPE_CASH, 'name' => 'صندوق', 'opening_balance' => 500_000_000,
        ]);
        $bank = Account::factory()->create([
            'type' => Account::TYPE_BANK, 'name' => 'بانک', 'opening_balance' => 0,
        ]);

        $rent = TransactionCategory::query()->create([
            'account_id' => Account::factory()->create(['type' => Account::TYPE_EXPENSE, 'name' => 'اجاره'])->id,
            'name' => 'اجاره مغازه', 'direction' => CashDirection::Expense, 'is_active' => true,
        ]);

        $desk = TransactionCategory::query()->create([
            'account_id' => Account::factory()->create(['type' => Account::TYPE_INCOME, 'name' => 'اجاره میز'])->id,
            'name' => 'اجاره میز', 'direction' => CashDirection::Income, 'is_active' => true,
        ]);

        return [$owner, $till, $bank, $rent, $desk];
    });

    [$this->owner, $this->till, $this->bank, $this->rent, $this->desk] = $fixtures;

    $this->inTenant = fn (Closure $callback): mixed => inTenantContext($this->tenant, $callback);
});

afterEach(fn () => app(TenantContext::class)->forget());

/* ------------------------------------------------------- the P&L -- */

it('subtracts operating costs from gross margin and adds other income', function (): void {
    ($this->inTenant)(function (): void {
        $transactions = app(RecordCashTransaction::class);
        $at = CarbonImmutable::parse('2026-08-05');

        $transactions->record($this->rent, $this->till, 80_000_000, $at);
        $transactions->record($this->desk, $this->till, 15_000_000, $at);

        $pnl = app(ProfitAndLoss::class)->forPeriod(
            CarbonImmutable::parse('2026-08-01'),
            CarbonImmutable::parse('2026-08-31'),
        );

        // No sales in this window, so the whole result is overheads against desk rent.
        expect($pnl['operating_costs'])->toBe(80_000_000)
            ->and($pnl['other_income'])->toBe(15_000_000)
            ->and($pnl['net_profit'])->toBe($pnl['gross_margin'] + 15_000_000 - 80_000_000);
    });
});

it('reports income as a positive figure despite the credit convention', function (): void {
    ($this->inTenant)(function (): void {
        app(RecordCashTransaction::class)->record(
            $this->desk, $this->till, 15_000_000, CarbonImmutable::parse('2026-08-05'),
        );

        $pnl = app(ProfitAndLoss::class)->forPeriod(
            CarbonImmutable::parse('2026-08-01'),
            CarbonImmutable::parse('2026-08-31'),
        );

        // An income account is credited, so its raw movement is negative. Leaking that
        // sign would show a shop's rental income as a loss.
        expect($pnl['other_income'])->toBe(15_000_000);
    });
});

it('makes the breakdown rows add up to the headline, including uncategorised costs', function (): void {
    ($this->inTenant)(function (): void {
        $at = CarbonImmutable::parse('2026-08-05');

        app(RecordCashTransaction::class)->record($this->rent, $this->till, 80_000_000, $at);

        // A bank fee posts straight to an expense account with no `cash_transactions` row
        // behind it. It is a real operating cost and must appear, or the rows stop summing
        // to the headline — which is how a shop stops believing a report.
        app(TransferBetweenAccounts::class)->transfer(
            $this->till, $this->bank, 50_000_000, fee: 350_000, occurredAt: $at,
        );

        $pnl = app(ProfitAndLoss::class)->forPeriod(
            CarbonImmutable::parse('2026-08-01'),
            CarbonImmutable::parse('2026-08-31'),
        );

        $summed = 0;

        foreach ($pnl['expense_breakdown'] as $row) {
            $summed += $row['amount'];
        }

        expect($pnl['operating_costs'])->toBe(80_350_000)
            ->and($summed)->toBe($pnl['operating_costs']);

        // And the unexplained part is named rather than dropped.
        $labels = array_column($pnl['expense_breakdown'], 'category');

        expect($labels)->toContain('سایر');
    });
});

it('groups by when the money moved, not by when it was keyed', function (): void {
    ($this->inTenant)(function (): void {
        // Rent paid on the 1st. Whatever `created_at` says, it belongs to August.
        app(RecordCashTransaction::class)->record(
            $this->rent, $this->till, 80_000_000, CarbonImmutable::parse('2026-08-01'),
        );

        $july = app(ProfitAndLoss::class)->forPeriod(
            CarbonImmutable::parse('2026-07-01'), CarbonImmutable::parse('2026-07-31'),
        );

        $august = app(ProfitAndLoss::class)->forPeriod(
            CarbonImmutable::parse('2026-08-01'), CarbonImmutable::parse('2026-08-31'),
        );

        expect($july['operating_costs'])->toBe(0)
            ->and($august['operating_costs'])->toBe(80_000_000);
    });
});

/* ------------------------------------------------ the daily close -- */

it('shows opening plus movement equalling closing, on every account', function (): void {
    ($this->inTenant)(function (): void {
        $at = CarbonImmutable::parse('2026-08-05');

        app(TransferBetweenAccounts::class)->transfer($this->till, $this->bank, 40_000_000, occurredAt: $at);
        app(RecordCashTransaction::class)->record($this->rent, $this->till, 10_000_000, $at);

        $close = app(DailyClose::class)->for($at);

        foreach ($close['accounts'] as $row) {
            // The arithmetic has to be visible, or an operator staring at a discrepancy
            // cannot find where it entered.
            expect($row['opening'] + $row['movement'])->toBe($row['closing']);
        }

        expect($close['totals']['opening'] + $close['totals']['movement'])
            ->toBe($close['totals']['closing']);
    });
});

it('opens the day where the previous day closed', function (): void {
    ($this->inTenant)(function (): void {
        app(TransferBetweenAccounts::class)->transfer(
            $this->till, $this->bank, 40_000_000, occurredAt: CarbonImmutable::parse('2026-08-05'),
        );

        $fifth = app(DailyClose::class)->for(CarbonImmutable::parse('2026-08-05'));
        $sixth = app(DailyClose::class)->for(CarbonImmutable::parse('2026-08-06'));

        expect($sixth['totals']['opening'])->toBe($fifth['totals']['closing'])
            // Nothing happened on the 6th.
            ->and($sixth['totals']['movement'])->toBe(0);
    });
});

it('closes on the same figure the treasury page shows', function (): void {
    ($this->inTenant)(function (): void {
        $at = CarbonImmutable::parse('2026-08-05');

        app(RecordCashTransaction::class)->record($this->rent, $this->till, 10_000_000, $at);

        $close = app(DailyClose::class)->for($at);
        $balances = app(AccountBalances::class);

        foreach ($close['accounts'] as $row) {
            /** @var Account $account */
            $account = Account::query()->findOrFail($row['id']);

            // Two different code paths. A shopkeeper comparing them is the first person to
            // find out when they diverge.
            expect($row['closing'])->toBe($balances->balanceOf($account));
        }
    });
});

it('lists only places money actually sits', function (): void {
    ($this->inTenant)(function (): void {
        $close = app(DailyClose::class)->for(CarbonImmutable::parse('2026-08-05'));

        $types = array_unique(array_column($close['accounts'], 'type'));

        // Asking how much is "in" the rent account is a category error, and a close that
        // listed it beside the till would invite exactly that question.
        expect($types)->not->toContain(Account::TYPE_EXPENSE)
            ->and($types)->not->toContain(Account::TYPE_INCOME)
            ->and($types)->not->toContain(Account::TYPE_SALES);
    });
});

it('shows what nobody has reconciled beside the balance', function (): void {
    ($this->inTenant)(function (): void {
        $at = CarbonImmutable::parse('2026-08-05');

        app(TransferBetweenAccounts::class)->transfer($this->till, $this->bank, 40_000_000, occurredAt: $at);

        $close = app(DailyClose::class)->for($at);

        $bank = collect($close['accounts'])->firstWhere('id', $this->bank->id);

        expect($bank)->not->toBeNull();

        // A balance that is right with entries nobody has ticked is a shop that has not
        // checked anything. The two numbers belong next to each other.
        expect($bank['unreconciled'] ?? null)->toBe(40_000_000);
    });
});
