<?php

declare(strict_types=1);

use App\Modules\Cheques\Models\Cheque;
use App\Modules\CRM\Models\Account;
use App\Modules\CRM\Models\Party;
use App\Modules\CRM\Services\LedgerService;
use App\Modules\Identity\Models\User;
use App\Modules\Installments\Models\InstallmentCollection;
use App\Modules\Installments\Models\InstallmentPlan;
use App\Modules\Installments\Models\InstallmentRow;
use App\Modules\Inventory\Models\Branch;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Platform\Services\PlanCatalogueSeeder;
use App\Modules\Platform\Services\SubscriptionResolver;
use App\Modules\Platform\Services\TenantProvisioner;
use App\Modules\Reporting\Services\FinancialReports;
use App\Support\Jalali;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;

/**
 * Aging, the cheque calendar and the instalment book.
 *
 * ## Every amount in this fixture is deliberately un-round
 *
 * `docs/testing.md` — "money fixtures use non-round amounts by default". Aging divides
 * nothing, but it *allocates*: FIFO splits a payment across debts, and a fixture where every
 * payment happens to settle a debt exactly never exercises the partial lot — which is the
 * only interesting branch in the whole query. So the payment here (7,430,000) lands in the
 * middle of the second invoice on purpose.
 *
 * ## The debts, and where FIFO puts them
 *
 * «مشتری بدهکار» owes three invoices and has paid once:
 *
 * | when          | what           | amount      | age at as-of |
 * |---------------|----------------|-------------|--------------|
 * | 120 days ago  | invoice        |  4,120,000  | «۹۰+»        |
 * | 45 days ago   | invoice        |  6,350,000  | «۳۱–۶۰»      |
 * | 10 days ago   | invoice        |  2,870,000  | «جاری»       |
 * | 5 days ago    | payment        | −7,430,000  |              |
 *
 * Total owed 13,340,000 − 7,430,000 = **5,910,000**.
 *
 * FIFO settles the oldest first: the 120-day invoice goes entirely, and 3,310,000 of the
 * 45-day one. What is left is 3,040,000 of the 45-day invoice and all 2,870,000 of the
 * 10-day one — so the report reads **0 in «۹۰+»**, 3,040,000 in «۳۱–۶۰» and 2,870,000 in
 * «جاری».
 *
 * That is the assertion this file exists for. A "bucket the debits, ignore the credits"
 * implementation reports 4,120,000 sitting in «۹۰+» and sends somebody to chase a debt that
 * was settled a week ago; a "whole balance in the oldest bucket" implementation reports the
 * entire 5,910,000 as more than ninety days old. Both are green against a fixture where the
 * payment settles an invoice exactly.
 */
beforeEach(function (): void {
    app(PlanCatalogueSeeder::class)->sync();

    $this->tenant = Tenant::factory()->withDomain()->create();
    $this->url = tenantUrl($this->tenant);

    subscribe($this->tenant, 'pro');
    app(SubscriptionResolver::class)->forget();
    app(TenantProvisioner::class)->seedRoles($this->tenant);

    $this->now = CarbonImmutable::now();

    /** @var array{User, User, User, Party} $fixtures */
    $fixtures = app(TenantContext::class)->runFor($this->tenant, function (): array {
        $owner = User::factory()->create(['name' => 'مالک']);
        $owner->assignRole('Owner');

        $cashier = User::factory()->create(['name' => 'صندوق‌دار']);
        $cashier->assignRole('Cashier');

        $technician = User::factory()->create();
        $technician->assignRole('Technician');

        $ledger = app(LedgerService::class);

        $debtor = Party::factory()->create(['name' => 'مشتری بدهکار', 'kind' => 'customer']);
        $prepaid = Party::factory()->create(['name' => 'مشتری پیش‌پرداخت', 'kind' => 'customer']);
        $supplier = Party::factory()->create(['name' => 'تأمین‌کننده', 'kind' => 'supplier']);

        /*
        | The other side of every posting is an ACCOUNT, not a party — which is both what
        | a real sale does (debit the customer, credit the sales account) and what keeps
        | this fixture readable. A party as the counter-side would accumulate the mirror of
        | every debt in the file and appear in the aging report as the shop's largest
        | debtor, burying the rows under test in an artefact of the test.
        */
        $sales = Account::factory()->create(['type' => Account::TYPE_SALES]);

        $post = function (int $partyId, int $debit, int $credit, int $daysAgo) use ($ledger, $sales): void {
            $ledger->post(
                [
                    ['party_id' => $partyId, 'debit' => $debit, 'credit' => $credit],
                    ['account_id' => $sales->id, 'debit' => $credit, 'credit' => $debit],
                ],
                occurredAt: CarbonImmutable::now()->subDays($daysAgo),
            );
        };

        $post($debtor->id, 4_120_000, 0, 120);
        $post($debtor->id, 6_350_000, 0, 45);
        $post($debtor->id, 2_870_000, 0, 10);
        $post($debtor->id, 0, 7_430_000, 5);

        // Paid the shop more than they owed: 1,250,000 of unapplied credit and no debt.
        $post($prepaid->id, 3_000_000, 0, 30);
        $post($prepaid->id, 0, 4_250_000, 20);

        // The shop owes this one — a payable, invisible to the receivable cut.
        $post($supplier->id, 0, 9_870_000, 70);

        /* ------------------------------------------------------- cheques -- */

        $branch = Branch::query()->first() ?? Branch::factory()->create();

        $cheque = function (string $direction, string $status, int $amount, int $inDays) use ($branch, $debtor): void {
            Cheque::query()->create([
                'branch_id' => $branch->id,
                'direction' => $direction,
                'status' => $status,
                'party_id' => $debtor->id,
                'amount' => $amount,
                'bank_name' => 'ملت',
                'serial' => (string) random_int(100000, 999999),
                'due_date' => CarbonImmutable::now()->addDays($inDays)->toDateString(),
            ]);
        };

        // Two open cheques due in nine days, one in and one out — the net on that day is
        // what a shop plans around.
        $cheque('received', 'in_hand', 8_640_000, 9);
        $cheque('issued', 'in_hand', 3_180_000, 9);

        // Cleared: reported, but out of the net. Counting it would tell the shop it has
        // cash coming that it already banked.
        $cheque('received', 'cleared', 5_000_000, 12);

        // Overdue and still open. It has no future date to sit inside the range.
        $cheque('received', 'bounced', 2_460_000, -20);

        /* --------------------------------------------------- instalments -- */

        $cash = Account::query()->where('type', Account::TYPE_CASH)->first()
            ?? Account::factory()->create(['type' => Account::TYPE_CASH, 'is_default' => true]);

        $plan = InstallmentPlan::query()->create([
            'branch_id' => $branch->id,
            'party_id' => $debtor->id,
            'number' => 'INS-1405-0001',
            'principal' => 12_000_000,
            'total_payable' => 13_500_000,
            'installment_count' => 3,
            'first_due_at' => CarbonImmutable::now()->subDays(20),
            'status' => 'active',
        ]);

        $rows = [];

        foreach ([[1, -20, 4_500_000], [2, 5, 4_500_000], [3, 35, 4_500_000]] as [$sequence, $offset, $amount]) {
            $rows[] = InstallmentRow::query()->create([
                'installment_plan_id' => $plan->id,
                'sequence' => $sequence,
                'due_at' => CarbonImmutable::now()->addDays($offset),
                'amount' => $amount,
                'status' => 'pending',
            ]);
        }

        // The first instalment was part-paid — 1,730,000 of 4,500,000 — and the customer
        // handed over 200,000 more than that, which sits on their account as credit rather
        // than on this row. `collected` must read 1,730,000, not 1,930,000.
        InstallmentCollection::query()->create([
            'branch_id' => $branch->id,
            'installment_row_id' => $rows[0]->id,
            'installment_plan_id' => $plan->id,
            'account_id' => $cash->id,
            'amount' => 1_930_000,
            'unapplied' => 200_000,
            'principal_part' => 1_730_000,
            'method' => 'cash',
            'occurred_at' => CarbonImmutable::now()->subDays(18),
        ]);

        return [$owner, $cashier, $technician, $debtor];
    });

    [$this->owner, $this->cashier, $this->technician, $this->debtor] = $fixtures;
});

afterEach(fn () => app(TenantContext::class)->forget());

/* --------------------------------------------------------------- aging -- */

it('ages a debt by what is still owed on it, not by when the party first owed anything', function (): void {
    $this->actingAs($this->owner)
        ->get($this->url.'/reporting/financial?cut=aging')
        ->assertOk()
        ->assertInertia(function ($page): void {
            $row = agingRowFor($page, 'مشتری بدهکار');

            expect($row)->not->toBe([]);

            // The payment settled the 120-day invoice entirely and part of the 45-day one.
            expect(rialOf($row['total']))->toBe(5_910_000)
                ->and(rialOf($row['older']))->toBe(0)
                ->and(rialOf($row['days_60']))->toBe(3_040_000)
                ->and(rialOf($row['current']))->toBe(2_870_000)
                ->and(rialOf($row['days_90']))->toBe(0);
        });
});

it('keeps unapplied credit out of the buckets and in its own column', function (): void {
    $this->actingAs($this->owner)
        ->get($this->url.'/reporting/financial?cut=aging')
        ->assertOk()
        ->assertInertia(function ($page): void {
            $row = agingRowFor($page, 'مشتری پیش‌پرداخت');

            // Owes nothing and is owed 1,250,000. Netting it into a bucket would make a
            // customer in credit look like a customer who has paid an old debt.
            expect(rialOf($row['total']))->toBe(0)
                ->and(rialOf($row['credit']))->toBe(1_250_000);
        });
});

it('reconciles to the sum of every party balance', function (): void {
    /*
    | The conservation claim, and the reason this report can be trusted at all:
    |
    |     Σ outstanding − Σ unapplied credit = Σ partyBalance()
    |
    | It holds by construction — both sides are `obligations − settlements` over the same
    | rows — which is exactly why it is worth asserting: it catches the day somebody adds a
    | filter to one side and not the other. `docs/testing.md` prefers an invariant to a
    | hand-maintained figure precisely here, because this one never needs editing when the
    | fixture grows.
    */
    app(TenantContext::class)->runFor($this->tenant, function (): void {
        $reports = app(FinancialReports::class);
        $ledger = app(LedgerService::class);

        $outstanding = 0;
        $credit = 0;

        foreach ($reports->aging(CarbonImmutable::now()) as $row) {
            $outstanding += $row['total'];
            $credit += $row['credit'];
        }

        $balances = 0;

        foreach (Party::query()->get() as $party) {
            $balances += $ledger->partyBalance($party);
        }

        // The fixture must contain the subject, or this passes on an empty world.
        expect($outstanding)->toBeGreaterThan(0);
        expect($credit)->toBeGreaterThan(0);

        expect($outstanding - $credit)->toBe($balances);
    });
});

it('shows the shop its own debts under the payable direction', function (): void {
    $this->actingAs($this->owner)
        ->get($this->url.'/reporting/financial?'.http_build_query(['cut' => 'aging', 'direction' => 'payable']))
        ->assertOk()
        ->assertInertia(function ($page): void {
            $supplier = agingRowFor($page, 'تأمین‌کننده');

            // 70 days old: «۶۱ تا ۹۰».
            expect(rialOf($supplier['total']))->toBe(9_870_000)
                ->and(rialOf($supplier['days_90']))->toBe(9_870_000);

            /*
            | The customer who owes the shop appears here too — owing **nothing**, with
            | their 5,910,000 mirrored into the credit column. That is not a leak between
            | the two cuts, it is the same ledger read from the other side: under «payable»
            | their debits are settlements, and settlements exceeding obligations is the
            | definition of unapplied credit.
            |
            | It is also load-bearing. The conservation claim above holds only because every
            | party appears in both directions; dropping the ones that owe nothing would
            | make the payable cut stop summing to the ledger, and it would stop silently.
            */
            $debtor = agingRowFor($page, 'مشتری بدهکار');

            expect(rialOf($debtor['total']))->toBe(0)
                ->and(rialOf($debtor['credit']))->toBe(5_910_000);
        });
});

it('counts an opening balance as the oldest debt rather than ignoring it', function (): void {
    /*
    | A shop migrating from paper carries a figure in, and `partyBalance()` includes it.
    | A report that did not would disagree with the party's own screen — the one thing a
    | report about balances may not do.
    */
    $party = app(TenantContext::class)->runFor($this->tenant, fn (): Party => Party::factory()->create([
        'name' => 'دفتر قدیمی',
        'kind' => 'customer',
        'opening_balance' => 6_540_000,
    ]));

    $this->actingAs($this->owner)
        ->get($this->url.'/reporting/financial?cut=aging')
        ->assertOk()
        ->assertInertia(function ($page) use ($party): void {
            $row = agingRowFor($page, 'دفتر قدیمی');

            expect($row['party_id'])->toBe($party->id)
                ->and(rialOf($row['total']))->toBe(6_540_000);
        });
});

/* ------------------------------------------------------------- cheques -- */

it('nets a day of cheques and leaves the cleared ones out of the net', function (): void {
    $from = Jalali::format($this->now, 'Y/m/d', persianDigits: false);
    $to = Jalali::format($this->now->addDays(30), 'Y/m/d', persianDigits: false);

    $this->actingAs($this->owner)
        ->get($this->url.'/reporting/financial?'.http_build_query(['cut' => 'cheques', 'from' => $from, 'to' => $to]))
        ->assertOk()
        ->assertInertia(function ($page): void {
            $rows = rowsOf($page);

            expect($rows)->not->toBe([]);

            $day = [];

            foreach ($rows as $row) {
                if (($row['incoming_count'] ?? 0) === 1 && ($row['outgoing_count'] ?? 0) === 1) {
                    $day = $row;
                }
            }

            expect($day)->not->toBe([]);
            expect(rialOf($day['incoming']))->toBe(8_640_000)
                ->and(rialOf($day['outgoing']))->toBe(3_180_000)
                ->and(rialOf($day['net']))->toBe(5_460_000);

            // The cleared cheque is reported on its own day and contributes nothing to the
            // period's net — that money already arrived.
            expect(rialOf(totalsOf($page)['incoming'] ?? null))->toBe(8_640_000);
        });
});

it('surfaces overdue cheques even though they fall outside the range', function (): void {
    $from = Jalali::format($this->now, 'Y/m/d', persianDigits: false);
    $to = Jalali::format($this->now->addDays(30), 'Y/m/d', persianDigits: false);

    $this->actingAs($this->owner)
        ->get($this->url.'/reporting/financial?'.http_build_query(['cut' => 'cheques', 'from' => $from, 'to' => $to]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            // Bounced 20 days ago and still open. Showing it only when somebody scrolls the
            // range back over it is how it gets forgotten.
            ->where('overdue.incoming.value', 2_460_000)
            ->where('overdue.incoming_count', 1)
            ->etc()
        );
});

/* --------------------------------------------------------- instalments -- */

it('reads what was collected against a row without the overpayment that went elsewhere', function (): void {
    $from = Jalali::format($this->now->subDays(30), 'Y/m/d', persianDigits: false);
    $to = Jalali::format($this->now->addDays(60), 'Y/m/d', persianDigits: false);

    $this->actingAs($this->owner)
        ->get($this->url.'/reporting/financial?'.http_build_query(['cut' => 'installments', 'from' => $from, 'to' => $to]))
        ->assertOk()
        ->assertInertia(function ($page): void {
            $rows = rowsOf($page);

            expect($rows)->toHaveCount(3);

            $first = $rows[0];

            // 1,930,000 was handed over, 200,000 of it left on the party as credit.
            expect($first['sequence'])->toBe(1)
                ->and(rialOf($first['collected']))->toBe(1_730_000)
                ->and(rialOf($first['outstanding']))->toBe(2_770_000)
                ->and($first['overdue_days'])->toBeGreaterThan(0);

            // The instalment due in five days is not overdue.
            expect($rows[1]['overdue_days'])->toBe(0);

            $totals = totalsOf($page);

            expect(rialOf($totals['due']))->toBe(13_500_000)
                ->and(rialOf($totals['collected']))->toBe(1_730_000)
                ->and(rialOf($totals['outstanding']))->toBe(11_770_000)
                ->and(rialOf($totals['overdue']))->toBe(2_770_000)
                ->and($totals['overdue_count'])->toBe(1);
        });
});

/* ------------------------------------------------------------ boundary -- */

it('gives a cashier the cuts they hold and not the ones they do not', function (): void {
    /*
    | A Cashier holds `crm.view_balance`, `cheques.view` and `installments.view` — all three
    | cuts. A Technician holds none of them and no `reporting.view` either.
    */
    $this->actingAs($this->cashier)
        ->get($this->url.'/reporting/financial?cut=aging')
        ->assertOk()
        ->assertInertia(function ($page): void {
            /** @var array<int, array{key: string}> $cuts */
            $cuts = $page->toArray()['props']['cuts'];

            expect(array_column($cuts, 'key'))->toBe(['aging', 'cheques', 'installments']);
        });

    $this->actingAs($this->technician)
        ->get($this->url.'/reporting/financial?cut=aging')
        ->assertForbidden();
});

it('refuses a cut rather than quietly showing a different one', function (): void {
    /*
    | A Salesperson holds `installments.view` (they write the contract at the counter) but
    | not `crm.view_balance` — and no `reporting.view`, so the door is shut first. The
    | assertion that matters is the shape: the screen says no to the report that was asked
    | for, instead of rendering another one under the requested heading.
    */
    $seller = app(TenantContext::class)->runFor($this->tenant, function (): User {
        $user = User::factory()->create();
        $user->assignRole('Salesperson');

        return $user;
    });

    $this->actingAs($seller)
        ->get($this->url.'/reporting/financial?cut=installments')
        ->assertForbidden();
});

it('lists the three financial rows on the index for the owner', function (): void {
    $this->actingAs($this->owner)
        ->get($this->url.'/reporting')
        ->assertOk()
        ->assertInertia(function ($page): void {
            expect(reportKeys($page, 'financial'))
                ->toBe(['financial.aging', 'financial.cheques', 'financial.installments']);
        });
});

it('downloads a workbook for each cut', function (): void {
    foreach (['aging', 'cheques', 'installments'] as $cut) {
        $response = $this->actingAs($this->owner)
            ->get($this->url.'/reporting/financial/export?cut='.$cut);

        $response->assertOk();

        expect($response->headers->get('content-disposition'))->toContain('.xlsx');
    }
});

/* ----------------------------------------------------------- isolation -- */

it('shows a shop its own debtors and not the shop next door', function (): void {
    $other = Tenant::factory()->withDomain()->create();
    subscribe($other, 'pro');
    app(SubscriptionResolver::class)->forget();
    app(TenantProvisioner::class)->seedRoles($other);

    $neighbour = app(TenantContext::class)->runFor($other, function (): User {
        $user = User::factory()->create();
        $user->assignRole('Owner');

        return $user;
    });

    // The positive half first: this tenant's own report DOES have rows, so the emptiness
    // below is a statement about isolation rather than about an empty world.
    $this->actingAs($this->owner)
        ->get($this->url.'/reporting/financial?cut=aging')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('totals.total.value', 5_910_000)->etc());

    $this->actingAs($neighbour)
        ->get(tenantUrl($other).'/reporting/financial?cut=aging')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('rows', [])
            ->where('totals.total.value', 0)
            ->etc()
        );

    $this->actingAs($neighbour)
        ->get(tenantUrl($other).'/reporting/financial?cut=cheques')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('rows', [])->etc());

    $this->actingAs($neighbour)
        ->get(tenantUrl($other).'/reporting/financial?cut=installments')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('rows', [])->etc());
})->group('isolation');

/**
 * One aging row by party name, or `[]` when the report does not list that party.
 *
 * @return array<string, mixed>
 */
function agingRowFor(object $page, string $name): array
{
    foreach (rowsOf($page) as $row) {
        if (($row['name'] ?? null) === $name) {
            return $row;
        }
    }

    return [];
}
