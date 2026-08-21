<?php

declare(strict_types=1);

use App\Modules\CRM\Models\Account;
use App\Modules\CRM\Models\LedgerEntry;
use App\Modules\Identity\Models\User;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Platform\Services\PlanCatalogueSeeder;
use App\Modules\Platform\Services\SubscriptionResolver;
use App\Modules\Platform\Services\TenantProvisioner;
use App\Modules\Treasury\Models\AccountTransfer;
use App\Modules\Treasury\Services\AccountBalances;
use App\Modules\Treasury\Services\TransferBetweenAccounts;
use App\Support\Tenancy\TenantContext;

/**
 * Banking the takings.
 *
 * The operation most likely to be got subtly wrong, because it is the only common event
 * where no party is involved and the shop's total does not change. Every test here is
 * really asking one question: is the shop still worth the same afterwards?
 */
beforeEach(function (): void {
    app(PlanCatalogueSeeder::class)->sync();

    $this->tenant = Tenant::factory()->withDomain()->create();
    $this->url = appUrl();

    subscribe($this->tenant, 'pro');
    app(SubscriptionResolver::class)->forget();
    app(TenantProvisioner::class)->seedRoles($this->tenant);

    /** @var array{User, Account, Account} $fixtures */
    $fixtures = inTenantContext($this->tenant, function (): array {
        $owner = User::factory()->create();
        $owner->assignRole('Owner');

        // 100,000,000 rial in the drawer to start with.
        $till = Account::factory()->create([
            'type' => Account::TYPE_CASH,
            'name' => 'صندوق فروشگاه',
            'is_default' => true,
            'opening_balance' => 100_000_000,
        ]);

        $bank = Account::factory()->create([
            'type' => Account::TYPE_BANK,
            'name' => 'بانک ملت',
            'opening_balance' => 0,
        ]);

        return [$owner, $till, $bank];
    });

    [$this->owner, $this->till, $this->bank] = $fixtures;

    $this->inTenant = fn (Closure $callback): mixed => inTenantContext($this->tenant, $callback);
});

afterEach(fn () => app(TenantContext::class)->forget());

/* ------------------------------------------- the shop is no richer -- */

it('moves money without changing what the shop is worth', function (): void {
    ($this->inTenant)(function (): void {
        $balances = app(AccountBalances::class);

        $before = $balances->balanceOf($this->till) + $balances->balanceOf($this->bank);

        app(TransferBetweenAccounts::class)->transfer(
            $this->till, $this->bank, 40_000_000, actorId: $this->owner->id,
        );

        $after = $balances->balanceOf($this->till->fresh() ?? $this->till)
            + $balances->balanceOf($this->bank->fresh() ?? $this->bank);

        expect($balances->balanceOf($this->till->fresh() ?? $this->till))->toBe(60_000_000)
            ->and($balances->balanceOf($this->bank->fresh() ?? $this->bank))->toBe(40_000_000)
            // The whole point. A transfer that changes this figure is income or
            // shrinkage wearing a transfer's clothes.
            ->and($after)->toBe($before);
    });
});

it('writes two ledger rows that share one batch', function (): void {
    ($this->inTenant)(function (): void {
        app(TransferBetweenAccounts::class)->transfer($this->till, $this->bank, 25_000_000);

        $entries = LedgerEntry::query()->orderBy('id')->get();

        expect($entries)->toHaveCount(2)
            ->and($entries->pluck('batch_id')->unique())->toHaveCount(1)
            ->and($entries->sum(fn (LedgerEntry $e): int => $e->debit))
            ->toBe($entries->sum(fn (LedgerEntry $e): int => $e->credit));
    });
});

/* -------------------------------------------------------- the fee -- */

it('books the fee as a real expense and still balances', function (): void {
    ($this->inTenant)(function (): void {
        // A card settlement: 50,000,000 arrives at the bank, the PSP keeps 350,000.
        app(TransferBetweenAccounts::class)->transfer(
            $this->till, $this->bank, 50_000_000, fee: 350_000,
        );

        $balances = app(AccountBalances::class);

        // The till is down by the amount AND the fee — that money really left.
        expect($balances->balanceOf($this->till->fresh() ?? $this->till))->toBe(49_650_000)
            // The bank got exactly what was sent, not what was sent minus a charge.
            ->and($balances->balanceOf($this->bank->fresh() ?? $this->bank))->toBe(50_000_000);

        $fees = Account::query()->where('type', Account::TYPE_EXPENSE)->firstOrFail();

        // And the 350,000 is somewhere a P&L can find it, rather than evaporated.
        expect($balances->balanceOf($fees))->toBe(350_000);

        $entries = LedgerEntry::query()->get();

        expect($entries->sum(fn (LedgerEntry $e): int => $e->debit))
            ->toBe($entries->sum(fn (LedgerEntry $e): int => $e->credit));
    });
});

it('records the fee beside the amount rather than folded into it', function (): void {
    ($this->inTenant)(function (): void {
        app(TransferBetweenAccounts::class)->transfer(
            $this->till, $this->bank, 50_000_000, fee: 350_000,
        );

        $transfer = AccountTransfer::query()->firstOrFail();

        // 50,000,000 that cost 350,000 — not a transfer of 49,650,000. Folding them
        // makes the charge invisible to every report that would ask about it.
        expect($transfer->amount)->toBe(50_000_000)
            ->and($transfer->fee)->toBe(350_000)
            ->and($transfer->totalOut())->toBe(50_350_000);
    });
});

/* ------------------------------------------------------- refusals -- */

it('refuses to move money to where it already is', function (): void {
    ($this->inTenant)(function (): void {
        expect(fn () => app(TransferBetweenAccounts::class)->transfer($this->till, $this->till, 1_000_000))
            ->toThrow(RuntimeException::class);

        expect(LedgerEntry::query()->count())->toBe(0);
    });
});

it('refuses to overdraw a cash box', function (): void {
    ($this->inTenant)(function (): void {
        // A till cannot hold less than nothing. If the software thinks it does,
        // somebody mistyped, and letting it through buries the mistake.
        expect(fn () => app(TransferBetweenAccounts::class)->transfer($this->till, $this->bank, 500_000_000))
            ->toThrow(RuntimeException::class);

        expect(LedgerEntry::query()->count())->toBe(0);
    });
});

it('lets a bank account go negative, because a real one can', function (): void {
    ($this->inTenant)(function (): void {
        // An overdraft, or a fee landing before a deposit clears. Refusing this would
        // make the software disagree with the statement, which is the one thing a
        // treasury screen must never do.
        app(TransferBetweenAccounts::class)->transfer($this->bank, $this->till, 5_000_000);

        expect(app(AccountBalances::class)->balanceOf($this->bank->fresh() ?? $this->bank))
            ->toBe(-5_000_000);
    });
});

it('refuses a transfer of nothing', function (): void {
    ($this->inTenant)(function (): void {
        expect(fn () => app(TransferBetweenAccounts::class)->transfer($this->till, $this->bank, 0))
            ->toThrow(RuntimeException::class);
    });
});

/* -------------------------------------------------- opening balance -- */

it('counts the opening balance as the start of the statement, not as an entry', function (): void {
    ($this->inTenant)(function (): void {
        // A shop migrating from paper carries a figure in. It is the one stored number
        // in the whole ledger, and it is not a movement — no row represents it.
        expect(app(AccountBalances::class)->balanceOf($this->till))->toBe(100_000_000)
            ->and(LedgerEntry::query()->where('account_id', $this->till->id)->count())->toBe(0);
    });
});

it('reads every account balance in one pass', function (): void {
    ($this->inTenant)(function (): void {
        app(TransferBetweenAccounts::class)->transfer($this->till, $this->bank, 30_000_000);

        $balances = app(AccountBalances::class)->balances();

        expect($balances[$this->till->id])->toBe(70_000_000)
            ->and($balances[$this->bank->id])->toBe(30_000_000);
    });
});

/* -------------------------------------------------------- tenancy -- */

it('will not move another shop money', function (): void {
    $other = Tenant::factory()->withDomain()->create();
    subscribe($other, 'pro');
    app(SubscriptionResolver::class)->forget();

    /** @var Account $theirs */
    $theirs = inTenantContext($other, fn (): Account => Account::factory()->create([
        'type' => Account::TYPE_BANK,
        'opening_balance' => 900_000_000,
    ]));

    ($this->inTenant)(function () use ($theirs): void {
        // The global scope means their account is not even findable from in here, so
        // the transfer has nowhere to land.
        expect(Account::query()->find($theirs->id))->toBeNull();
    });
});
