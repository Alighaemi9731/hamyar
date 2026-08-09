<?php

declare(strict_types=1);

use App\Modules\CRM\Models\Account;
use App\Modules\CRM\Models\LedgerEntry;
use App\Modules\CRM\Models\Party;
use App\Modules\CRM\Models\PartyContact;
use App\Modules\CRM\Services\LedgerService;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Platform\Services\PlanCatalogueSeeder;
use App\Modules\Platform\Services\TenantProvisioner;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

function partyId(Party $party): int
{
    /** @var int $id */
    $id = $party->getKey();

    return $id;
}

function accountId(Account $account): int
{
    /** @var int $id */
    $id = $account->getKey();

    return $id;
}

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->withDomain()->create();
    $this->ledger = app(LedgerService::class);
});

afterEach(fn () => app(TenantContext::class)->forget());

/* --------------------------------------------------------------- parties -- */

it('gives a new shop a default cash account', function (): void {
    app(PlanCatalogueSeeder::class)->sync();

    $tenant = app(TenantProvisioner::class)->provision([
        'name' => 'موبایل پارس',
        'subdomain' => 'pars-mobile',
        'owner_name' => 'نیما',
        'owner_mobile' => '09127778899',
        'owner_email' => null,
        'password' => 'secret-secret-1',
    ]);

    // Every sale needs somewhere to post the money side, so it must exist before the
    // first transaction rather than after someone finds a settings screen.
    app(TenantContext::class)->runFor($tenant, function (): void {
        $account = Account::query()->where('is_default', true)->firstOrFail();

        expect($account->type)->toBe(Account::TYPE_CASH);
    });
});

it('normalises a phone number so the counter can find it', function (): void {
    app(TenantContext::class)->runFor($this->tenant, function (): void {
        $party = Party::factory()->create();

        PartyContact::query()->create([
            'party_id' => partyId($party),
            'type' => PartyContact::TYPE_MOBILE,
            // Persian digits with separators, as pasted from a message.
            'value' => '۰۹۱۲-۱۱۱-۲۲۳۳',
            'is_primary' => true,
        ]);

        expect($party->fresh()?->primaryMobile())->toBe('09121112233');
    });
});

it('searches parties by name, company or contact number', function (): void {
    app(TenantContext::class)->runFor($this->tenant, function (): void {
        $party = Party::factory()->create(['name' => 'رضا تهرانی']);
        PartyContact::query()->create([
            'party_id' => partyId($party),
            'type' => PartyContact::TYPE_MOBILE,
            'value' => '09351234567',
            'is_primary' => true,
        ]);

        Party::factory()->create(['name' => 'کس دیگر']);

        // The counter does not know which field the customer will give them.
        expect(Party::query()->search('تهرانی')->count())->toBe(1);
        expect(Party::query()->search('09351234567')->count())->toBe(1);
        expect(Party::query()->search('')->count())->toBe(2);
    });
});

it('refuses two parties with the same national id', function (): void {
    app(TenantContext::class)->runFor($this->tenant, function (): void {
        Party::factory()->create(['national_id' => '0012345678']);

        // Almost always the same human entered twice, which splits their balance in half
        // and makes both statements wrong.
        expect(fn () => DB::transaction(fn () => Party::factory()->create(['national_id' => '0012345678'])))
            ->toThrow(QueryException::class);
    });
});

it('allows many parties with no national id', function (): void {
    app(TenantContext::class)->runFor($this->tenant, function (): void {
        // Most walk-in customers never give one; a plain unique would collide on NULLs.
        Party::factory()->count(3)->create();

        expect(Party::query()->count())->toBe(3);
    });
});

/* ---------------------------------------------------------------- ledger -- */

it('requires a posting to balance', function (): void {
    app(TenantContext::class)->runFor($this->tenant, function (): void {
        $party = Party::factory()->create();
        $account = Account::factory()->create();

        // One side without the other is how money appears from nowhere.
        expect(fn () => $this->ledger->post([
            ['account_id' => accountId($account), 'debit' => 5_000_000],
            ['party_id' => partyId($party), 'credit' => 4_000_000],
        ]))->toThrow(InvalidArgumentException::class, 'does not balance');
    });
});

it('refuses a single-line posting', function (): void {
    app(TenantContext::class)->runFor($this->tenant, function (): void {
        $account = Account::factory()->create();

        expect(fn () => $this->ledger->post([
            ['account_id' => accountId($account), 'debit' => 1_000],
        ]))->toThrow(InvalidArgumentException::class, 'at least two lines');
    });
});

it('refuses a row with both debit and credit', function (): void {
    app(TenantContext::class)->runFor($this->tenant, function (): void {
        $account = Account::factory()->create();

        // Two events wearing one row; it makes a running balance unreadable. Enforced by
        // a CHECK, not only by the service.
        expect(fn () => DB::transaction(fn () => LedgerEntry::query()->create([
            'account_id' => accountId($account),
            'debit' => 100,
            'credit' => 100,
            'batch_id' => (string) Str::uuid(),
            'occurred_at' => now(),
        ])))->toThrow(QueryException::class);
    });
});

it('refuses an entry that touches neither party nor account', function (): void {
    app(TenantContext::class)->runFor($this->tenant, function (): void {
        // Invisible money: it cannot appear on any statement.
        expect(fn () => DB::transaction(fn () => LedgerEntry::query()->create([
            'debit' => 100,
            'batch_id' => (string) Str::uuid(),
            'occurred_at' => now(),
        ])))->toThrow(QueryException::class);
    });
});

it('computes a party balance as a sum, including the opening figure', function (): void {
    app(TenantContext::class)->runFor($this->tenant, function (): void {
        $party = Party::factory()->openingBalance(1_000_000)->create();
        $account = Account::factory()->create();

        // Sold them goods on credit: they owe more.
        $this->ledger->post([
            ['party_id' => partyId($party), 'debit' => 5_000_000],
            ['account_id' => accountId($account), 'credit' => 5_000_000],
        ]);

        // They paid some of it.
        $this->ledger->post([
            ['account_id' => accountId($account), 'debit' => 2_000_000],
            ['party_id' => partyId($party), 'credit' => 2_000_000],
        ]);

        // A shop migrating from paper carries a figure in; the balance must start there.
        expect($this->ledger->partyBalance($party))->toBe(4_000_000);
    });
});

it('computes an account balance the same way', function (): void {
    app(TenantContext::class)->runFor($this->tenant, function (): void {
        $party = Party::factory()->create();
        $account = Account::factory()->create(['opening_balance' => 500_000]);

        $this->ledger->post([
            ['account_id' => accountId($account), 'debit' => 3_000_000],
            ['party_id' => partyId($party), 'credit' => 3_000_000],
        ]);

        expect($this->ledger->accountBalance($account))->toBe(3_500_000);
    });
});

it('makes the statement closing figure equal the balance', function (): void {
    app(TenantContext::class)->runFor($this->tenant, function (): void {
        $party = Party::factory()->openingBalance(750_000)->create();
        $account = Account::factory()->create();

        foreach ([1_000_000, 2_500_000, 400_000] as $amount) {
            $this->ledger->post([
                ['party_id' => partyId($party), 'debit' => $amount],
                ['account_id' => accountId($account), 'credit' => $amount],
            ]);
        }

        $statement = $this->ledger->statement($party);

        // The spec asserts this explicitly: a statement whose total disagrees with the
        // balance shown elsewhere is worse than no statement.
        expect($statement['opening'])->toBe(750_000);
        expect($statement['closing'])->toBe($this->ledger->partyBalance($party));
        expect($statement['rows'])->toHaveCount(3);
        expect($statement['rows'][2]['balance'])->toBe($statement['closing']);
    });
});

it('folds earlier entries into the opening figure of a windowed statement', function (): void {
    app(TenantContext::class)->runFor($this->tenant, function (): void {
        $party = Party::factory()->create();
        $account = Account::factory()->create();

        $this->ledger->post(
            [['party_id' => partyId($party), 'debit' => 1_000_000], ['account_id' => accountId($account), 'credit' => 1_000_000]],
            occurredAt: now()->subMonths(2)->toImmutable()
        );

        $this->ledger->post(
            [['party_id' => partyId($party), 'debit' => 500_000], ['account_id' => accountId($account), 'credit' => 500_000]],
            occurredAt: now()->subDay()->toImmutable()
        );

        $statement = $this->ledger->statement($party, now()->subWeek()->toImmutable());

        // The first row of a filtered statement must continue from a real number, not
        // from zero.
        expect($statement['opening'])->toBe(1_000_000);
        expect($statement['rows'])->toHaveCount(1);
        expect($statement['closing'])->toBe(1_500_000);
    });
});

it('reverses a batch by writing its mirror image, never deleting', function (): void {
    app(TenantContext::class)->runFor($this->tenant, function (): void {
        $party = Party::factory()->create();
        $account = Account::factory()->create();

        $entries = $this->ledger->post([
            ['party_id' => partyId($party), 'debit' => 2_000_000],
            ['account_id' => accountId($account), 'credit' => 2_000_000],
        ]);

        $this->ledger->reverse($entries[0]->batch_id, 'ابطال فاکتور');

        // Balance back to zero, but all four rows still on the statement — the
        // difference between a ledger and a spreadsheet.
        expect($this->ledger->partyBalance($party))->toBe(0);
        expect(LedgerEntry::query()->count())->toBe(4);
    });
});

it('groups the lines of one event under a single batch', function (): void {
    app(TenantContext::class)->runFor($this->tenant, function (): void {
        $party = Party::factory()->create();
        $account = Account::factory()->create();

        $entries = $this->ledger->post([
            ['party_id' => partyId($party), 'debit' => 1_000],
            ['account_id' => accountId($account), 'credit' => 1_000],
        ]);

        expect($entries[0]->batch_id)->toBe($entries[1]->batch_id);
    });
});

/* ---------------------------------------------------------- credit limit -- */

it('warns rather than blocks when a party would exceed its credit limit', function (): void {
    app(TenantContext::class)->runFor($this->tenant, function (): void {
        $party = Party::factory()->withCreditLimit(10_000_000)->create();
        $account = Account::factory()->create();

        $this->ledger->post([
            ['party_id' => partyId($party), 'debit' => 8_000_000],
            ['account_id' => accountId($account), 'credit' => 8_000_000],
        ]);

        $check = $this->ledger->creditCheck($party, 5_000_000);

        // A warning with an override, not a hard block: refusing a regular customer over
        // a limit nobody remembers setting loses the shop money.
        expect($check['exceeds'])->toBeTrue();
        expect($check['balance'])->toBe(8_000_000);
        expect($check['after'])->toBe(13_000_000);
    });
});

it('never flags a party with no credit limit set', function (): void {
    app(TenantContext::class)->runFor($this->tenant, function (): void {
        $party = Party::factory()->create();

        // Null means "nobody has decided", which is not the same as zero credit.
        expect($this->ledger->creditCheck($party, 999_000_000)['exceeds'])->toBeFalse();
    });
});

/* ------------------------------------------------------------- isolation -- */

it('does not leak parties across tenants', function (): void {
    pest()->group('isolation');

    $other = Tenant::factory()->withDomain()->create();

    app(TenantContext::class)->runFor($this->tenant, fn () => Party::factory()->count(3)->create());
    app(TenantContext::class)->runFor($other, fn () => Party::factory()->create());

    app(TenantContext::class)->runFor($other, fn () => expect(Party::query()->count())->toBe(1));
});

it('does not leak ledger entries across tenants', function (): void {
    pest()->group('isolation');

    $other = Tenant::factory()->withDomain()->create();

    app(TenantContext::class)->runFor($this->tenant, function (): void {
        $party = Party::factory()->create();
        $account = Account::factory()->create();

        $this->ledger->post([
            ['party_id' => partyId($party), 'debit' => 1_000_000],
            ['account_id' => accountId($account), 'credit' => 1_000_000],
        ]);
    });

    // Who owes a competitor how much is about as sensitive as data gets.
    app(TenantContext::class)->runFor($other, fn () => expect(LedgerEntry::query()->count())->toBe(0));
});
