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

/*
| Every close below names its day as a SHOP day: a Tehran wall-clock instant for when the
| money moved, and the Tehran date for the day being closed. These tests used to write
| `CarbonImmutable::parse('2026-08-05')` for both — UTC midnight, 03:30 in Tehran — which
| passed against a close that computed its window in UTC, because nothing ever moved near
| either midnight. The last two tests are the ones that move money there.
|
| An instant is handed over as UTC (`->utc()`), the way the application stores one: the
| query builder writes a Carbon's wall-clock digits as it finds them.
*/

it('shows opening plus movement equalling closing, on every account', function (): void {
    ($this->inTenant)(function (): void {
        // Midday on ۱۴ مرداد in Tehran.
        $at = CarbonImmutable::parse('2026-08-05 12:00:00', 'Asia/Tehran')->utc();

        app(TransferBetweenAccounts::class)->transfer($this->till, $this->bank, 40_000_000, occurredAt: $at);
        app(RecordCashTransaction::class)->record($this->rent, $this->till, 10_000_000, $at);

        $close = app(DailyClose::class)->for(CarbonImmutable::parse('2026-08-05', 'Asia/Tehran'));

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
            $this->till, $this->bank, 40_000_000, occurredAt: CarbonImmutable::parse('2026-08-05 12:00:00', 'Asia/Tehran')->utc(),
        );

        // The shop days ۱۴ and ۱۵ مرداد.
        $fifth = app(DailyClose::class)->for(CarbonImmutable::parse('2026-08-05', 'Asia/Tehran'));
        $sixth = app(DailyClose::class)->for(CarbonImmutable::parse('2026-08-06', 'Asia/Tehran'));

        expect($sixth['totals']['opening'])->toBe($fifth['totals']['closing'])
            // Nothing happened on the 6th.
            ->and($sixth['totals']['movement'])->toBe(0);
    });
});

it('closes on the same figure the treasury page shows', function (): void {
    ($this->inTenant)(function (): void {
        app(RecordCashTransaction::class)->record(
            $this->rent, $this->till, 10_000_000, CarbonImmutable::parse('2026-08-05 12:00:00', 'Asia/Tehran')->utc(),
        );

        $close = app(DailyClose::class)->for(CarbonImmutable::parse('2026-08-05', 'Asia/Tehran'));
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
        $close = app(DailyClose::class)->for(CarbonImmutable::parse('2026-08-05', 'Asia/Tehran'));

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
        app(TransferBetweenAccounts::class)->transfer(
            $this->till, $this->bank, 40_000_000, occurredAt: CarbonImmutable::parse('2026-08-05 12:00:00', 'Asia/Tehran')->utc(),
        );

        $close = app(DailyClose::class)->for(CarbonImmutable::parse('2026-08-05', 'Asia/Tehran'));

        $bank = collect($close['accounts'])->firstWhere('id', $this->bank->id);

        expect($bank)->not->toBeNull();

        // A balance that is right with entries nobody has ticked is a shop that has not
        // checked anything. The two numbers belong next to each other.
        expect($bank['unreconciled'] ?? null)->toBe(40_000_000);
    });
});

/*
| 21:00 UTC on 2026-08-05 is 00:30 on 2026-08-06 in Tehran — ۱۵ مرداد. Rent paid then
| belongs to the 6th's close. The old window was the UTC day, 03:30 to 03:29 in Tehran,
| and closed it with the 5th: the 5th's till was short by a payment made after it shut,
| and the 6th's was over by the same amount.
*/
it('closes a payment made at 00:30 Tehran with the next shop day', function (): void {
    ($this->inTenant)(function (): void {
        app(RecordCashTransaction::class)->record(
            $this->rent, $this->till, 10_000_000, CarbonImmutable::parse('2026-08-05 21:00:00', 'UTC'),
        );

        /** @param array{accounts: list<array{id: int, movement: int}>} $close */
        $movementOfTill = function (array $close): ?int {
            foreach ($close['accounts'] as $row) {
                if ($row['id'] === $this->till->id) {
                    return $row['movement'];
                }
            }

            return null;
        };

        $fifth = app(DailyClose::class)->for(CarbonImmutable::parse('2026-08-05', 'Asia/Tehran'));
        $sixth = app(DailyClose::class)->for(CarbonImmutable::parse('2026-08-06', 'Asia/Tehran'));

        expect($fifth['date'])->toBe('2026-08-05')
            ->and($movementOfTill($fifth))->toBe(0)
            ->and($sixth['date'])->toBe('2026-08-06')
            ->and($movementOfTill($sixth))->toBe(-10_000_000)
            ->and($sixth['totals']['opening'])->toBe($fifth['totals']['closing']);

        // Asked with the instant itself, the close is the shop day it fell on.
        expect(app(DailyClose::class)->for(CarbonImmutable::parse('2026-08-05 21:00:00', 'UTC'))['date'])
            ->toBe('2026-08-06');
    });
});

it('opens the close screen at 00:30 Tehran on the shop day, with this shop money only', function (): void {
    ($this->inTenant)(function (): void {
        app(RecordCashTransaction::class)->record(
            $this->rent, $this->till, 10_000_000, CarbonImmutable::parse('2026-08-05 21:00:00', 'UTC'),
        );
    });

    // Another shop pays its own rent at the same instant, from a till of its own.
    $other = Tenant::factory()->withDomain()->create();
    subscribe($other, 'pro');
    app(SubscriptionResolver::class)->forget();
    app(TenantProvisioner::class)->seedRoles($other);

    /** @var array{User, Account} $neighbour */
    $neighbour = inTenantContext($other, function (): array {
        $owner = User::factory()->create();
        $owner->assignRole('Owner');

        $till = Account::factory()->create([
            'type' => Account::TYPE_CASH, 'name' => 'صندوق همسایه', 'opening_balance' => 90_000_000,
        ]);

        $rent = TransactionCategory::query()->create([
            'account_id' => Account::factory()->create(['type' => Account::TYPE_EXPENSE, 'name' => 'اجاره'])->id,
            'name' => 'اجاره', 'direction' => CashDirection::Expense, 'is_active' => true,
        ]);

        app(RecordCashTransaction::class)->record($rent, $till, 7_000_000, CarbonImmutable::parse('2026-08-05 21:00:00', 'UTC'));

        return [$owner, $till];
    });

    [$neighbourOwner, $neighbourTill] = $neighbour;

    // 00:30 in Tehran on ۱۵ مرداد; the UTC date is still the 5th.
    $this->travelTo(CarbonImmutable::parse('2026-08-05 21:00:00', 'UTC'));

    $this->actingAs($this->owner)
        ->get(appUrl('/treasury/close'))
        ->assertOk()
        ->assertInertia(function ($page): void {
            $props = propsOf($page);

            /** @var list<array{id: int, movement: array{value: int}}> $accounts */
            $accounts = $props['accounts'];

            /** @var array{movement: array{value: int}}|null $till */
            $till = collect($accounts)->firstWhere('id', $this->till->id);

            expect($props['date'])->toBe('2026-08-06')
                ->and(array_column($accounts, 'id'))->toEqualCanonicalizing([$this->till->id, $this->bank->id])
                ->and($till['movement']['value'] ?? null)->toBe(-10_000_000);
        });

    // The day before, named the way the screen's `date` names a day: nothing moved on it.
    $this->actingAs($this->owner)
        ->get(appUrl('/treasury/close?date=2026-08-05'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('date', '2026-08-05')
            ->where('totals.movement.value', 0)
            ->etc()
        );

    // And the neighbour's close holds the neighbour's money and nothing of this shop's.
    $this->actingAs($neighbourOwner)
        ->get(appUrl('/treasury/close'))
        ->assertOk()
        ->assertInertia(function ($page) use ($neighbourTill): void {
            /** @var list<array{id: int, movement: array{value: int}}> $accounts */
            $accounts = propsOf($page)['accounts'];

            expect(array_column($accounts, 'id'))->toBe([$neighbourTill->id])
                ->and($accounts[0]['movement']['value'])->toBe(-7_000_000);
        });
})->group('isolation');
