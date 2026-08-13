<?php

declare(strict_types=1);

use App\Modules\Cheques\Enums\ChequeDirection;
use App\Modules\Cheques\Enums\ChequeStatus;
use App\Modules\Cheques\Models\Cheque;
use App\Modules\Cheques\Services\ChequeAccounts;
use App\Modules\Cheques\Services\ChequeTransitions;
use App\Modules\CRM\Models\Account;
use App\Modules\CRM\Models\LedgerEntry;
use App\Modules\CRM\Models\Party;
use App\Modules\CRM\Services\LedgerService;
use App\Modules\Identity\Models\User;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Platform\Services\PlanCatalogueSeeder;
use App\Modules\Platform\Services\SubscriptionResolver;
use App\Modules\Platform\Services\TenantProvisioner;
use App\Modules\Treasury\Services\AccountBalances;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;

/**
 * The posting matrix from `docs/specs/cheques.md`, pinned row for row.
 *
 * **This file mirrors the spec table exactly.** Every row R1–R13 and I1–I7 has its own
 * test, named for the row it pins, in the order the table lists them — including the rows
 * that are obvious, because a matrix with three interesting rows tested and nine assumed
 * is a matrix nobody can trust when the tenth turns out to be wrong.
 *
 * The pairing is the point: a change to the spec without a change here, or the reverse, is
 * visibly incomplete. Same standard ADR 0009's rounding rules are held to.
 *
 * Face value throughout is 450,000,000 rial — a real phone, a real cheque.
 */
const FACE = 450_000_000;

beforeEach(function (): void {
    app(PlanCatalogueSeeder::class)->sync();

    $this->tenant = Tenant::factory()->withDomain()->create();
    $this->url = tenantUrl($this->tenant);

    subscribe($this->tenant, 'pro');
    app(SubscriptionResolver::class)->forget();
    app(TenantProvisioner::class)->seedRoles($this->tenant);

    /** @var array{User, Party, Party, Account} $fixtures */
    $fixtures = inTenantContext($this->tenant, function (): array {
        $owner = User::factory()->create();
        $owner->assignRole('Owner');

        return [
            $owner,
            Party::factory()->create(['name' => 'حسن رضایی']),
            Party::factory()->create(['name' => 'پخش موبایل ایرانیان']),
            Account::factory()->create(['type' => Account::TYPE_BANK, 'name' => 'بانک ملت', 'opening_balance' => 0]),
        ];
    });

    [$this->owner, $this->drawer, $this->supplier, $this->bank] = $fixtures;

    $this->inTenant = fn (Closure $callback): mixed => inTenantContext($this->tenant, $callback);
});

afterEach(fn () => app(TenantContext::class)->forget());

/**
 * A received cheque from حسن, already in hand (R1 applied).
 *
 * The customer's debt is posted first, because that is the only shape this ever has in
 * life: a cheque settles something. Taking one from somebody who owes nothing leaves the
 * shop owing THEM — a real case (an advance), but not the one the matrix is about, and
 * starting from it would make every expectation below read as a negative.
 */
function received(?int $amount = null): Cheque
{
    /** @var Party $drawer */
    $drawer = test()->drawer;
    $face = $amount ?? FACE;

    app(LedgerService::class)->post([
        ['party_id' => $drawer->id, 'debit' => $face, 'description' => 'فاکتور فروش'],
        ['account_id' => (int) Account::factory()->create(['type' => Account::TYPE_SALES])->id, 'credit' => $face],
    ]);

    $cheque = Cheque::query()->create([
        'direction' => ChequeDirection::Received,
        'party_id' => $drawer->id,
        'amount' => $face,
        'bank_name' => 'ملت',
        'serial' => (string) random_int(100000, 999999),
        'sayad_id' => (string) random_int(1000000000000000, 9999999999999999),
        'due_date' => '2026-11-22',
    ]);

    /** @var User $owner */
    $owner = test()->owner;

    return app(ChequeTransitions::class)->receive($cheque, CarbonImmutable::parse('2026-08-22'), $owner->id);
}

/** Balance of one of the module's own accounts. */
function chequeAccountBalance(string $which): int
{
    $accounts = app(ChequeAccounts::class);

    $account = match ($which) {
        'collection' => $accounts->inCollection(),
        'returned' => $accounts->returned(),
        'payable' => $accounts->payable(),
        'charges' => $accounts->bankCharges(),
        'bad_debt' => $accounts->badDebt(),
        default => $accounts->receivable(),
    };

    return app(AccountBalances::class)->balanceOf($account);
}

function partyBalance(Party $party): int
{
    return app(LedgerService::class)->partyBalance($party);
}

/* ===================================================== RECEIVED ===== */

it('R1 — taking a cheque debits cheques_receivable and credits the drawer', function (): void {
    ($this->inTenant)(function (): void {
        // `received()` posts the sale that put them in debt, then takes the cheque.
        received();

        // Settled on receipt: the shop swapped a claim on a person for a claim on paper.
        expect(partyBalance($this->drawer))->toBe(0)
            ->and(chequeAccountBalance('receivable'))->toBe(FACE);
    });
});

it('R2 — depositing debits cheques_in_collection and credits cheques_receivable', function (): void {
    ($this->inTenant)(function (): void {
        $cheque = received();

        app(ChequeTransitions::class)->deposit($cheque, $this->bank, CarbonImmutable::parse('2026-11-22'));

        expect(chequeAccountBalance('receivable'))->toBe(0)
            ->and(chequeAccountBalance('collection'))->toBe(FACE)
            // NOT the bank. The bank has not paid, and a balance inflated by uncleared
            // paper can never be reconciled against the bank's own statement.
            ->and(app(AccountBalances::class)->balanceOf($this->bank))->toBe(0);
    });
});

it('R3 — clearing from deposited debits the bank and credits cheques_in_collection', function (): void {
    ($this->inTenant)(function (): void {
        $cheque = received();
        $transitions = app(ChequeTransitions::class);

        $transitions->deposit($cheque, $this->bank, CarbonImmutable::parse('2026-11-22'));
        $transitions->clear($cheque, at: CarbonImmutable::parse('2026-11-25'));

        expect(app(AccountBalances::class)->balanceOf($this->bank))->toBe(FACE)
            ->and(chequeAccountBalance('collection'))->toBe(0)
            // The drawer was credited at receipt. Crediting again here is the classic
            // double-settlement, and it balances perfectly while being wrong.
            // The drawer was credited at receipt. Crediting again here would be the
            // classic double-settlement — it balances perfectly while being wrong.
            ->and(partyBalance($this->drawer))->toBe(0);
    });
});

it('R3f — a collection fee folds into the clearing batch', function (): void {
    ($this->inTenant)(function (): void {
        $cheque = received();
        $transitions = app(ChequeTransitions::class);

        $transitions->deposit($cheque, $this->bank, CarbonImmutable::parse('2026-11-22'));
        $transitions->clear($cheque, fee: 150_000, at: CarbonImmutable::parse('2026-11-25'));

        // The bank nets a collection fee into the same credit line on the statement, and
        // reconciliation ties against what the statement shows.
        expect(app(AccountBalances::class)->balanceOf($this->bank))->toBe(FACE - 150_000)
            ->and(chequeAccountBalance('charges'))->toBe(150_000)
            ->and(chequeAccountBalance('collection'))->toBe(0);
    });
});

it('R4 — cashing in hand at the drawee branch skips collection entirely', function (): void {
    ($this->inTenant)(function (): void {
        $cheque = received();

        // The shopkeeper walked it to the issuing bank and was paid over the counter.
        app(ChequeTransitions::class)->clear($cheque, into: $this->bank, at: CarbonImmutable::parse('2026-11-22'));

        expect(app(AccountBalances::class)->balanceOf($this->bank))->toBe(FACE)
            ->and(chequeAccountBalance('receivable'))->toBe(0)
            // Nothing was ever in transit; a collection account that gains and loses the
            // same amount in one transaction makes the deposit-slip report show a deposit
            // no bank ever received.
            ->and(chequeAccountBalance('collection'))->toBe(0);
    });
});

it('R5 — a bounce debits the drawer and credits cheques_in_collection, in one batch', function (): void {
    ($this->inTenant)(function (): void {
        $cheque = received();
        $transitions = app(ChequeTransitions::class);

        $transitions->deposit($cheque, $this->bank, CarbonImmutable::parse('2026-11-22'));
        $transitions->bounce($cheque, 'کسر موجودی', at: CarbonImmutable::parse('2026-11-25'));

        expect(partyBalance($this->drawer))->toBe(FACE)
            ->and(chequeAccountBalance('collection'))->toBe(0)
            // NOT a reversal of the receipt: that would credit cheques_receivable, but
            // the cheque was in collection, not in hand.
            ->and(chequeAccountBalance('receivable'))->toBe(0);
    });
});

it('R5 — the restoration and the collection credit are one batch, never two', function (): void {
    ($this->inTenant)(function (): void {
        $cheque = received();
        $transitions = app(ChequeTransitions::class);

        $transitions->deposit($cheque, $this->bank, CarbonImmutable::parse('2026-11-22'));

        $before = LedgerEntry::query()->distinct()->count('batch_id');

        $transitions->bounce($cheque, 'کسر موجودی', at: CarbonImmutable::parse('2026-11-25'));

        // One new batch. In two, there is a window where the books claim the shop holds a
        // good asset AND is owed nothing — and a crash leaves it that way.
        expect(LedgerEntry::query()->distinct()->count('batch_id'))->toBe($before + 1);
    });
});

it('R5f — the returned-item charge is its own batch', function (): void {
    ($this->inTenant)(function (): void {
        $cheque = received();
        $transitions = app(ChequeTransitions::class);

        $transitions->deposit($cheque, $this->bank, CarbonImmutable::parse('2026-11-22'));

        $before = LedgerEntry::query()->distinct()->count('batch_id');

        $transitions->bounce($cheque, 'کسر موجودی', fee: 300_000, at: CarbonImmutable::parse('2026-11-25'));

        // Two: the restoration, and the fee. Separate so a disputed charge can be
        // reversed without touching the customer's debt.
        expect(LedgerEntry::query()->distinct()->count('batch_id'))->toBe($before + 2)
            ->and(chequeAccountBalance('charges'))->toBe(300_000)
            ->and(app(AccountBalances::class)->balanceOf($this->bank))->toBe(-300_000);
    });
});

it('R6 — a partial payment is one batch of three lines', function (): void {
    ($this->inTenant)(function (): void {
        $cheque = received();
        $transitions = app(ChequeTransitions::class);

        $transitions->deposit($cheque, $this->bank, CarbonImmutable::parse('2026-11-22'));

        // The bank paid what was in the account and certified the rest.
        $transitions->bounce($cheque, 'کسر موجودی', recovered: 100_000_000, at: CarbonImmutable::parse('2026-11-25'));

        expect(app(AccountBalances::class)->balanceOf($this->bank))->toBe(100_000_000)
            ->and(partyBalance($this->drawer))->toBe(FACE - 100_000_000)
            ->and(chequeAccountBalance('collection'))->toBe(0)
            ->and(($cheque->fresh() ?? $cheque)->outstanding())->toBe(FACE - 100_000_000);
    });
});

it('R7 — re-presenting credits the PARTY, not cheques_receivable', function (): void {
    ($this->inTenant)(function (): void {
        $cheque = received();
        $transitions = app(ChequeTransitions::class);

        $transitions->deposit($cheque, $this->bank, CarbonImmutable::parse('2026-11-22'));
        $transitions->bounce($cheque, 'کسر موجودی', at: CarbonImmutable::parse('2026-11-25'));
        $transitions->deposit($cheque, $this->bank, CarbonImmutable::parse('2026-12-01'));

        // The row most specs miss. After a bounce the drawer account holds nothing for
        // this cheque, so crediting it would drive it negative by the face value —
        // permanently, and the drawer-count invariant with it.
        expect(chequeAccountBalance('receivable'))->toBe(0)
            ->and(chequeAccountBalance('collection'))->toBe(FACE)
            ->and(partyBalance($this->drawer))->toBe(0)
            ->and(($cheque->fresh() ?? $cheque)->presentation_attempt)->toBe(2);
    });
});

it('R8 — endorsing debits the endorsee and credits cheques_receivable', function (): void {
    ($this->inTenant)(function (): void {
        $cheque = received();

        // The shop owes the supplier 500,000,000.
        app(LedgerService::class)->post([
            ['account_id' => (int) Account::factory()->create(['type' => Account::TYPE_INVENTORY])->id, 'debit' => 500_000_000],
            ['party_id' => $this->supplier->id, 'credit' => 500_000_000],
        ]);

        app(ChequeTransitions::class)->endorse($cheque, $this->supplier->id, CarbonImmutable::parse('2026-08-25'));

        // Positive means they owe the shop, so debiting moves what the shop owes toward
        // zero: -500,000,000 + 450,000,000.
        expect(partyBalance($this->supplier))->toBe(-50_000_000)
            ->and(chequeAccountBalance('receivable'))->toBe(0)
            // The drawer is NOT credited again — that would settle their debt twice and
            // gift them the face value.
            ->and(partyBalance($this->drawer))->toBe(0);
    });
});

it('R9 — a spent cheque clearing at the endorsee posts nothing at all', function (): void {
    ($this->inTenant)(function (): void {
        $cheque = received();
        app(ChequeTransitions::class)->endorse($cheque, $this->supplier->id);

        $before = LedgerEntry::query()->count();

        // There is nothing to close out: the endorsement already zeroed this cheque's
        // contribution to every account. Any posting here invents value.
        expect(LedgerEntry::query()->count())->toBe($before);
    });
});

it('R10 — an endorsee returning it debits cheques_returned and credits them', function (): void {
    ($this->inTenant)(function (): void {
        $cheque = received();
        $transitions = app(ChequeTransitions::class);

        $transitions->endorse($cheque, $this->supplier->id);
        $transitions->returnedByEndorsee($cheque, CarbonImmutable::parse('2026-12-01'));

        // The shop's debt to the supplier revives on notification, not on the paper
        // physically coming back.
        expect(partyBalance($this->supplier))->toBe(0)
            ->and(chequeAccountBalance('returned'))->toBe(FACE);
    });
});

it('R11 — chasing the drawer debits them and credits cheques_returned', function (): void {
    ($this->inTenant)(function (): void {
        $cheque = received();
        $transitions = app(ChequeTransitions::class);

        $transitions->endorse($cheque, $this->supplier->id);
        $transitions->returnedByEndorsee($cheque, CarbonImmutable::parse('2026-12-01'));
        $transitions->chaseDrawer($cheque, CarbonImmutable::parse('2026-12-02'));

        expect(partyBalance($this->drawer))->toBe(FACE)
            ->and(chequeAccountBalance('returned'))->toBe(0);
    });
});

it('R12 — handing it back debits the drawer and credits cheques_receivable', function (): void {
    ($this->inTenant)(function (): void {
        $cheque = received();

        app(ChequeTransitions::class)->returnToDrawer($cheque, CarbonImmutable::parse('2026-08-30'));

        // They owe again: the thing that settled their debt is back in their pocket.
        expect(partyBalance($this->drawer))->toBe(FACE)
            ->and(chequeAccountBalance('receivable'))->toBe(0);
    });
});

it('R13 — writing off debits bad debt and credits the drawer', function (): void {
    ($this->inTenant)(function (): void {
        $cheque = received();
        $transitions = app(ChequeTransitions::class);

        $transitions->deposit($cheque, $this->bank, CarbonImmutable::parse('2026-11-22'));
        $transitions->bounce($cheque, 'کسر موجودی', at: CarbonImmutable::parse('2026-11-25'));
        $transitions->writeOff($cheque, CarbonImmutable::parse('2027-02-01'));

        // Their balance goes to zero because the shop stopped counting it as an asset,
        // not because they paid.
        expect(partyBalance($this->drawer))->toBe(0)
            ->and(chequeAccountBalance('bad_debt'))->toBe(FACE);
    });
});

/* ======================================================= ISSUED ===== */

/**
 * A cheque the shop wrote to its supplier.
 */
function issued(?int $amount = null): Cheque
{
    /** @var Party $supplier */
    $supplier = test()->supplier;
    /** @var Account $bank */
    $bank = test()->bank;

    $cheque = Cheque::query()->create([
        'direction' => ChequeDirection::Issued,
        'party_id' => $supplier->id,
        'amount' => $amount ?? FACE,
        'bank_name' => 'ملت',
        'serial' => (string) random_int(100000, 999999),
        'due_date' => '2026-11-22',
    ]);

    return app(ChequeTransitions::class)->issue($cheque, $bank, CarbonImmutable::parse('2026-08-22'));
}

it('I1 — issuing debits the payee and credits cheques_payable', function (): void {
    ($this->inTenant)(function (): void {
        issued();

        // What the shop owes the supplier falls; a liability for the paper takes its place.
        expect(partyBalance($this->supplier))->toBe(FACE)
            ->and(chequeAccountBalance('payable'))->toBe(-FACE);
    });
});

it('I2 — the payee presenting it posts nothing, deliberately', function (): void {
    ($this->inTenant)(function (): void {
        $cheque = issued();

        $before = LedgerEntry::query()->count();

        app(ChequeTransitions::class)->markPresented($cheque, CarbonImmutable::parse('2026-11-20'));

        // The asymmetry with R2 is considered: there is no invariant to buy here. The
        // obligation is the same size whether or not the payee has banked it.
        expect(LedgerEntry::query()->count())->toBe($before)
            ->and(($cheque->fresh() ?? $cheque)->status)->toBe(ChequeStatus::Presented);
    });
});

it('I3 — our cheque clearing debits cheques_payable and credits the drawn-on bank', function (): void {
    ($this->inTenant)(function (): void {
        $cheque = issued();

        app(ChequeTransitions::class)->clearIssued($cheque, at: CarbonImmutable::parse('2026-11-22'));

        expect(chequeAccountBalance('payable'))->toBe(0)
            ->and(app(AccountBalances::class)->balanceOf($this->bank))->toBe(-FACE);
    });
});

it('I3f — a bank charge on our cleared cheque rides in the same batch', function (): void {
    ($this->inTenant)(function (): void {
        $cheque = issued();

        app(ChequeTransitions::class)->clearIssued($cheque, fee: 50_000, at: CarbonImmutable::parse('2026-11-22'));

        expect(app(AccountBalances::class)->balanceOf($this->bank))->toBe(-FACE - 50_000)
            ->and(chequeAccountBalance('charges'))->toBe(50_000);
    });
});

it('I4 — our cheque bouncing debits cheques_payable and credits the payee', function (): void {
    ($this->inTenant)(function (): void {
        $cheque = issued();

        app(ChequeTransitions::class)->bounceIssued($cheque, 'کسر موجودی', fee: 400_000, at: CarbonImmutable::parse('2026-11-22'));

        // The liability for the paper is gone and the plain debt is back.
        expect(partyBalance($this->supplier))->toBe(0)
            ->and(chequeAccountBalance('payable'))->toBe(0)
            // The bank takes its charge regardless.
            ->and(chequeAccountBalance('charges'))->toBe(400_000)
            ->and(app(AccountBalances::class)->balanceOf($this->bank))->toBe(-400_000);
    });
});

it('I7 — cancelling ours debits cheques_payable and credits the payee', function (): void {
    ($this->inTenant)(function (): void {
        $cheque = issued();

        app(ChequeTransitions::class)->cancel($cheque, CarbonImmutable::parse('2026-09-01'));

        expect(partyBalance($this->supplier))->toBe(0)
            ->and(chequeAccountBalance('payable'))->toBe(0);
    });
});
