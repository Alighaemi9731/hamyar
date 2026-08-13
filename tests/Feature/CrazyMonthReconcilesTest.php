<?php

declare(strict_types=1);

use App\Modules\CRM\Models\Account;
use App\Modules\CRM\Models\LedgerEntry;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Platform\Services\PlanCatalogueSeeder;
use App\Modules\Platform\Services\SubscriptionResolver;
use App\Modules\Platform\Services\TenantProvisioner;
use App\Modules\Treasury\Services\AccountBalances;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\CrazyMonthSeeder;

/**
 * The Phase 7 Definition of Done, as a test.
 *
 * "A seeded 'one crazy month' scenario reconciles to the rial across all reports."
 *
 * This file grows with {@see CrazyMonthSeeder}, one slice at a time, and that is the whole
 * point of it existing this early. Assembled at the end of the phase it would be a week of
 * bisecting six subsystems that all post into one ledger; run after every slice, a break is
 * attributable to the slice that caused it.
 *
 * ## What "reconciles" means here
 *
 * Four claims, and every one of them is the kind a shop would notice:
 *
 * 1. **The ledger balances.** Total debits equal total credits, globally. If this fails,
 *    money was created or destroyed and nothing else is worth checking.
 * 2. **Every batch balances on its own.** The global sum can be right while two individual
 *    events are wrong in opposite directions — a state that hides until somebody reverses
 *    one of them.
 * 3. **Each account's balance equals its entries.** The treasury page and the statement are
 *    computed by different code paths, and a shopkeeper checking one against the other is
 *    the first person to find out when they diverge.
 * 4. **Money is conserved across transfers.** Moving cash to the bank changes where the
 *    shop's money is, never how much of it there is — less anything genuinely spent.
 */
beforeEach(function (): void {
    app(PlanCatalogueSeeder::class)->sync();

    $this->tenant = Tenant::factory()->withDomain()->create(['slug' => 'demo']);

    subscribe($this->tenant, 'pro');
    app(SubscriptionResolver::class)->forget();
    app(TenantProvisioner::class)->seedRoles($this->tenant);

    inTenantContext($this->tenant, function (): void {
        $owner = User::factory()->create();
        $owner->assignRole('Owner');

        Warehouse::factory()->create(['is_sellable' => true, 'is_default' => true]);
    });

    $this->inTenant = fn (Closure $callback): mixed => inTenantContext($this->tenant, $callback);

    // Driven through the real seeder, so what is asserted below is what `make fresh`
    // produces — not a fixture built to satisfy the assertions.
    (new CrazyMonthSeeder)->run();
});

afterEach(fn () => app(TenantContext::class)->forget());

/* ------------------------------------------------- the four claims -- */

it('balances globally — no money was created or destroyed', function (): void {
    ($this->inTenant)(function (): void {
        $debits = LedgerEntry::query()->sum('debit');
        $credits = LedgerEntry::query()->sum('credit');

        expect($debits)->toBeGreaterThan(0)
            ->and((int) $debits)->toBe((int) $credits);
    });
});

it('balances every batch on its own, not merely in total', function (): void {
    ($this->inTenant)(function (): void {
        // A global sum can be right while two events are wrong in opposite directions.
        // That state survives every report until somebody reverses one of them.
        $unbalanced = LedgerEntry::query()
            ->groupBy('batch_id')
            ->havingRaw('coalesce(sum(debit), 0) <> coalesce(sum(credit), 0)')
            ->pluck('batch_id');

        expect($unbalanced)->toBeEmpty();
    });
});

it('agrees with itself about every account balance', function (): void {
    ($this->inTenant)(function (): void {
        $balances = app(AccountBalances::class);

        foreach (Account::query()->get() as $account) {
            // The treasury page reads `balances()`; a statement reads `balanceOf()`.
            // Different code paths, and a shopkeeper comparing them is the first to know
            // when they diverge.
            $fromMany = $balances->balances([$account->id])[$account->id] ?? null;

            expect($fromMany)->toBe($balances->balanceOf($account));
        }
    });
});

it('conserves money across transfers, less what was genuinely spent', function (): void {
    ($this->inTenant)(function (): void {
        $balances = app(AccountBalances::class);

        $held = 0;
        $opening = 0;

        foreach (Account::query()->get() as $account) {
            if ($account->holdsMoney()) {
                $held += $balances->balanceOf($account);
                $opening += $account->opening_balance;
            }
        }

        $spent = 0;

        foreach (Account::query()->where('type', Account::TYPE_EXPENSE)->get() as $expense) {
            $spent += $balances->balanceOf($expense);
        }

        // Slice 1 has no sales and no purchases, so the only thing that moved the shop's
        // total is the 850,000 the PSP kept. Every later slice adds revenue and cost to
        // both sides of this, and the identity has to keep holding.
        expect($held)->toBe($opening - $spent)
            ->and($spent)->toBe(850_000);
    });
});

/* ---------------------------------------------- slice 1 — banking -- */

it('banks the takings without changing what the shop is worth', function (): void {
    ($this->inTenant)(function (): void {
        $balances = app(AccountBalances::class);

        $till = Account::query()->where('type', Account::TYPE_CASH)->firstOrFail();
        $bank = Account::query()->where('type', Account::TYPE_BANK)->firstOrFail();
        $terminal = Account::query()->where('type', Account::TYPE_POS_TERMINAL)->firstOrFail();

        // 50,000,000 opening, 30,000,000 banked.
        expect($balances->balanceOf($till))->toBe(20_000_000)
            // 0 opening, 120,000,000 taken on card, all of it settled away.
            ->and($balances->balanceOf($terminal))->toBe(-120_850_000)
            // 800,000,000 opening + 30,000,000 cash + 120,000,000 card.
            ->and($balances->balanceOf($bank))->toBe(950_000_000);
    });
});

it('books the PSP charge where a P&L can find it', function (): void {
    ($this->inTenant)(function (): void {
        $fees = Account::query()->where('type', Account::TYPE_EXPENSE)->firstOrFail();

        // Not folded into the settled amount: the bank received the full 120,000,000 and
        // the 850,000 left the shop separately. Folding them would make this figure
        // invisible to every report that asks what card processing costs.
        expect(app(AccountBalances::class)->balanceOf($fees))->toBe(850_000);
    });
});
