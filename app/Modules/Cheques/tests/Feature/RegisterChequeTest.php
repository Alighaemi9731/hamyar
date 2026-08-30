<?php

declare(strict_types=1);

use App\Modules\Cheques\Enums\ChequeDirection;
use App\Modules\Cheques\Enums\ChequeStatus;
use App\Modules\Cheques\Models\Cheque;
use App\Modules\CRM\Models\Account;
use App\Modules\CRM\Models\Party;
use App\Modules\Identity\Models\User;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Platform\Services\SubscriptionResolver;
use App\Modules\Platform\Services\TenantProvisioner;
use App\Support\Tenancy\TenantContext;

/**
 * Registering a cheque — the door that did not exist until `0.20.0`.
 *
 * ## What this file is really testing
 *
 * Not the posting matrix; `ChequePostingMatrixTest` pins every row of that and has since
 * Phase 7. What was missing was any way to *start*: across 104 write routes nothing created
 * a `Cheque`. The row was written in nine test files and zero production files, while
 * `cheques.cheques` sat on the plan ladder advertising «۵۰ ثبت چک در ماه» for something a
 * shop could not do once.
 *
 * So these tests assert the join: that the HTTP route creates the row, spends the credit and
 * posts the opening ledger entry **as one act** — and that a failure anywhere in that act
 * leaves none of the three behind.
 */
beforeEach(function (): void {
    $this->tenant = Tenant::factory()->withDomain()->create();
    $this->url = appUrl();

    subscribe($this->tenant, 'pro');
    app(SubscriptionResolver::class)->forget();
    app(TenantProvisioner::class)->seedRoles($this->tenant);

    /** @var array{User, Party, Account} $fixtures */
    $fixtures = inTenantContext($this->tenant, function (): array {
        $owner = User::factory()->create();
        $owner->assignRole('Owner');

        return [
            $owner,
            Party::factory()->create(['name' => 'حسن رضایی']),
            Account::factory()->create(['type' => Account::TYPE_BANK, 'name' => 'بانک ملت', 'is_active' => true]),
        ];
    });

    [$this->owner, $this->party, $this->bank] = $fixtures;
});

afterEach(fn () => app(TenantContext::class)->forget());

/**
 * The body a shopkeeper's form actually posts — Persian digits included, because that is
 * what an Iranian keypad produces and the request is expected to normalise it.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function chequeBody(array $overrides = []): array
{
    /** @var Party $party */
    $party = test()->party;

    return [
        'direction' => 'received',
        'party_id' => $party->id,
        'amount' => 250_000_000,
        'serial' => '۱۲۳۴۵۶',
        'sayad_id' => '۱۲۳۴۵۶۷۸۹۰۱۲۳۴۵۶',
        'bank_name' => 'بانک ملی',
        'branch_name' => 'شعبهٔ مرکزی',
        'account_holder' => 'حسن رضایی',
        'due_date' => '2026-12-06T00:00:00Z',
        'notes' => null,
        ...$overrides,
    ];
}

it('records a cheque a customer handed over', function (): void {
    $this->actingAs($this->owner)
        ->post($this->url.'/cheques', chequeBody())
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    /** @var Cheque|null $cheque */
    $cheque = inTenantContext($this->tenant, fn (): ?Cheque => Cheque::query()->first());

    expect($cheque)->not->toBeNull()
        // Persian digits in, Latin out — `prepareForValidation` normalises before the rules
        // run, so «۱۲۳۴۵۶» satisfies the same rule a Latin serial does.
        ->and($cheque?->serial)->toBe('123456')
        ->and($cheque?->sayad_id)->toBe('1234567890123456')
        ->and($cheque?->amount)->toBe(250_000_000)
        // Both directions land in `in_hand`; everything after is the existing state machine.
        // Compared as the enum, because the model casts the column — asserting the string
        // would pass only until somebody added the cast, or fail only because they had.
        ->and($cheque?->status)->toBe(ChequeStatus::InHand);
});

it('posts the opening ledger entry in the same act', function (): void {
    $this->actingAs($this->owner)->post($this->url.'/cheques', chequeBody())->assertRedirect();

    /** @var int $entries */
    $entries = inTenantContext($this->tenant, fn (): int => DB::table('ledger_entries')->count());

    /*
    | R1 of the posting matrix: DEBIT cheques_receivable, CREDIT the party.
    |
    | The pairing is the point. A cheque row without its posting is worse than no cheque:
    | `ChequeExposure` counts it toward the customer's credit while the ledger does not know
    | it exists, so the two answers a shop gets about the same customer disagree.
    */
    expect($entries)->toBe(2);
});

it('spends one cheque credit, inside the transaction that writes the row', function (): void {
    $this->actingAs($this->owner)->post($this->url.'/cheques', chequeBody())->assertRedirect();

    expect(quotaUsed($this->tenant, 'cheques.cheques'))->toBe(1);
});

it('refuses the cheque that would cross the monthly ceiling, and writes nothing', function (): void {
    capQuota($this->tenant, 'cheques.cheques', 1);

    $this->actingAs($this->owner)->post($this->url.'/cheques', chequeBody())->assertRedirect();

    $this->actingAs($this->owner)
        ->post($this->url.'/cheques', chequeBody(['serial' => '654321']))
        ->assertSessionHasErrors('quota');

    /** @var array{int, int} $counts */
    $counts = inTenantContext($this->tenant, fn (): array => [
        Cheque::query()->count(),
        DB::table('ledger_entries')->count(),
    ]);

    // Neither the row nor its postings. The credit was consumed inside the transaction that
    // writes them, so a refusal takes all three back together.
    expect($counts)->toBe([1, 2])
        ->and(quotaUsed($this->tenant, 'cheques.cheques'))->toBe(1);
});

it('needs a bank account for a cheque the shop itself issues', function (): void {
    // I1 debits the party and credits `cheques_payable`, drawn on a real bank account.
    // Without one there is nothing to draw on, and the refusal is a sentence rather than a
    // 500 — it arrives under `account_id`, which is the field that is wrong.
    $this->actingAs($this->owner)
        ->post($this->url.'/cheques', chequeBody(['direction' => 'issued', 'account_id' => null]))
        ->assertSessionHasErrors('account_id');

    expect(inTenantContext($this->tenant, fn (): int => Cheque::query()->count()))->toBe(0);
});

it('records a cheque the shop issues, drawn on its own bank', function (): void {
    /** @var Account $bank */
    $bank = $this->bank;

    $this->actingAs($this->owner)
        ->post($this->url.'/cheques', chequeBody(['direction' => 'issued', 'account_id' => $bank->id]))
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    /** @var Cheque|null $cheque */
    $cheque = inTenantContext($this->tenant, fn (): ?Cheque => Cheque::query()->first());

    expect($cheque?->direction)->toBe(ChequeDirection::Issued)
        ->and($cheque?->account_id)->toBe($bank->id)
        ->and($cheque?->status)->toBe(ChequeStatus::InHand);
});

it('refuses a mistyped sayad id rather than recording it as fact', function (): void {
    // Optional, because paper older than 1400 has none — but shape-checked when present,
    // since a 15-digit «صیاد» recorded as true is worse than none at all.
    $this->actingAs($this->owner)
        ->post($this->url.'/cheques', chequeBody(['sayad_id' => '123']))
        ->assertSessionHasErrors('sayad_id');

    expect(inTenantContext($this->tenant, fn (): int => Cheque::query()->count()))->toBe(0);
});

it('does not let one shop register a cheque against another shop customer', function (): void {
    $other = Tenant::factory()->withDomain()->create();
    subscribe($other, 'pro');
    app(TenantProvisioner::class)->seedRoles($other);

    /** @var Party $theirParty */
    $theirParty = inTenantContext($other, fn (): Party => Party::factory()->create(['name' => 'مغازهٔ دیگر']));

    app(SubscriptionResolver::class)->forget();

    // `exists:parties,id` runs under RLS, so another shop's party is simply not there —
    // the guarantee is the database's, not a hand-written check that could be forgotten.
    $this->actingAs($this->owner)
        ->post($this->url.'/cheques', chequeBody(['party_id' => $theirParty->id]))
        ->assertSessionHasErrors('party_id');

    expect(inTenantContext($this->tenant, fn (): int => Cheque::query()->count()))->toBe(0);
})->group('isolation');
