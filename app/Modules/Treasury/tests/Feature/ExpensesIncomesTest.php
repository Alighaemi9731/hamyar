<?php

declare(strict_types=1);

use App\Modules\CRM\Models\Account;
use App\Modules\CRM\Models\LedgerEntry;
use App\Modules\CRM\Models\Party;
use App\Modules\Identity\Models\User;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Platform\Services\PlanCatalogueSeeder;
use App\Modules\Platform\Services\SubscriptionResolver;
use App\Modules\Platform\Services\TenantProvisioner;
use App\Modules\Treasury\Enums\CashDirection;
use App\Modules\Treasury\Models\CashTransaction;
use App\Modules\Treasury\Models\RecurringTemplate;
use App\Modules\Treasury\Models\RentalContract;
use App\Modules\Treasury\Models\TransactionCategory;
use App\Modules\Treasury\Services\AccountBalances;
use App\Modules\Treasury\Services\GenerateRecurring;
use App\Modules\Treasury\Services\RecordCashTransaction;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;

/**
 * Rent, wages, and the desk the shop leases out.
 *
 * Most of the difference between a mobile shop's turnover and what its owner keeps. The
 * tests that matter here are the idempotency ones: a generator that books August's rent
 * twice produces a P&L that is wrong by a plausible-looking amount, which is the worst
 * kind of wrong.
 */
beforeEach(function (): void {
    app(PlanCatalogueSeeder::class)->sync();

    $this->tenant = Tenant::factory()->withDomain()->create();
    $this->url = appUrl();

    subscribe($this->tenant, 'pro');
    app(SubscriptionResolver::class)->forget();
    app(TenantProvisioner::class)->seedRoles($this->tenant);

    /** @var array{User, Account, TransactionCategory, TransactionCategory} $fixtures */
    $fixtures = inTenantContext($this->tenant, function (): array {
        $owner = User::factory()->create();
        $owner->assignRole('Owner');

        $till = Account::factory()->create([
            'type' => Account::TYPE_CASH, 'name' => 'صندوق', 'opening_balance' => 500_000_000,
        ]);

        $rentAccount = Account::factory()->create(['type' => Account::TYPE_EXPENSE, 'name' => 'اجاره مغازه']);
        $deskAccount = Account::factory()->create(['type' => Account::TYPE_INCOME, 'name' => 'اجاره میز']);

        $rent = TransactionCategory::query()->create([
            'account_id' => $rentAccount->id, 'name' => 'اجاره مغازه',
            'direction' => CashDirection::Expense, 'is_active' => true,
        ]);

        $desk = TransactionCategory::query()->create([
            'account_id' => $deskAccount->id, 'name' => 'اجاره میز تعمیرات',
            'direction' => CashDirection::Income, 'is_active' => true,
        ]);

        return [$owner, $till, $rent, $desk];
    });

    [$this->owner, $this->till, $this->rent, $this->desk] = $fixtures;

    $this->inTenant = fn (Closure $callback): mixed => inTenantContext($this->tenant, $callback);
});

afterEach(fn () => app(TenantContext::class)->forget());

/* ------------------------------------------------ the two directions -- */

it('takes money out of the till for an expense', function (): void {
    ($this->inTenant)(function (): void {
        app(RecordCashTransaction::class)->record(
            $this->rent, $this->till, 80_000_000, CarbonImmutable::parse('2026-08-01'),
            description: 'اجاره مرداد',
        );

        $balances = app(AccountBalances::class);

        expect($balances->balanceOf($this->till))->toBe(420_000_000)
            // And the expense is somewhere a P&L can find it, under its own heading.
            ->and($balances->balanceOf($this->rent->account))->toBe(80_000_000);
    });
});

it('puts money into the till for an income', function (): void {
    ($this->inTenant)(function (): void {
        app(RecordCashTransaction::class)->record(
            $this->desk, $this->till, 15_000_000, CarbonImmutable::parse('2026-08-01'),
        );

        $balances = app(AccountBalances::class);

        expect($balances->balanceOf($this->till))->toBe(515_000_000)
            ->and($balances->balanceOf($this->desk->account))->toBe(-15_000_000);
    });
});

it('leaves the party balance alone when cash changes hands on the spot', function (): void {
    ($this->inTenant)(function (): void {
        $landlord = Party::factory()->create(['name' => 'مالک مغازه']);

        app(RecordCashTransaction::class)->record(
            $this->rent, $this->till, 80_000_000, CarbonImmutable::parse('2026-08-01'),
            partyId: $landlord->id,
        );

        // Paying the landlord in cash settles nothing between them and the shop — the
        // money changed hands. Debiting the party as well would make the shop appear to
        // be owed a month's rent it has just paid.
        expect(LedgerEntry::query()->whereNotNull('party_id')->count())->toBe(0)
            // The party is on the transaction row for "what have we paid this person",
            // which is a reporting question rather than a ledger one.
            ->and(CashTransaction::query()->firstOrFail()->party_id)->toBe($landlord->id);
    });
});

/* ------------------------------------------------------- refusals -- */

it('refuses to pay out of a heading rather than a place', function (): void {
    ($this->inTenant)(function (): void {
        $sales = Account::factory()->create(['type' => Account::TYPE_SALES]);

        // Paying rent "out of" the sales account is a category error that produces a
        // balance nobody can explain.
        expect(fn () => app(RecordCashTransaction::class)->record(
            $this->rent, $sales, 1_000_000, CarbonImmutable::parse('2026-08-01'),
        ))->toThrow(RuntimeException::class);
    });
});

it('refuses an amount that could never be printed', function (): void {
    ($this->inTenant)(function (): void {
        // ADR 0009: not a whole number of toman, so `Money` would refuse to render it —
        // on the receipt, with somebody waiting for it.
        expect(fn () => app(RecordCashTransaction::class)->record(
            $this->rent, $this->till, 1_000_005, CarbonImmutable::parse('2026-08-01'),
        ))->toThrow(RuntimeException::class);
    });
});

/* ------------------------------------------ THE IDEMPOTENCY THAT MATTERS -- */

it('books a month of rent once, however many times the generator runs', function (): void {
    ($this->inTenant)(function (): void {
        RecurringTemplate::query()->create([
            'transaction_category_id' => $this->rent->id,
            'account_id' => $this->till->id,
            'name' => 'اجاره ماهانه مغازه',
            'direction' => CashDirection::Expense,
            'amount' => 80_000_000,
            'day_of_month' => 1,
            'starts_on' => '2026-07-23',
        ]);

        $first = app(GenerateRecurring::class)->run(CarbonImmutable::parse('2026-08-25'));

        // Mordad and Shahrivar have both begun by 25 August 2026.
        expect($first['generated'])->toBeGreaterThan(0);

        $booked = CashTransaction::query()->count();

        // A retry after a timeout, a second worker on a bad deploy, an owner pressing the
        // button because they are not sure it ran. All of them, and the answer is the same.
        app(GenerateRecurring::class)->run(CarbonImmutable::parse('2026-08-25'));
        app(GenerateRecurring::class)->run(CarbonImmutable::parse('2026-08-25'));

        expect(CashTransaction::query()->count())->toBe($booked);
    });
});

it('catches up periods a switched-off template missed', function (): void {
    ($this->inTenant)(function (): void {
        RecurringTemplate::query()->create([
            'transaction_category_id' => $this->rent->id,
            'account_id' => $this->till->id,
            'name' => 'اجاره ماهانه',
            'direction' => CashDirection::Expense,
            'amount' => 10_000_000,
            'day_of_month' => 1,
            'starts_on' => '2026-05-01',
        ]);

        // There is no pointer to resume from — the generator asks which periods have not
        // been booked, so four months of silence is four months of catching up.
        $result = app(GenerateRecurring::class)->run(CarbonImmutable::parse('2026-08-25'));

        expect($result['generated'])->toBeGreaterThanOrEqual(3)
            ->and(CashTransaction::query()->count())->toBe($result['generated']);
    });
});

it('stops generating after a template ends', function (): void {
    ($this->inTenant)(function (): void {
        RecurringTemplate::query()->create([
            'transaction_category_id' => $this->rent->id,
            'account_id' => $this->till->id,
            'name' => 'اجاره موقت',
            'direction' => CashDirection::Expense,
            'amount' => 5_000_000,
            'day_of_month' => 1,
            'starts_on' => '2026-05-01',
            'ends_on' => '2026-06-15',
        ]);

        app(GenerateRecurring::class)->run(CarbonImmutable::parse('2026-08-25'));

        $latest = CashTransaction::query()->orderByDesc('occurred_at')->firstOrFail();

        expect($latest->occurred_at->lessThanOrEqualTo(CarbonImmutable::parse('2026-06-15')))->toBeTrue();
    });
});

/* ----------------------------------------------------- rentals -- */

it('earns rent from a leased desk, month after month', function (): void {
    ($this->inTenant)(function (): void {
        $technician = Party::factory()->create(['name' => 'آقای کریمی']);

        RentalContract::query()->create([
            'party_id' => $technician->id,
            'transaction_category_id' => $this->desk->id,
            'account_id' => $this->till->id,
            'number' => 'RNT-000001',
            'title' => 'میز تعمیرات — گوشه شمالی',
            'monthly_amount' => 12_000_000,
            'deposit' => 50_000_000,
            'due_day' => 1,
            'starts_on' => '2026-06-01',
        ]);

        app(GenerateRecurring::class)->run(CarbonImmutable::parse('2026-08-25'));

        $rows = CashTransaction::query()->where('direction', CashDirection::Income->value)->get();

        expect($rows->count())->toBeGreaterThanOrEqual(2)
            ->and($rows->every(fn (CashTransaction $t): bool => $t->amount === 12_000_000))->toBeTrue();

        // The deposit is held, not earned. Booking ودیعه as revenue overstates the month
        // it arrives in and understates the month it is given back.
        expect($rows->sum(fn (CashTransaction $t): int => $t->amount))->toBe(12_000_000 * $rows->count());
    });
});

it('stops earning the day a contract is terminated', function (): void {
    ($this->inTenant)(function (): void {
        $technician = Party::factory()->create();

        RentalContract::query()->create([
            'party_id' => $technician->id,
            'transaction_category_id' => $this->desk->id,
            'account_id' => $this->till->id,
            'number' => 'RNT-000002',
            'title' => 'میز دوم',
            'monthly_amount' => 9_000_000,
            'due_day' => 1,
            'starts_on' => '2026-05-01',
            // Ended early. Termination beats the paper's end date.
            'terminated_on' => '2026-06-20',
        ]);

        app(GenerateRecurring::class)->run(CarbonImmutable::parse('2026-08-25'));

        $latest = CashTransaction::query()->orderByDesc('occurred_at')->first();

        expect($latest?->occurred_at->lessThanOrEqualTo(CarbonImmutable::parse('2026-06-20')))->toBeTrue();
    });
});

/* -------------------------------------------------------- tenancy -- */

it('will not spend another shop money', function (): void {
    $other = Tenant::factory()->withDomain()->create();
    subscribe($other, 'pro');
    app(SubscriptionResolver::class)->forget();

    /** @var TransactionCategory $theirs */
    $theirs = inTenantContext($other, function (): TransactionCategory {
        $account = Account::factory()->create(['type' => Account::TYPE_EXPENSE]);

        return TransactionCategory::query()->create([
            'account_id' => $account->id, 'name' => 'هزینه آنها',
            'direction' => CashDirection::Expense, 'is_active' => true,
        ]);
    });

    ($this->inTenant)(function () use ($theirs): void {
        expect(TransactionCategory::query()->find($theirs->id))->toBeNull();
    });
});
