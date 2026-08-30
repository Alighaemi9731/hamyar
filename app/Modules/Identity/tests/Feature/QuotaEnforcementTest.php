<?php

declare(strict_types=1);

use App\Modules\Identity\Models\Invitation;
use App\Modules\Identity\Models\User;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Platform\Services\SubscriptionResolver;
use App\Modules\Platform\Services\TenantProvisioner;
use App\Support\Quota\QuotaGuard;
use App\Support\Tenancy\TenantContext;

/**
 * Seats, at the place a shopkeeper actually meets them.
 *
 * ## Why this file exists separately from the guard's own suite
 *
 * `Platform/tests/Feature/Quota/*` proves the guard is correct against a synthetic metric,
 * deliberately, so those tests break when the guard breaks rather than when Identity
 * renames something. The cost of that isolation is that none of them touch a route, and a
 * guard that is perfect and never called is indistinguishable, from the shop floor, from
 * no guard at all. This is the other half: that `POST /settings/users/invite` itself
 * refuses, that the refusal arrives as something a React component can render, and that
 * nothing was written on the way.
 *
 * ## `identity.users` is a Total window, and that changes what there is to assert
 *
 * `sales.invoices` is a monthly flow: a credit is spent, a `usage_counters` row records
 * it, and `quotaUsed()` reads it back. Seats are not a flow. `Window::Total` has no
 * period, no counter row and no reset — `DatabaseQuotaGuard::assertCapacity()` takes an
 * advisory lock, runs the metric's `measure:` closure over the shop's **live rows**, and
 * either lets the write through or throws. Nothing is ever written to `usage_counters`,
 * so `quotaUsed()` stays 0 and `quotaRowExists()` stays false for the whole life of this
 * file. Asserting on them the way the Sales suite does would be asserting on a table this
 * metric does not use, and every one of those assertions would pass whether the guard ran
 * or not.
 *
 * So the counter here is the shop itself, read through `seatsInUse()` below, and the
 * claims are about capacity rather than about credits:
 *
 * - taking a seat is refused at the ceiling and leaves no invitation behind;
 * - **giving a seat back frees it in the same instant**, because the measure is the truth
 *   rather than a tally that has to be decremented by somebody remembering to;
 * - and the back door that follows from that — deactivate, invite, re-activate — is shut,
 *   which is the reason `toggleActive()` consumes on the way back up.
 */
beforeEach(function (): void {
    $this->tenant = Tenant::factory()->withDomain()->create();
    $this->url = appUrl();

    // «حرفه‌ای» allows six seats. Every assertion below runs against that real number or
    // an override of it, never around it.
    subscribe($this->tenant, 'pro');
    app(SubscriptionResolver::class)->forget();
    app(TenantProvisioner::class)->seedRoles($this->tenant);

    /** @var array{User, User} $staff */
    $staff = inTenantContext($this->tenant, function (): array {
        $owner = User::factory()->create(['name' => 'مالک']);
        $owner->assignRole('Owner');

        // A second body in a seat, and not an Owner: `UserPolicy::deactivate()` refuses
        // self-deactivation and `wouldLeaveNoOwner()` refuses the last Owner, so the
        // freeing-a-seat tests need somebody who is neither.
        $seller = User::factory()->create(['name' => 'فروشنده']);
        $seller->assignRole('Salesperson');

        return [$owner, $seller];
    });

    [$this->owner, $this->seller] = $staff;
});

afterEach(fn () => app(TenantContext::class)->forget());

/**
 * One invitation, through the real endpoint.
 *
 * Deliberately not asserting anything about the response: every test here wants to say
 * something different about it, and a helper that asserted success could not be used by
 * the tests about refusal.
 *
 * @return Illuminate\Testing\TestResponse<Illuminate\Http\Response>
 */
function inviteStaff(string $mobile = '09121110000', string $role = 'Cashier'): Illuminate\Testing\TestResponse
{
    /** @var User $owner */
    $owner = test()->owner;
    /** @var string $url */
    $url = test()->url;

    return test()->actingAs($owner)->post($url.'/settings/users/invite', [
        'name' => 'کاربر تازه',
        'mobile' => $mobile,
        'role' => $role,
    ]);
}

/**
 * How many seats the shop is standing in right now — the metric's own `measure:` closure,
 * reached through the guard rather than reimplemented here.
 *
 * Reimplementing it would be the trap: a test that counts users itself goes on passing
 * after somebody changes what the metric counts, which is precisely the change that would
 * hand a shop free seats.
 */
function seatsInUse(): int
{
    /** @var Tenant $tenant */
    $tenant = test()->tenant;

    /** @var int $used */
    $used = inTenantContext(
        $tenant,
        static fn (): int => app(QuotaGuard::class)->check('identity.users')->used
    );

    return $used;
}

function invitationCount(): int
{
    /** @var Tenant $tenant */
    $tenant = test()->tenant;

    /** @var int $count */
    $count = inTenantContext($tenant, static fn (): int => Invitation::query()->count());

    return $count;
}

it('takes a seat for one invitation, and keeps no counter row for it', function (): void {
    // Two active users to begin with, and no invitation yet.
    expect(seatsInUse())->toBe(2);

    inviteStaff()->assertSessionHasNoErrors()->assertRedirect();

    // The seat is reserved at INVITE, not at accept. A shop that could invite ten people
    // into a two-seat plan and only discover it as each of them accepted would have ten
    // disappointed employees and one support ticket.
    expect(seatsInUse())->toBe(3)
        ->and(invitationCount())->toBe(1)
        // And nothing landed in `usage_counters`. Not an oversight — a standing capacity
        // is measured, never tallied, so there is no row to keep and nothing to reset.
        // The Sales suite's `quotaUsed()` assertion has no meaning here, and writing one
        // anyway would pin a behaviour this metric deliberately does not have.
        ->and(quotaRowExists($this->tenant, 'identity.users'))->toBeFalse()
        ->and(quotaUsed($this->tenant, 'identity.users'))->toBe(0);
});

it('refuses the invitation that would cross the ceiling, and writes no invitation', function (): void {
    // Two seats, both already sat in. The next one is the whole test.
    capQuota($this->tenant, 'identity.users', 2);

    $blocked = inviteStaff();

    $blocked->assertSessionHasErrors('quota');

    // Nothing half-created: the exception is thrown inside the transaction that writes the
    // invitation, so the row unwinds with it. A shop at its cap must be *told*, not handed
    // a form that silently does nothing — see CLAUDE.md on the operator pressing submit
    // twice with a customer at the counter.
    expect(invitationCount())->toBe(0)
        ->and(seatsInUse())->toBe(2);
});

it('hands the operator something to render, not just an error string', function (): void {
    capQuota($this->tenant, 'identity.users', 2);

    inviteStaff();

    /** @var array<string, mixed> $block */
    $block = session('quota_block') ?? [];

    // These are the keys `quota-block.tsx` reads. A refusal that reached the browser
    // without them would render an empty card, which is worse than a 500 because nobody
    // would report it.
    expect($block)->toHaveKeys(['metric', 'label', 'message', 'used', 'limit', 'resets_at', 'next_plan'])
        ->and($block['metric'])->toBe('identity.users')
        ->and($block['used'])->toBe(2)
        ->and($block['limit'])->toBe(2)
        // Persian, not the exception's English. The whole reason `QuotaExceeded` no longer
        // extends `RuntimeException` is that a dozen controllers used to convert it into a
        // field message carrying exactly that English string.
        ->and($block['message'])->toBeString()->not->toContain('Quota exceeded')
        // A standing capacity never refills, so there is no date to promise — and the
        // screen has to be able to tell that apart from "we forgot to send one". `null`
        // is the honest answer and `QuotaBlock` passes `resetsAt` straight through from a
        // verdict whose window has no period.
        ->and($block['resets_at'])->toBeNull();
});

it('names the cheapest plan that would actually fit', function (): void {
    capQuota($this->tenant, 'identity.users', 2);

    inviteStaff();

    /** @var array{next_plan?: array{code?: string, limit?: int|null, due?: array<string, mixed>}} $block */
    $block = session('quota_block') ?? [];

    // Not "the next one up the list" — the cheapest rung whose limit clears the wall the
    // shop just hit. Aiming a shop at a plan that would block it again tomorrow is how an
    // upsell becomes a refund.
    expect($block['next_plan']['code'] ?? null)->toBe('enterprise')
        /*
        | And the number quoted is finite, which is the interesting part.
        |
        | «نامحدود» is unlimited on almost everything and deliberately is NOT on seats: a
        | user costs us per head whatever we charge, so the top rung sells 25 of them
        | rather than «بی‌نهایت» (Gate 6, item 9). A block screen that promised unlimited
        | staff here would be selling something no plan contains.
        */
        ->and($block['next_plan']['limit'] ?? null)->toBe(25)
        // The prorated amount, not the sticker price: the shop is mid-period on «حرفه‌ای»
        // and is owed credit for the days it already paid for (ADR 0006).
        ->and($block['next_plan']['due'] ?? null)->toBeArray();
});

it('frees the seat the instant somebody is deactivated', function (): void {
    /*
    | The claim this whole file is here for.
    |
    | Because a Total window is measured rather than tallied, there is no counter to
    | decrement and therefore nobody who has to remember to decrement it: the seat is free
    | the moment the row stops matching `is_active = true`. The alternative design — a
    | credit spent per hire — would charge a shop for the seasonal assistant it let go in
    | Esfand for the rest of the year, and the shop would have no way to see why.
    */
    capQuota($this->tenant, 'identity.users', 2);

    inviteStaff()->assertSessionHasErrors('quota');

    $this->actingAs($this->owner)
        ->put($this->url.'/settings/users/'.idOf($this->seller).'/active')
        ->assertSessionHasNoErrors();

    expect(seatsInUse())->toBe(1);

    // Same request, same cap, and now it goes through. No cache sits in front of the
    // measure, which is what "immediately" has to mean for somebody standing at the
    // counter having just let a colleague go.
    inviteStaff()->assertSessionHasNoErrors()->assertRedirect();

    expect(invitationCount())->toBe(1)
        ->and(seatsInUse())->toBe(2);
});

it('shuts the deactivate-invite-reactivate back door', function (): void {
    /*
    | The direct consequence of the test above, and the reason `toggleActive()` consumes on
    | the way back up.
    |
    | Deactivate somebody, invite into the seat they vacated, re-activate them: three
    | ordinary clicks, each individually reasonable, and the shop is a seat over its plan
    | for ever. Freeing a seat is free; taking one back is a seat like any other.
    */
    capQuota($this->tenant, 'identity.users', 2);

    $this->actingAs($this->owner)
        ->put($this->url.'/settings/users/'.idOf($this->seller).'/active')
        ->assertSessionHasNoErrors();

    inviteStaff()->assertSessionHasNoErrors();

    // Owner plus one pending invitation is the two seats. The seller cannot come back.
    $this->actingAs($this->owner)
        ->put($this->url.'/settings/users/'.idOf($this->seller).'/active')
        ->assertSessionHasErrors('quota');

    /** @var User $seller */
    $seller = inTenantContext(
        $this->tenant,
        fn (): User => User::query()->whereKey(idOf($this->seller))->firstOrFail()
    );

    // Still off. The `is_active` write is inside the same transaction as the consume, so
    // a refusal takes the flag back with it.
    expect($seller->is_active)->toBeFalse()
        ->and(seatsInUse())->toBe(2);
});

it('never signs anybody out for hitting the seat cap', function (): void {
    /*
    | A quota is a limit on recording new work, never a lock on the door.
    |
    | Two things could go wrong here and both are the same kind of wrong. The person who
    | pressed the button gets an exception thrown from inside their request, so a handler
    | that turned it into a 401 or a redirect to login would put them back at the sign-in
    | screen with a customer waiting. And a shop whose cap is lowered *under* it — a plan
    | downgrade, a lapsed subscription falling back to the free rung's two seats — is
    | instantly over its limit through no action of its own; if that logged the surplus
    | staff out, a billing event would read as the software breaking.
    */
    capQuota($this->tenant, 'identity.users', 1);

    inviteStaff()->assertSessionHasErrors('quota');

    $this->assertAuthenticatedAs($this->owner);

    // And the screen they were on still loads: reads are never metered.
    $this->actingAs($this->owner)->get($this->url.'/settings/users')->assertOk();

    // The seller is the surplus seat — over the cap of one, and still working.
    $this->actingAs($this->seller)->get($this->url.'/dashboard')->assertOk();

    $this->assertAuthenticatedAs($this->seller);
});

it('takes no seat when the invitation fails for its own reasons', function (): void {
    /*
    | The Sales suite's companion test asserts `quotaRowExists()` is false after a failed
    | write, to tell "rolled back" apart from "wrote a zero". That distinction does not
    | exist for a standing capacity — there is never a row either way — so the honest
    | version of the claim is the one below: a write refused for a reason of its own leaves
    | the shop's seat count exactly where it was.
    |
    | A duplicate mobile is the real case. `Rule::unique` fails in the FormRequest, so this
    | never reaches the transaction at all — which is the point: the capacity a shop is
    | using is its live rows, and a request that creates no row cannot cost it a seat no
    | matter where it died.
    */
    /** @var string $taken */
    $taken = inTenantContext(
        $this->tenant,
        fn (): ?string => User::query()->find(idOf($this->seller))?->mobile
    );

    inviteStaff($taken)->assertSessionHasErrors('mobile')->assertSessionDoesntHaveErrors('quota');

    expect(invitationCount())->toBe(0)
        ->and(seatsInUse())->toBe(2)
        ->and(quotaRowExists($this->tenant, 'identity.users'))->toBeFalse();
});
