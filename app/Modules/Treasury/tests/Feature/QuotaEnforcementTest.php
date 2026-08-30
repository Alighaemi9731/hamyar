<?php

declare(strict_types=1);

use App\Modules\CRM\Models\Account;
use App\Modules\CRM\Models\LedgerEntry;
use App\Modules\Identity\Models\User;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Platform\Services\SubscriptionResolver;
use App\Modules\Platform\Services\TenantProvisioner;
use App\Modules\Treasury\Models\AccountTransfer;
use App\Support\Tenancy\TenantContext;

/**
 * The meter on «انتقال بین حساب‌ها», at the place a shopkeeper actually meets it.
 *
 * ## Why this file exists separately from the guard's own suite
 *
 * `Platform/tests/Feature/Quota/*` proves the guard is correct — it counts, it refuses at
 * the ceiling, it is atomic under concurrency — and every one of those tests meters a
 * synthetic `quota.widgets` metric on purpose, so that the guard's suite breaks when the
 * guard breaks rather than when Treasury renames something.
 *
 * The price of that isolation is that none of them touch a route. `TransferBetweenAccounts`
 * calls `consume('treasury.transfers')` inside the transaction that writes the transfer,
 * and until this file nothing anywhere drove `POST /treasury/transfers` over HTTP at all —
 * every existing Treasury test calls the service directly, inside `inTenantContext()`. So
 * the controller, the policy and the exception renderer between the shopkeeper and that
 * `consume()` were untested end to end, which is exactly the gap where a refusal turns
 * into a form that silently does nothing.
 *
 * ## The one that would have been missed
 *
 * `TreasuryController::transfer()` wraps the domain call in `catch (RuntimeException)` and
 * converts it into a field message on `transfer` — the established way this codebase puts
 * «موجودی کافی نیست» next to the input that caused it. That arm is precisely the shape
 * described in `QuotaExceeded`'s docblock, the one that used to swallow the block payload
 * on its way past and hand the operator raw English. It does not any more, because
 * `QuotaExceeded extends Exception` rather than `RuntimeException` — but that is a claim
 * about this controller that only this controller can prove, and the test below is the
 * proof for Treasury.
 *
 * ## Its sibling metric, and why it is not here
 *
 * `treasury.cash_transactions` is metered too, in `RecordCashTransaction`, and it has **no
 * HTTP route**: nothing in `Treasury/routes/web.php` reaches it, and the only caller in the
 * product is the scheduled recurring-document generator. There is therefore no enforcement
 * site to drive for it, and inventing one here would test a fixture rather than the
 * product. It stays uncovered, deliberately and visibly, rather than covered by a call to
 * the service that would look like coverage of a door nobody can open.
 */
beforeEach(function (): void {
    $this->tenant = Tenant::factory()->withDomain()->create();
    $this->url = appUrl();

    subscribe($this->tenant, 'pro');
    app(SubscriptionResolver::class)->forget();
    app(TenantProvisioner::class)->seedRoles($this->tenant);

    /** @var array{User, Account, Account} $fixtures */
    $fixtures = inTenantContext($this->tenant, function (): array {
        $owner = User::factory()->create();
        $owner->assignRole('Owner');

        // 100,000,000 rial in the drawer, so every transfer below clears the cash-box
        // overdraw guard and the only thing that can refuse one is the credit.
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
});

afterEach(fn () => app(TenantContext::class)->forget());

/**
 * Bank the takings, through the real endpoint.
 *
 * Deliberately asserts nothing about the response: every test here wants to say something
 * different about it, and a helper that asserted success could not be used by the tests
 * about refusal.
 *
 * @return Illuminate\Testing\TestResponse<Illuminate\Http\Response>
 */
function bankTakings(int $amount = 10_000_000, int $fee = 0): Illuminate\Testing\TestResponse
{
    /** @var User $owner */
    $owner = test()->owner;
    /** @var Account $till */
    $till = test()->till;
    /** @var Account $bank */
    $bank = test()->bank;
    /** @var string $url */
    $url = test()->url;

    return test()->actingAs($owner)->post($url.'/treasury/transfers', [
        'from_account_id' => $till->id,
        'to_account_id' => $bank->id,
        'amount' => $amount,
        'fee' => $fee,
        'reference' => null,
    ]);
}

function transferCount(): int
{
    /** @var Tenant $tenant */
    $tenant = test()->tenant;

    /** @var int $count */
    $count = inTenantContext($tenant, fn (): int => AccountTransfer::query()->count());

    return $count;
}

it('spends one transfer credit for one banking of the takings', function (): void {
    bankTakings()->assertSessionHasNoErrors()->assertRedirect();

    expect(quotaUsed($this->tenant, 'treasury.transfers'))->toBe(1)
        // The pairing is the interesting claim, not the number: a spent credit always has
        // a row behind it, because `consume()` runs inside the transaction that writes it.
        ->and(transferCount())->toBe(1);
});

it('charges one credit for the whole transfer, fee line included', function (): void {
    // A card settlement: 50,000,000 reaches the bank and the PSP keeps 350,000, which is a
    // third ledger line. Three lines, one movement of the shop's money, one credit — a
    // meter that counted ledger rows would charge a shop half again for banking through a
    // provider that takes a cut, which is a charge for somebody else's fee.
    bankTakings(50_000_000, fee: 350_000)->assertSessionHasNoErrors();

    expect(quotaUsed($this->tenant, 'treasury.transfers'))->toBe(1);

    inTenantContext($this->tenant, function (): void {
        expect(LedgerEntry::query()->count())->toBe(3);
    });
});

it('refuses the transfer that would cross the ceiling, and moves no money', function (): void {
    capQuota($this->tenant, 'treasury.transfers', 1);

    bankTakings()->assertSessionHasNoErrors();
    expect(transferCount())->toBe(1);

    // The second one is the whole test. A shop at its cap must be *told*, not handed a
    // form that does nothing — see CLAUDE.md on the operator pressing submit twice with a
    // customer at the counter.
    $blocked = bankTakings();

    $blocked->assertSessionHasErrors('quota');

    expect(transferCount())->toBe(1)
        ->and(quotaUsed($this->tenant, 'treasury.transfers'))->toBe(1);

    inTenantContext($this->tenant, function (): void {
        // And the money is where it was. A refusal that had rolled back the transfer row
        // while leaving its ledger lines behind would be the worst of both: a shop whose
        // bank balance moved for a transfer it has no record of.
        expect(LedgerEntry::query()->count())->toBe(2);
    });
});

it('refuses through the controller arm that used to swallow the block', function (): void {
    capQuota($this->tenant, 'treasury.transfers', 0);

    bankTakings()
        ->assertSessionHasErrors('quota')
        // `transfer` is the key `TreasuryController::transfer()` writes when it converts a
        // `RuntimeException` into a field message. A quota refusal must NOT arrive that
        // way: «موجودی کافی نیست» and «سهمیهٔ شما تمام شد» are different problems with
        // different answers, and the second one has an upgrade button behind it that the
        // first must never show.
        ->assertSessionDoesntHaveErrors('transfer');
});

it('hands the operator something to render, not just an error string', function (): void {
    capQuota($this->tenant, 'treasury.transfers', 0);

    bankTakings();

    /** @var array<string, mixed> $block */
    $block = session('quota_block') ?? [];

    // These are the keys `quota-block.tsx` reads. A refusal that reached the browser
    // without them would render an empty card, which is worse than a 500 because nobody
    // would report it.
    expect($block)->toHaveKeys(['metric', 'label', 'message', 'used', 'limit', 'resets_at', 'next_plan'])
        ->and($block['metric'])->toBe('treasury.transfers')
        // Persian, not the exception's English. The whole reason `QuotaExceeded` no longer
        // extends `RuntimeException` is that a dozen controllers — this one included —
        // used to convert it into a field message carrying exactly that English string.
        ->and($block['message'])->toBeString()->not->toContain('Quota exceeded');
});

it('names the cheapest plan that would actually fit', function (): void {
    capQuota($this->tenant, 'treasury.transfers', 0);

    bankTakings();

    /** @var array{next_plan?: array{code?: string, due?: array<string, mixed>}} $block */
    $block = session('quota_block') ?? [];

    // Not "the next one up the list" — the cheapest rung above «حرفه‌ای» whose limit clears
    // the wall the shop just hit. Aiming a shop at a plan that would block it again
    // tomorrow is how an upsell becomes a refund.
    expect($block['next_plan']['code'] ?? null)->toBe('enterprise')
        // And it quotes the prorated amount rather than the sticker price: the shop is
        // mid-period and is owed credit for the days it already paid for (ADR 0006).
        ->and($block['next_plan']['due'] ?? null)->toBeArray();
});

/**
 * A transfer refused for its own reasons costs the shop nothing.
 *
 * ## Read this one for what it is, which is less than its Sales counterpart claims
 *
 * Every refusal `TransferBetweenAccounts` can produce — a zero amount, the same account at
 * both ends, an inactive account, an overdrawn cash box — is raised by `guard()`, and
 * `guard()` runs BEFORE `$this->connection->transaction(...)` opens. So this proves the
 * ordering — nothing is metered ahead of the domain check, the way it would be if
 * `consume()` had been put in the controller — and it does NOT prove the rollback: no
 * route-reachable failure exists in this service after `consume()` has run, because the
 * three ledger lines are constructed to balance and the fee account is created on demand
 * rather than looked up and missed.
 *
 * Stated rather than engineered around. The alternative was to reach past the route and
 * break the ledger by hand, which tests a fixture instead of the product — and a comment
 * admitting the smaller claim is worth more than an assertion pretending to the larger one.
 */
it('spends nothing when the transfer is refused for its own reasons', function (): void {
    // 500,000,000 out of a drawer holding 100,000,000. A till cannot hold less than
    // nothing, so the guard refuses it and the controller renders the message beside the
    // amount field, where the person who mistyped is looking.
    bankTakings(500_000_000)->assertSessionHasErrors('transfer');

    expect(transferCount())->toBe(0)
        // No row at all, rather than a row reading zero: the two are different claims, and
        // only the first says the shop was never charged for the attempt.
        ->and(quotaRowExists($this->tenant, 'treasury.transfers'))->toBeFalse();
});
