<?php

declare(strict_types=1);

use App\Modules\Cheques\Enums\ChequeStatus;
use App\Modules\Cheques\Models\Cheque;
use App\Modules\Cheques\Services\ChequeAccounts;
use App\Modules\Cheques\Services\ChequeExposure;
use App\Modules\CRM\Models\Account;
use App\Modules\CRM\Models\LedgerEntry;
use App\Modules\CRM\Models\Party;
use App\Modules\CRM\Services\LedgerService;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Platform\Services\PlanCatalogueSeeder;
use App\Modules\Platform\Services\SubscriptionResolver;
use App\Modules\Platform\Services\TenantProvisioner;
use App\Modules\Treasury\Models\CashTransaction;
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

it('closes the books — every balance summed equals every opening balance summed', function (): void {
    ($this->inTenant)(function (): void {
        /*
        | THE reconciliation, and the one assertion in this file that never needs editing
        | when a slice lands.
        |
        | Every ledger row names exactly one subject and carries exactly one of debit or
        | credit, and every batch balances. So across ALL subjects — accounts and parties
        | together — the movements must cancel to zero, and the only thing left standing is
        | what the shop started with.
        |
        | It is slice-independent by construction, which is what makes it trustworthy: a
        | figure I have to update when I add a sale is a figure I could update wrongly.
        */
        $balances = app(AccountBalances::class);
        $ledger = app(LedgerService::class);

        $totalBalances = 0;
        $totalOpenings = 0;

        foreach (Account::query()->get() as $account) {
            $totalBalances += $balances->balanceOf($account);
            $totalOpenings += $account->opening_balance;
        }

        foreach (Party::query()->get() as $party) {
            $totalBalances += $ledger->partyBalance($party);
            $totalOpenings += $party->opening_balance;
        }

        expect($totalOpenings)->toBe(850_000_000)
            ->and($totalBalances)->toBe($totalOpenings);
    });
});

/* ------------------------------------ slice 1 — banking and the PSP cut -- */

it('banks the takings without changing what the shop is worth', function (): void {
    ($this->inTenant)(function (): void {
        $balances = app(AccountBalances::class);

        $till = Account::query()->where('type', Account::TYPE_CASH)->firstOrFail();
        $terminal = Account::query()->where('type', Account::TYPE_POS_TERMINAL)->firstOrFail();

        // 50,000,000 opening, 30,000,000 banked.
        expect($balances->balanceOf($till))->toBe(20_000_000)
            // 0 opening, 120,000,000 taken on card, all of it settled away plus the cut.
            ->and($balances->balanceOf($terminal))->toBe(-120_850_000);
    });
});

it('books the PSP charge and the bounce fee where a P&L can find them', function (): void {
    ($this->inTenant)(function (): void {
        $charges = Account::query()
            ->where('type', Account::TYPE_EXPENSE)
            ->where('name', 'کارمزد بانکی')
            ->firstOrFail();

        // 850,000 from the card settlement, 300,000 when the cheque came back. Neither is
        // folded into the amount it accompanied — folding makes them invisible to every
        // report that asks what banking costs this shop.
        expect(app(AccountBalances::class)->balanceOf($charges))->toBe(1_150_000);
    });
});

/* ------------------------------------------------ slice 2 — the rent -- */

it('books exactly one month of rent, from the bank', function (): void {
    ($this->inTenant)(function (): void {
        $rent = Account::query()
            ->where('type', Account::TYPE_EXPENSE)
            ->where('name', 'اجاره مغازه')
            ->firstOrFail();

        // One Jalali period falls inside the month. A second would mean the generator
        // double-booked, which is the failure this whole seeder exists to catch early.
        expect(app(AccountBalances::class)->balanceOf($rent))->toBe(120_000_000)
            ->and(CashTransaction::query()->where('direction', 'expense')->count())->toBe(1);
    });
});

/* ------------------------------- slice 3 — the cheque that bounced -- */

it('leaves the bounced-and-recovered cheque fully collected', function (): void {
    ($this->inTenant)(function (): void {
        $cheque = Cheque::query()->where('serial', '445678')->firstOrFail();

        // Received, deposited, bounced, re-presented, cleared. Five events, and the money
        // is in the bank at the end.
        expect($cheque->status)->toBe(ChequeStatus::Cleared)
            ->and($cheque->presentation_attempt)->toBe(2)
            ->and($cheque->events()->count())->toBe(5);
    });
});

it('empties both cheque holding accounts once nothing is in flight', function (): void {
    ($this->inTenant)(function (): void {
        $accounts = app(ChequeAccounts::class);
        $balances = app(AccountBalances::class);

        // One cheque cleared, one endorsed away. Nothing is in the drawer and nothing is
        // at a bank, so both accounts must be flat — this is the drawer-count invariant,
        // and it is the cheapest way a shop ever discovers a missing cheque.
        expect($balances->balanceOf($accounts->receivable()))->toBe(0)
            ->and($balances->balanceOf($accounts->inCollection()))->toBe(0);
    });
});

it('still counts the endorsed cheque as exposure at month end', function (): void {
    ($this->inTenant)(function (): void {
        $customer = Party::query()->where('name', 'حسن رضایی')->firstOrFail();

        // They owe nothing — both cheques settled their debts on receipt — and the shop is
        // still carrying 280,000,000 of their paper, endorsed onward but not discharged.
        expect(app(LedgerService::class)->partyBalance($customer))->toBe(0)
            ->and(app(ChequeExposure::class)->forParty($customer))->toBe(280_000_000);
    });
});

it('leaves the supplier owed nothing but the difference', function (): void {
    ($this->inTenant)(function (): void {
        $supplier = Party::query()->where('name', 'پخش موبایل ایرانیان')->firstOrFail();

        // Owed 300,000,000 for the shipment, settled with 280,000,000 of endorsed paper.
        // Negative means the shop still owes them 20,000,000.
        expect(app(LedgerService::class)->partyBalance($supplier))->toBe(-20_000_000);
    });
});
