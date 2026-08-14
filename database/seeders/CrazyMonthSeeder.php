<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Cheques\Enums\ChequeDirection;
use App\Modules\Cheques\Models\Cheque;
use App\Modules\Cheques\Services\ChequeTransitions;
use App\Modules\CRM\Models\Account;
use App\Modules\CRM\Models\Party;
use App\Modules\CRM\Services\LedgerService;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Treasury\Enums\CashDirection;
use App\Modules\Treasury\Models\RecurringTemplate;
use App\Modules\Treasury\Models\TransactionCategory;
use App\Modules\Treasury\Services\GenerateRecurring;
use App\Modules\Treasury\Services\TransferBetweenAccounts;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

/**
 * One crazy month — the Phase 7 Definition of Done, as data.
 *
 * The DoD is that a seeded month "reconciles to the rial across all reports". That is not
 * a thing you can assemble at the end of a phase: by then there are six subsystems posting
 * into one ledger and the first discrepancy is a week of bisecting. So this file is grown
 * one slice at a time, and {@see \Tests\Feature\CrazyMonthReconcilesTest} runs after every
 * slice. A slice that breaks the month is caught the day it lands.
 *
 * ## Everything is driven through the real services
 *
 * Same discipline as {@see DemoShopSeeder}, and it matters more here. A hand-inserted
 * ledger row balances by construction — the seeder's author made it balance — so a month
 * built that way proves nothing about the code that will build next month. Every figure
 * below comes out of the same service a shopkeeper's button press would call.
 *
 * ## Dates are fixed, and the month is in the past
 *
 * `MONTH_START` is a literal, not `now()->startOfMonth()`. A seeded scenario whose numbers
 * depend on the day you run it produces a reconciliation test that passes in August and
 * fails in September, and the failure looks like a bug in the ledger. The month is in the
 * past so that due dates, overdue instalments and abandoned-device sweeps all have
 * something to be late relative to.
 *
 * ## What "crazy" means
 *
 * A quiet month reconciles by accident. This one is built to be awkward on purpose: a
 * cheque that bounces and is re-presented, a repair delivered against a part consumed
 * weeks earlier, an instalment paid late with a fee, an early settlement with a rebate, a
 * card settlement that arrives net of a PSP charge, and a trade-in. Each of those is a
 * place where a plausible implementation loses a few rial.
 */
class CrazyMonthSeeder extends Seeder
{
    /** Mordad 1405 — a real Jalali month, fixed so the arithmetic never moves. */
    public const MONTH_START = '2026-07-23';

    public const MONTH_END = '2026-08-22';

    public function run(): void
    {
        $tenant = Tenant::query()->where('slug', 'demo')->first();

        if (! $tenant instanceof Tenant) {
            return;
        }

        app(TenantContext::class)->runFor($tenant, function (): void {
            $owner = User::query()->orderBy('id')->firstOrFail();
            auth()->setUser($owner);

            $this->seedChartOfAccounts();
            $this->seedBanking();
            $this->seedOverheads();
            $this->seedCheques();
        });

        app(TenantContext::class)->forget();
    }

    /**
     * The accounts a month of trading needs somewhere to land in.
     *
     * `firstOrCreate` throughout: this seeder runs after `DemoShopSeeder`, which has
     * already made a till and a sales account, and a second cash box called «صندوق» would
     * split the month's takings across two drawers that each look half empty.
     */
    private function seedChartOfAccounts(): void
    {
        $branchId = Warehouse::query()->where('is_sellable', true)->value('branch_id');

        /*
        | Adopt the shop's existing till rather than making a second one.
        |
        | `DemoShopSeeder` has already created a default cash account, and only one per
        | tenant may be the default — a partial unique index says so. Matching on NAME
        | rather than on type would try to create «صندوق فروشگاه» beside the existing
        | «صندوق», collide on that index, and — if it had not collided — split the month's
        | takings across two drawers that each looked half empty.
        */
        $till = Account::query()->where('type', Account::TYPE_CASH)->orderByDesc('is_default')->first();

        if (! $till instanceof Account) {
            $till = Account::query()->create([
                'type' => Account::TYPE_CASH,
                'name' => 'صندوق فروشگاه',
                'is_default' => true,
                'is_active' => true,
                'branch_id' => $branchId,
            ]);
        }

        // The month needs a float in the drawer to bank from, whichever till it found.
        $till->forceFill(['opening_balance' => 50_000_000])->save();

        Account::query()->firstOrCreate(
            ['type' => Account::TYPE_BANK, 'name' => 'بانک ملت — جاری'],
            ['is_active' => true, 'bank_name' => 'ملت', 'iban' => 'IR820540102680020817909002', 'opening_balance' => 800_000_000],
        );

        Account::query()->firstOrCreate(
            ['type' => Account::TYPE_POS_TERMINAL, 'name' => 'کارتخوان بانک ملت'],
            ['is_active' => true, 'terminal_number' => '12345678', 'opening_balance' => 0],
        );

        Account::query()->firstOrCreate(
            ['type' => Account::TYPE_EXPENSE, 'name' => 'کارمزد بانکی'],
            ['is_active' => true],
        );
    }

    /**
     * Slice 1 — banking the takings, and a card settlement that arrives short.
     *
     * The PSP charge is the interesting one. It is the first event in the month where the
     * shop's total genuinely falls without anything being sold or bought, and a
     * reconciliation that quietly ignores it is out by 850,000 before the month is a week
     * old.
     */
    private function seedBanking(): void
    {
        $till = Account::query()->where('type', Account::TYPE_CASH)->firstOrFail();
        $bank = Account::query()->where('type', Account::TYPE_BANK)->firstOrFail();
        $terminal = Account::query()->where('type', Account::TYPE_POS_TERMINAL)->firstOrFail();

        $transfers = app(TransferBetweenAccounts::class);
        $start = CarbonImmutable::parse(self::MONTH_START);

        // Cash banked mid-month. No fee: the shop's own branch takes deposits free.
        $transfers->transfer(
            $till, $bank, 30_000_000,
            reference: 'واریز نقدی هفتگی',
            occurredAt: $start->addDays(6),
        );

        // The کارتخوان settles into the bank the next day, less the PSP's cut. The fee is
        // a third line, not a smaller amount — see TransferBetweenAccounts.
        $transfers->transfer(
            $terminal, $bank, 120_000_000,
            fee: 850_000,
            reference: 'تسویه کارتخوان',
            occurredAt: $start->addDays(9),
        );
    }

    /**
     * Slice 2 — the rent, which nobody remembers and which eats a month's profit.
     *
     * Booked through the recurring generator rather than inserted, so the month proves the
     * idempotency path as well as the arithmetic: the reconciliation test runs the seeder
     * once, but `make fresh` on a machine that already had data runs it against existing
     * rows, and a generator that double-booked would show up here first.
     */
    private function seedOverheads(): void
    {
        // Paid from the bank, as shops actually pay rent — and as they must here: a cash
        // box cannot go negative, and 120,000,000 of rent against a 50,000,000 drawer is
        // exactly the overdraw `TransferBetweenAccounts` refuses.
        $payFrom = Account::query()->where('type', Account::TYPE_BANK)->firstOrFail();

        $rentAccount = Account::query()->firstOrCreate(
            ['type' => Account::TYPE_EXPENSE, 'name' => 'اجاره مغازه'],
            ['is_active' => true],
        );

        $category = TransactionCategory::query()->firstOrCreate(
            ['name' => 'اجاره مغازه', 'direction' => CashDirection::Expense->value],
            ['account_id' => $rentAccount->id, 'is_active' => true],
        );

        RecurringTemplate::query()->firstOrCreate(
            ['name' => 'اجاره ماهانه مغازه'],
            [
                'transaction_category_id' => $category->id,
                'account_id' => $payFrom->id,
                'direction' => CashDirection::Expense,
                'amount' => 120_000_000,
                'day_of_month' => 1,
                'starts_on' => self::MONTH_START,
                'is_active' => true,
            ],
        );

        app(GenerateRecurring::class)->run(CarbonImmutable::parse(self::MONTH_END));
    }

    /**
     * Slice 3 — the cheque that bounces, and the one the shop spends.
     *
     * The awkward path on purpose. A month where every cheque clears reconciles by
     * accident; this one exercises the two rows most likely to be wrong — the bounce that
     * restores a debt without erasing history, and the re-presentation that credits the
     * party rather than the drawer account (spec rows R5 and R7).
     *
     * It also leaves a cheque endorsed to a supplier and still outstanding at month end,
     * which is what makes the exposure figure non-zero when the DoD checks it.
     */
    private function seedCheques(): void
    {
        $branchId = Warehouse::query()->where('is_sellable', true)->value('branch_id');
        $bank = Account::query()->where('type', Account::TYPE_BANK)->firstOrFail();
        // By TYPE, not by name — same reason as the till above. `DemoShopSeeder` already
        // made a sales account, and a second one called «فروش» would split the month's
        // revenue across two headings that each look half right on a P&L.
        $sales = $this->accountOfType(Account::TYPE_SALES, 'درآمد فروش');

        $customer = Party::query()->firstOrCreate(
            ['name' => 'حسن رضایی'],
            ['kind' => 'customer', 'is_active' => true, 'credit_limit' => 500_000_000],
        );

        $supplier = Party::query()->firstOrCreate(
            ['name' => 'پخش موبایل ایرانیان'],
            ['kind' => 'supplier', 'is_active' => true],
        );

        $ledger = app(LedgerService::class);
        $transitions = app(ChequeTransitions::class);
        $start = CarbonImmutable::parse(self::MONTH_START);

        // Two wholesale sales on credit, both settled with post-dated paper.
        foreach ([['A', 450_000_000, 2], ['B', 280_000_000, 11]] as [$tag, $amount, $dayOffset]) {
            $ledger->post([
                ['party_id' => $customer->id, 'debit' => $amount, 'description' => "فروش عمده {$tag}"],
                ['account_id' => $sales->id, 'credit' => $amount],
            ], null, $start->addDays($dayOffset));
        }

        $bouncer = Cheque::query()->firstOrCreate(
            ['direction' => ChequeDirection::Received->value, 'bank_name' => 'ملت', 'serial' => '445678'],
            [
                'branch_id' => $branchId,
                'party_id' => $customer->id,
                'amount' => 450_000_000,
                'sayad_id' => '1234567890123456',
                'due_date' => $start->addDays(20)->toDateString(),
            ],
        );

        if ($bouncer->wasRecentlyCreated) {
            $transitions->receive($bouncer, $start->addDays(2));
            $transitions->deposit($bouncer, $bank, $start->addDays(20));
            // The event the whole month is built around.
            $transitions->bounce($bouncer, 'کسر موجودی', fee: 300_000, at: $start->addDays(22));
            // And the shop chases it, successfully.
            $transitions->deposit($bouncer, $bank, $start->addDays(26));
            $transitions->clear($bouncer, at: $start->addDays(28));
        }

        // The second cheque is endorsed to a supplier instead of banked — خرج کردن چک —
        // and is still outstanding when the month closes.
        $endorsed = Cheque::query()->firstOrCreate(
            ['direction' => ChequeDirection::Received->value, 'bank_name' => 'صادرات', 'serial' => '991122'],
            [
                'branch_id' => $branchId,
                'party_id' => $customer->id,
                'amount' => 280_000_000,
                'sayad_id' => '6543210987654321',
                'due_date' => $start->addDays(75)->toDateString(),
            ],
        );

        if ($endorsed->wasRecentlyCreated) {
            $transitions->receive($endorsed, $start->addDays(11));

            // The shop owes the supplier for a shipment.
            $ledger->post([
                ['account_id' => $this->accountOfType(Account::TYPE_INVENTORY, 'ارزش موجودی انبار')->id,
                    'debit' => 300_000_000, 'description' => 'خرید عمده'],
                ['party_id' => $supplier->id, 'credit' => 300_000_000],
            ], null, $start->addDays(12));

            $transitions->endorse($endorsed, (int) $supplier->id, $start->addDays(13));
        }
    }

    /**
     * The shop's account of a given type, or a new one named as suggested.
     *
     * Matching on type rather than name is what stops this seeder building a parallel
     * chart of accounts beside the one `DemoShopSeeder` already made. Two sales accounts
     * do not break the ledger — it still balances — but every P&L then shows revenue split
     * across two headings that each look half right, which is worse than an obvious error.
     */
    private function accountOfType(string $type, string $nameIfMissing): Account
    {
        $account = Account::query()->where('type', $type)->orderBy('id')->first();

        if ($account instanceof Account) {
            return $account;
        }

        /** @var Account $created */
        $created = Account::query()->create([
            'type' => $type,
            'name' => $nameIfMissing,
            'is_active' => true,
        ]);

        return $created;
    }
}
