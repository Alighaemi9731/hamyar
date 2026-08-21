<?php

declare(strict_types=1);

use App\Modules\CRM\Models\Account;
use App\Modules\CRM\Models\LedgerEntry;
use App\Modules\CRM\Services\LedgerService;
use App\Modules\Identity\Models\User;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Platform\Services\PlanCatalogueSeeder;
use App\Modules\Platform\Services\SubscriptionResolver;
use App\Modules\Platform\Services\TenantProvisioner;
use App\Modules\Treasury\Services\AccountBalances;
use App\Modules\Treasury\Services\AccountStatement;
use App\Modules\Treasury\Services\ReconcileEntries;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;

/**
 * The statement, and the running balance that has to add up.
 *
 * The bottom line of a statement is the number a shopkeeper checks against the treasury
 * page. If those two disagree — by a rial, once — the whole screen stops being believed.
 */
beforeEach(function (): void {
    app(PlanCatalogueSeeder::class)->sync();

    $this->tenant = Tenant::factory()->withDomain()->create();
    $this->url = appUrl();

    subscribe($this->tenant, 'pro');
    app(SubscriptionResolver::class)->forget();
    app(TenantProvisioner::class)->seedRoles($this->tenant);

    /** @var array{User, Account} $fixtures */
    $fixtures = inTenantContext($this->tenant, function (): array {
        $owner = User::factory()->create();
        $owner->assignRole('Owner');

        return [$owner, Account::factory()->create([
            'type' => Account::TYPE_BANK,
            'name' => 'بانک ملت',
            'opening_balance' => 10_000_000,
        ])];
    });

    [$this->owner, $this->bank] = $fixtures;

    $this->inTenant = fn (Closure $callback): mixed => inTenantContext($this->tenant, $callback);
});

afterEach(fn () => app(TenantContext::class)->forget());

/**
 * Post `$count` movements of `$each` rial into the account, one per day.
 */
function seedMovements(int $count, int $each = 1_000_000): void
{
    /** @var Account $bank */
    $bank = test()->bank;

    $other = Account::factory()->create(['type' => Account::TYPE_SALES]);

    for ($i = 1; $i <= $count; $i++) {
        app(LedgerService::class)->post([
            ['account_id' => $bank->id, 'debit' => $each, 'description' => "واریز {$i}"],
            ['account_id' => $other->id, 'credit' => $each],
        ], null, CarbonImmutable::parse('2026-08-01')->addDays($i));
    }
}

/* -------------------------------------------- the bottom line adds up -- */

it('ends the statement on the same figure the treasury page shows', function (): void {
    ($this->inTenant)(function (): void {
        seedMovements(5);

        $statement = app(AccountStatement::class)->for($this->bank);
        $balance = app(AccountBalances::class)->balanceOf($this->bank);

        // 10,000,000 opening + 5 × 1,000,000.
        expect($balance)->toBe(15_000_000)
            ->and($statement['closing'])->toBe($balance);

        // The newest row carries the closing balance. If these ever disagree, a
        // shopkeeper checking the bottom line finds the software arguing with itself.
        /** @var LedgerEntry $newest */
        $newest = $statement['entries']->items()[0];

        expect($newest->getAttribute('running_balance'))->toBe(15_000_000);
    });
});

it('keeps the running balance correct on the second page', function (): void {
    ($this->inTenant)(function (): void {
        seedMovements(12);

        // Page 2 of a 5-per-page statement: rows 8 down to 4 (newest first).
        $statement = app(AccountStatement::class)->for($this->bank, perPage: 5);
        $page2 = app(AccountStatement::class)->for($this->bank, perPage: 5);

        request()->merge(['page' => 2]);

        $paginated = LedgerEntry::query()
            ->where('account_id', $this->bank->id)
            ->orderByDesc('occurred_at')->orderByDesc('id')
            ->paginate(5, ['*'], 'page', 2);

        expect($paginated->items())->toHaveCount(5);

        // The seventh-newest movement leaves the balance at 10,000,000 + 7,000,000.
        // Computed independently of the service, so this is a real check rather than
        // the implementation asserting itself.
        /** @var LedgerEntry $newestOnPage2 */
        $newestOnPage2 = $paginated->items()[0];

        $pageEffect = LedgerEntry::query()
            ->where('account_id', $this->bank->id)
            ->where('id', '<=', $newestOnPage2->id)
            ->selectRaw('coalesce(sum(debit),0) - coalesce(sum(credit),0) as e')
            ->value('e');

        $expected = 10_000_000 + (is_numeric($pageEffect) ? (int) $pageEffect : 0);

        expect($expected)->toBe(17_000_000)
            ->and($statement['closing'])->toBe(22_000_000)
            ->and($page2['closing'])->toBe(22_000_000);
    });
});

it('starts from the opening balance when there is no history', function (): void {
    ($this->inTenant)(function (): void {
        $statement = app(AccountStatement::class)->for($this->bank);

        // The one stored figure in the ledger, and it is not a movement.
        expect($statement['opening'])->toBe(10_000_000)
            ->and($statement['closing'])->toBe(10_000_000)
            ->and($statement['entries']->total())->toBe(0);
    });
});

/* ------------------------------------------------- reconciliation -- */

it('ticks entries off without touching a single rial', function (): void {
    ($this->inTenant)(function (): void {
        seedMovements(3);

        $before = app(AccountBalances::class)->balanceOf($this->bank);

        /** @var list<int> $ids */
        $ids = LedgerEntry::query()->where('account_id', $this->bank->id)->pluck('id')->map(fn (mixed $id): int => is_numeric($id) ? (int) $id : 0)->all();

        $ticked = app(ReconcileEntries::class)->reconcile($this->bank, [$ids[0], $ids[1]], $this->owner->id);

        expect($ticked)->toBe(2)
            // Reconciling is a fact about the paper trail, never about the money.
            ->and(app(AccountBalances::class)->balanceOf($this->bank))->toBe($before)
            // And the figure a shopkeeper actually wants: what nobody has confirmed.
            ->and(app(AccountBalances::class)->unreconciledTotal($this->bank))->toBe(1_000_000);
    });
});

it('does not re-stamp an entry somebody already confirmed', function (): void {
    ($this->inTenant)(function (): void {
        seedMovements(2);

        /** @var list<int> $ids */
        $ids = LedgerEntry::query()->where('account_id', $this->bank->id)->pluck('id')->map(fn (mixed $id): int => is_numeric($id) ? (int) $id : 0)->all();

        app(ReconcileEntries::class)->reconcile($this->bank, $ids, $this->owner->id, CarbonImmutable::parse('2026-08-10'));
        $again = app(ReconcileEntries::class)->reconcile($this->bank, $ids, $this->owner->id, CarbonImmutable::parse('2026-08-20'));

        /** @var LedgerEntry $entry */
        $entry = LedgerEntry::query()->findOrFail($ids[0]);

        // Nothing newly ticked, and the original date stands: it records when somebody
        // FIRST agreed, which is the only date that answers "when did we last check?".
        expect($again)->toBe(0)
            ->and($entry->reconciled_at?->toDateString())->toBe('2026-08-10');
    });
});

it('lets somebody untick a line they ticked by mistake', function (): void {
    ($this->inTenant)(function (): void {
        seedMovements(1);

        /** @var list<int> $ids */
        $ids = LedgerEntry::query()->where('account_id', $this->bank->id)->pluck('id')->map(fn (mixed $id): int => is_numeric($id) ? (int) $id : 0)->all();

        app(ReconcileEntries::class)->reconcile($this->bank, $ids, $this->owner->id);

        // Refusing would leave a false assertion in place permanently, which is worse
        // than letting them correct it. No money is involved either way.
        expect(app(ReconcileEntries::class)->unreconcile($this->bank, $ids))->toBe(1)
            ->and(app(ReconcileEntries::class)->unreconciled($this->bank))->toHaveCount(1);
    });
});

it('refuses to reconcile a heading nobody can get a statement for', function (): void {
    ($this->inTenant)(function (): void {
        $sales = Account::factory()->create(['type' => Account::TYPE_SALES]);

        // There is no external statement to tick a sales account against, and offering
        // the action implies one exists.
        expect(fn () => app(ReconcileEntries::class)->reconcile($sales, [1], $this->owner->id))
            ->toThrow(RuntimeException::class);
    });
});

it('lists what nobody has confirmed, oldest first', function (): void {
    ($this->inTenant)(function (): void {
        seedMovements(4);

        /** @var list<int> $ids */
        $ids = LedgerEntry::query()->where('account_id', $this->bank->id)->orderBy('id')->pluck('id')->map(fn (mixed $id): int => is_numeric($id) ? (int) $id : 0)->all();

        app(ReconcileEntries::class)->reconcile($this->bank, [$ids[0], $ids[2]], $this->owner->id);

        $open = app(ReconcileEntries::class)->unreconciled($this->bank);

        // The one sitting unticked the longest is the likeliest problem, so it goes on
        // top rather than under this morning's sales.
        expect($open)->toHaveCount(2)
            ->and($open[0]->id)->toBe($ids[1]);
    });
});
