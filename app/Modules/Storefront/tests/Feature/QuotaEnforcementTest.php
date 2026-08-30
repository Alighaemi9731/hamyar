<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\PriceLevel;
use App\Modules\Identity\Models\User;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Platform\Services\SubscriptionResolver;
use App\Modules\Platform\Services\TenantProvisioner;
use App\Modules\Storefront\Models\PriceListLink;
use App\Support\Quota\QuotaGuard;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;

/**
 * Price-list links, at the place a shopkeeper actually meets the meter.
 *
 * ## Why this file exists separately from the guard's own suite
 *
 * `Platform/tests/Feature/Quota/*` proves the guard is correct against a synthetic metric,
 * deliberately, so those tests break when the guard breaks rather than when Storefront
 * renames something. The cost of that isolation is that none of them touch a route, and a
 * guard that is perfect and never called is indistinguishable, from the shop floor, from
 * no guard at all. This is the other half: that `POST /storefront/links` itself refuses,
 * that the refusal arrives as something a React component can render, and that nothing was
 * minted on the way.
 *
 * ## `storefront.price_list_links` is a Total window, and that changes what there is to assert
 *
 * `sales.invoices` is a monthly flow: a credit is spent, a `usage_counters` row records
 * it, and `quotaUsed()` reads it back. A price-list link is not a flow.
 * `DatabaseQuotaGuard::assertCapacity()` takes an advisory lock, runs the metric's
 * `measure:` closure over the shop's **live rows** — unrevoked, unexpired — and either
 * lets the write through or throws. Nothing is ever written to `usage_counters`, so
 * `quotaUsed()` stays 0 and `quotaRowExists()` stays false for the whole life of this
 * file. Copying the Sales suite's counter assertions would be asserting on a table this
 * metric does not use, and every one of them would pass whether the guard ran or not.
 *
 * So what a plan sells here is **how many lists are live at once**, never how many were
 * ever minted — and the reason that distinction is worth a suite of its own is the one
 * act it protects:
 *
 * > **Revoking a leaked price list must give the slot back.**
 *
 * A shop that discovers its «همکار» figures in a competitor's WhatsApp has exactly one
 * thing it should do, immediately, without weighing it against anything. A meter that
 * charged for the mint and kept charging after the revoke would put a price on closing a
 * leak, and the shop would leave it open until the month turned. That is the failure this
 * file is here to make impossible to reintroduce quietly.
 */
beforeEach(function (): void {
    $this->tenant = Tenant::factory()->withDomain()->create();
    $this->url = appUrl();

    // «حرفه‌ای» allows five live links. Every assertion below runs against that real
    // number or an override of it, never around it.
    subscribe($this->tenant, 'pro');
    app(SubscriptionResolver::class)->forget();
    app(TenantProvisioner::class)->seedRoles($this->tenant);

    /** @var array{User, PriceLevel} $fixtures */
    $fixtures = inTenantContext($this->tenant, function (): array {
        $owner = User::factory()->create(['name' => 'مالک']);
        $owner->assignRole('Owner');

        return [$owner, PriceLevel::factory()->create([
            'code' => PriceLevel::RESELLER, 'name_fa' => 'همکار', 'is_default' => false, 'position' => 2,
        ])];
    });

    [$this->owner, $this->reseller] = $fixtures;
});

afterEach(fn () => app(TenantContext::class)->forget());

/**
 * Mint one link through the real endpoint.
 *
 * Deliberately not asserting anything about the response: every test here wants to say
 * something different about it, and a helper that asserted success could not be used by
 * the tests about refusal.
 *
 * @return Illuminate\Testing\TestResponse<Illuminate\Http\Response>
 */
function mintLink(int $days = 14, string $label = 'حاج آقای رضایی'): Illuminate\Testing\TestResponse
{
    /** @var User $owner */
    $owner = test()->owner;
    /** @var PriceLevel $reseller */
    $reseller = test()->reseller;
    /** @var string $url */
    $url = test()->url;

    return test()->actingAs($owner)->post($url.'/storefront/links', [
        'price_level_id' => idOf($reseller),
        'label' => $label,
        'days' => $days,
    ]);
}

/**
 * How many slots the shop is standing in right now — the metric's own `measure:` closure,
 * reached through the guard rather than reimplemented here.
 *
 * Reimplementing it would be the trap: a test that counts links itself goes on passing
 * after somebody changes what the metric counts, and "does a revoked link still occupy a
 * slot" is exactly the question this file exists to answer.
 */
function slotsInUse(): int
{
    /** @var Tenant $tenant */
    $tenant = test()->tenant;

    /** @var int $used */
    $used = inTenantContext(
        $tenant,
        static fn (): int => app(QuotaGuard::class)->check('storefront.price_list_links')->used
    );

    return $used;
}

/**
 * Every link row the shop has ever minted, live or not — the number the meter must NOT be
 * counting.
 */
function mintedEver(): int
{
    /** @var Tenant $tenant */
    $tenant = test()->tenant;

    /** @var int $count */
    $count = inTenantContext($tenant, static fn (): int => PriceListLink::query()->count());

    return $count;
}

it('takes a slot for one minted link, and keeps no counter row for it', function (): void {
    expect(slotsInUse())->toBe(0);

    mintLink()
        ->assertSessionHasNoErrors()
        // The token is shown exactly once, because only its hash is stored. If this flash
        // is missing the shop has paid a slot for a URL nobody can ever read.
        ->assertSessionHas('minted_link');

    expect(slotsInUse())->toBe(1)
        ->and(mintedEver())->toBe(1)
        // And nothing landed in `usage_counters`. Not an oversight — a standing capacity
        // is measured, never tallied, so there is no row to keep and nothing to reset.
        // The Sales suite's `quotaUsed()` assertion has no meaning here, and writing one
        // anyway would pin a behaviour this metric deliberately does not have.
        ->and(quotaRowExists($this->tenant, 'storefront.price_list_links'))->toBeFalse()
        ->and(quotaUsed($this->tenant, 'storefront.price_list_links'))->toBe(0);
});

it('refuses the link that would cross the ceiling, and mints nothing', function (): void {
    capQuota($this->tenant, 'storefront.price_list_links', 1);

    mintLink()->assertSessionHasNoErrors();
    expect(mintedEver())->toBe(1);

    // The second one is the whole test. A shop at its cap must be *told*, not handed a
    // button that silently does nothing — and here silence would be particularly bad,
    // because the shopkeeper would go looking for a token that was never minted.
    $blocked = mintLink();

    $blocked->assertSessionHasErrors('quota');

    // `consume()` runs inside the transaction that writes the row, so the refusal unwinds
    // it: no orphan link, and no token handed out that the meter does not know about.
    expect(mintedEver())->toBe(1)
        ->and(slotsInUse())->toBe(1);
});

it('hands the operator something to render, not just an error string', function (): void {
    capQuota($this->tenant, 'storefront.price_list_links', 1);

    mintLink()->assertSessionHasNoErrors();
    mintLink();

    /** @var array<string, mixed> $block */
    $block = session('quota_block') ?? [];

    // These are the keys `quota-block.tsx` reads. A refusal that reached the browser
    // without them would render an empty card, which is worse than a 500 because nobody
    // would report it.
    expect($block)->toHaveKeys(['metric', 'label', 'message', 'used', 'limit', 'resets_at', 'next_plan'])
        ->and($block['metric'])->toBe('storefront.price_list_links')
        ->and($block['used'])->toBe(1)
        ->and($block['limit'])->toBe(1)
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
    capQuota($this->tenant, 'storefront.price_list_links', 1);

    mintLink()->assertSessionHasNoErrors();
    mintLink();

    /** @var array{next_plan?: array{code?: string, limit?: int|null, due?: array<string, mixed>}} $block */
    $block = session('quota_block') ?? [];

    // Not "the next one up the list" — the cheapest rung whose limit clears the wall the
    // shop just hit. Aiming a shop at a plan that would block it again tomorrow is how an
    // upsell becomes a refund.
    expect($block['next_plan']['code'] ?? null)->toBe('enterprise')
        // Links cost us nothing per unit, so the top rung really is unlimited here — and
        // `null` has to survive the whole way to the screen as "no ceiling" rather than
        // being flattened into a zero, which is what a limit of «۰» would read as.
        ->and($block['next_plan']['limit'] ?? 'missing')->toBeNull()
        // The prorated amount, not the sticker price: the shop is mid-period on «حرفه‌ای»
        // and is owed credit for the days it already paid for (ADR 0006).
        ->and($block['next_plan']['due'] ?? null)->toBeArray();
});

it('gives the slot back the instant a leaked link is revoked', function (): void {
    /*
    | The claim this whole file is here for.
    |
    | The shop is at its ceiling and has just found one of its lists somewhere it did not
    | send it. Revoking has to be free and has to take effect now — not at the turn of the
    | month, not after a support ticket. Because a Total window is measured rather than
    | tallied, there is no counter to decrement and therefore nobody who has to remember
    | to: `revoked_at` stops the row matching the measure, and that is the whole mechanism.
    */
    capQuota($this->tenant, 'storefront.price_list_links', 1);

    mintLink()->assertSessionHasNoErrors();

    /** @var PriceListLink $leaked */
    $leaked = inTenantContext($this->tenant, static fn (): PriceListLink => PriceListLink::query()->firstOrFail());

    mintLink()->assertSessionHasErrors('quota');

    $this->actingAs($this->owner)
        ->delete($this->url.'/storefront/links/'.idOf($leaked))
        ->assertSessionHasNoErrors();

    expect(slotsInUse())->toBe(0);

    // Same request, same cap, and now it goes through. Nothing caches link state, which is
    // what "immediately" has to mean for somebody who has just watched their «همکار»
    // prices travel.
    mintLink()->assertSessionHasNoErrors()->assertSessionHas('minted_link');

    // Two rows have existed; one slot is in use. That gap is the metric's definition, and
    // a meter that counted mints instead of live links would read 2 here and have refused
    // the replacement above.
    expect(mintedEver())->toBe(2)
        ->and(slotsInUse())->toBe(1);
});

it('gives the slot back when a link simply expires', function (): void {
    /*
    | The same claim from the other direction, and the ordinary one: most links are never
    | revoked, they just run out. Every link has an expiry — `PriceListLinkRequest` caps it
    | at 90 days and there is no "never" — so a meter that kept counting expired rows would
    | fill a shop's ceiling with dead links it cannot even see on the screen, and the only
    | remedy would be to buy a bigger plan for links nobody can open.
    */
    capQuota($this->tenant, 'storefront.price_list_links', 1);

    mintLink(days: 1)->assertSessionHasNoErrors();

    expect(slotsInUse())->toBe(1);

    $this->travelTo(CarbonImmutable::now()->addDays(2));

    expect(slotsInUse())->toBe(0);

    mintLink()->assertSessionHasNoErrors()->assertSessionHas('minted_link');

    expect(mintedEver())->toBe(2)
        ->and(slotsInUse())->toBe(1);
});

it('takes no slot when the mint fails for its own reasons', function (): void {
    /*
    | The Sales suite's companion test asserts `quotaRowExists()` is false after a failed
    | write, to tell "rolled back" apart from "wrote a zero". That distinction does not
    | exist for a standing capacity — there is never a row either way — so the honest
    | version of the claim is the one below: a mint refused for a reason of its own leaves
    | the shop's slot count exactly where it was.
    |
    | An over-long expiry is the real case, and the rule behind it is a business one: a
    | link that outlives the price list it shows is worse than an expired one, because the
    | colleague quotes a Farvardin figure in Bahman and the shop has to honour it or argue.
    | It fails in the FormRequest, so it never reaches the transaction at all — which is
    | the point: the capacity a shop is using is its live rows, and a request that creates
    | no row cannot cost it a slot no matter where it died.
    */
    mintLink(days: 400)
        ->assertSessionHasErrors('days')
        ->assertSessionDoesntHaveErrors('quota');

    expect(mintedEver())->toBe(0)
        ->and(slotsInUse())->toBe(0)
        ->and(quotaRowExists($this->tenant, 'storefront.price_list_links'))->toBeFalse();
});
