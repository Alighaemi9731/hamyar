<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\CRM\Models\Account;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Platform\Models\Tenant;
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

        Account::query()->firstOrCreate(
            ['type' => Account::TYPE_CASH, 'name' => 'صندوق فروشگاه'],
            ['is_default' => true, 'is_active' => true, 'branch_id' => $branchId, 'opening_balance' => 50_000_000],
        );

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
}
