<?php

declare(strict_types=1);

use App\Modules\Identity\Models\User;
use App\Modules\Platform\Events\SubscriptionActivated;
use App\Modules\Platform\Events\SubscriptionInvoicePaid;
use App\Modules\Platform\Models\PaymentAttempt;
use App\Modules\Platform\Models\Plan;
use App\Modules\Platform\Models\Subscription;
use App\Modules\Platform\Models\SubscriptionInvoice;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Platform\Services\BillingService;
use App\Modules\Platform\Services\Payments\FakeGateway;
use App\Modules\Platform\Services\Payments\PaymentGateway;
use App\Modules\Platform\Services\PlanCatalogueSeeder;
use App\Modules\Platform\Services\SubscriptionResolver;
use App\Modules\Platform\Services\TenantProvisioner;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Event;

/**
 * The payment path, end to end, against {@see FakeGateway}.
 *
 * Every test here drives the real `BillingService` — only the two network calls are
 * swapped. Mocking `BillingService` itself would leave the code that actually decides
 * whether a shop has paid completely untested.
 */
beforeEach(function (): void {
    app(PlanCatalogueSeeder::class)->sync();

    $this->tenant = Tenant::factory()->withDomain()->create();
    $this->url = appUrl();

    app(TenantProvisioner::class)->seedRoles($this->tenant);

    $this->user = app(TenantContext::class)->runFor($this->tenant, function (): User {
        $user = User::factory()->create();
        $user->assignRole('Owner');

        return $user;
    });

    $this->pro = Plan::query()->where('code', 'pro')->firstOrFail();
    $this->billing = app(BillingService::class);

    /** @var FakeGateway $gateway */
    $gateway = app(PaymentGateway::class);
    $this->gateway = $gateway->willSucceed();
});

afterEach(fn () => app(TenantContext::class)->forget());

function payFor(Tenant $tenant, Plan $plan): PaymentAttempt
{
    $billing = app(BillingService::class);
    $invoice = $billing->invoiceForPlan($tenant, $plan);
    $redirect = $billing->initiatePayment($invoice, 'https://example.test/billing/callback');

    return $billing->verifyCallback($redirect->authority, ['Status' => 'OK']);
}

/* ------------------------------------------------------------ happy path -- */

it('buys a plan and activates the subscription', function (): void {
    Event::fake([SubscriptionInvoicePaid::class, SubscriptionActivated::class]);

    subscribe($this->tenant, 'basic');

    $attempt = payFor($this->tenant, $this->pro);

    expect($attempt->isVerified())->toBeTrue();
    expect($attempt->reference)->toBe('REF-'.$attempt->authority);

    app(TenantContext::class)->runAsPlatform(function () use ($attempt): void {
        expect($attempt->invoice->refresh()->isPaid())->toBeTrue();
    });

    Event::assertDispatched(SubscriptionInvoicePaid::class);
    Event::assertDispatched(SubscriptionActivated::class);
});

it('numbers invoices sequentially per tenant without gaps', function (): void {
    // Never MAX(+1) — the counter table with a row lock (project convention).
    $other = Tenant::factory()->withDomain()->create();

    $first = $this->billing->invoiceForPlan($this->tenant, $this->pro);
    $second = $this->billing->invoiceForPlan($this->tenant, $this->pro);
    $otherFirst = $this->billing->invoiceForPlan($other, $this->pro);

    expect($first->number)->toBe('SUB-00001');
    expect($second->number)->toBe('SUB-00002');
    // A second shop starts at 1 again: the sequence is per tenant, not global.
    expect($otherFirst->number)->toBe('SUB-00001');
});

it('bills only the difference when upgrading mid-period', function (): void {
    $subscription = subscribe($this->tenant, 'basic', [
        'current_period_start' => now()->subDays(11),
        'current_period_end' => now()->addDays(19),
    ]);

    $invoice = $this->billing->invoiceForPlan($this->tenant, $this->pro);

    // ADR 0006: charge − credit, not the full Pro price.
    expect($invoice->total)->toBeLessThan($this->pro->price);
    expect($invoice->total)->toBeRial();
    expect($invoice->lines)->toHaveCount(2);

    unset($subscription);
});

it('charges the full price to renew an EXPIRED subscription', function (): void {
    // Regression. Proration on a period that has already ended yields zero remaining
    // days and therefore a zero invoice — a lapsed shop would have renewed for free,
    // silently, and the only symptom would be missing revenue.
    subscribe($this->tenant, 'basic', [
        'current_period_start' => now()->subDays(40),
        'current_period_end' => now()->subDay(),
    ]);

    $invoice = $this->billing->invoiceForPlan($this->tenant, $this->pro);

    expect($invoice->total)->toBe($this->pro->price);
    expect($invoice->requiresPayment())->toBeTrue();
});

it('charges the full price when upgrading off the FREE plan', function (): void {
    // The free rung cost nothing, so there is no unused value to credit against the new
    // plan (ADR 0006) — prorating here would hand out a discount funded by nothing. This
    // used to be the trial's rule; the free plan inherited it at DECISION GATE 6, and
    // `hasLivePeriod()` gets it right for free because a free subscription has no period.
    subscribe($this->tenant, 'basic', [
        'current_period_start' => now()->subMonths(6),
        'current_period_end' => null,
    ]);

    $invoice = $this->billing->invoiceForPlan($this->tenant, $this->pro);

    expect($invoice->total)->toBe($this->pro->price);
});

/* ------------------------------------------------------- the plan change -- */

it('MOVES THE SHOP ONTO THE PLAN IT PAID FOR', function (): void {
    // The regression this whole section exists for. `applyPayment()` extended the period
    // and never wrote `plan_id`, so a shop upgraded, paid, and stayed on Basic — with a
    // paid invoice as the only evidence anything had happened. No test asserted the plan
    // changed, which is why it survived from Phase 2 to Phase 12.
    $subscription = subscribe($this->tenant, 'basic', [
        'current_period_start' => now()->subDays(11),
        'current_period_end' => now()->addDays(19),
    ]);

    payFor($this->tenant, $this->pro);

    $fresh = app(TenantContext::class)->runAsPlatform(
        // whereKey()->firstOrFail(), not findOrFail(): the latter also accepts an array
        // of ids, so its return type widens to include a Collection and Larastan is
        // right to refuse the narrower closure signature.
        fn (): Subscription => Subscription::query()->whereKey($subscription->getKey())->firstOrFail()
    );

    expect($fresh->plan_id)->toBe($this->pro->getKey());
    expect($fresh->plan_changed_at)->not->toBeNull();
    // ADR 0006: an upgrade keeps its renewal date. The plan moves, the date does not.
    expect($fresh->current_period_end?->toDateString())
        ->toBe($subscription->current_period_end?->toDateString());
});

it('unlocks the new plan for the rest of the request', function (): void {
    // The other half: the resolver is a singleton memoising one subscription per tenant,
    // so without ForgetResolvedSubscription the process that just took the money keeps
    // answering from the plan the shop had before it paid.
    subscribe($this->tenant, 'basic');

    app(TenantContext::class)->runFor($this->tenant, function (): void {
        expect(app(SubscriptionResolver::class)->grants('repairs'))->toBeFalse();

        payFor($this->tenant, $this->pro);

        // No forget() here on purpose — the listener is what has to have done it.
        expect(app(SubscriptionResolver::class)->grants('repairs'))->toBeTrue();
    });
});

it('records which plan an invoice was for', function (): void {
    subscribe($this->tenant, 'basic');

    $invoice = $this->billing->invoiceForPlan($this->tenant, $this->pro);

    expect($invoice->plan_id)->toBe($this->pro->getKey());
    // And the snapshot still says what it said: the column is what the invoice MEANS,
    // `lines` is what it SAYS, and neither replaces the other.
    expect($invoice->lines[0]['label'])->toContain($this->pro->name_fa);
});

it('does not stamp plan_changed_at on a plain renewal', function (): void {
    // Paying again for the plan you are already on is a renewal, not a change. A
    // `plan_changed_at` that moved here would lie to whoever reads it next.
    subscribe($this->tenant, 'pro', [
        'current_period_start' => now()->subDays(40),
        'current_period_end' => now()->subDay(),
    ]);

    payFor($this->tenant, $this->pro);

    $fresh = app(TenantContext::class)->runAsPlatform(
        fn (): ?Subscription => Subscription::query()->where('tenant_id', $this->tenant->getKey())->first()
    );

    expect($fresh?->plan_id)->toBe($this->pro->getKey());
    expect($fresh?->plan_changed_at)->toBeNull();
});

it('grants a subscription to a shop that paid without one', function (): void {
    // "We took the money and the shop got nothing" used to be the literal behaviour:
    // with no subscription row, applyPayment() fired its event and returned.
    expect(app(TenantContext::class)->runAsPlatform(
        fn (): int => Subscription::query()->where('tenant_id', $this->tenant->getKey())->count()
    ))->toBe(0);

    payFor($this->tenant, $this->pro);

    $created = app(TenantContext::class)->runAsPlatform(
        fn (): ?Subscription => Subscription::query()->where('tenant_id', $this->tenant->getKey())->first()
    );

    expect($created)->not->toBeNull();
    expect($created?->plan_id)->toBe($this->pro->getKey());
    expect($created?->status)->toBe(Subscription::STATUS_ACTIVE);
});

/* -------------------------------------------------- the upgrade button -- */

it('accepts the plan CODE the billing page actually posts', function (): void {
    // `billing/index.tsx` has always posted `plan.code`; the route was bound by id, so
    // every press of the upgrade button 404'd. Nothing caught it because no test had
    // ever posted to this route at all — the suite drove BillingService directly.
    subscribe($this->tenant, 'basic');

    $this->actingAs($this->user)
        ->post(appUrl().'/billing/subscribe/pro')
        ->assertRedirect();
});

it('404s a plan code that does not exist', function (): void {
    subscribe($this->tenant, 'basic');

    $this->actingAs($this->user)
        ->post(appUrl().'/billing/subscribe/no-such-plan')
        ->assertNotFound();
});

/* ----------------------------------------------------------- idempotency -- */

it('does not settle the same authority twice', function (): void {
    subscribe($this->tenant, 'basic');

    $invoice = $this->billing->invoiceForPlan($this->tenant, $this->pro);
    $redirect = $this->billing->initiatePayment($invoice, 'https://example.test/cb');

    $first = $this->billing->verifyCallback($redirect->authority, ['Status' => 'OK']);
    $verifiedAt = $first->verified_at;

    // The shop refreshes the return page. Same authority, second time.
    $second = $this->billing->verifyCallback($redirect->authority, ['Status' => 'OK']);

    expect($second->getKey())->toBe($first->getKey());
    expect($verifiedAt)->not->toBeNull();
    expect($second->verified_at?->toIso8601String())->toBe($verifiedAt?->toIso8601String());

    app(TenantContext::class)->runAsPlatform(function (): void {
        // One attempt, one paid invoice — not two of either.
        expect(PaymentAttempt::query()->where('status', PaymentAttempt::STATUS_VERIFIED)->count())->toBe(1);
        expect(SubscriptionInvoice::query()->where('status', SubscriptionInvoice::STATUS_PAID)->count())->toBe(1);
    });
});

it('does not extend the period twice on a replayed callback', function (): void {
    // The bug that gives a shop two months for one payment.
    $subscription = subscribe($this->tenant, 'basic', [
        'current_period_start' => now()->subDays(11),
        'current_period_end' => now()->addDays(19),
    ]);

    $invoice = $this->billing->invoiceForPlan($this->tenant, $this->pro);
    $redirect = $this->billing->initiatePayment($invoice, 'https://example.test/cb');

    $this->billing->verifyCallback($redirect->authority, ['Status' => 'OK']);
    $afterFirst = app(TenantContext::class)->runAsPlatform(
        fn () => $subscription->refresh()->current_period_end
    );

    $this->billing->verifyCallback($redirect->authority, ['Status' => 'OK']);
    $afterSecond = app(TenantContext::class)->runAsPlatform(
        fn () => $subscription->refresh()->current_period_end
    );

    expect($afterSecond?->toIso8601String())->toBe($afterFirst?->toIso8601String());
});

it('rejects an authority it never issued', function (): void {
    expect(fn () => $this->billing->verifyCallback('FORGED-0001', ['Status' => 'OK']))
        ->toThrow(RuntimeException::class, 'Unknown payment authority');
});

/* --------------------------------------------------------------- failure -- */

it('records a failed payment without granting anything', function (): void {
    $this->gateway->willFail('موجودی کافی نیست.');

    subscribe($this->tenant, 'basic');

    $attempt = payFor($this->tenant, $this->pro);

    expect($attempt->status)->toBe(PaymentAttempt::STATUS_FAILED);
    expect($attempt->error)->toBe('موجودی کافی نیست.');

    app(TenantContext::class)->runAsPlatform(function () use ($attempt): void {
        expect($attempt->invoice->refresh()->isPaid())->toBeFalse();
    });
});

it('treats an abandoned payment as a failure, not a success', function (): void {
    subscribe($this->tenant, 'basic');

    $invoice = $this->billing->invoiceForPlan($this->tenant, $this->pro);
    $redirect = $this->billing->initiatePayment($invoice, 'https://example.test/cb');

    // Zarinpal sends Status=NOK when the customer backs out.
    $attempt = $this->billing->verifyCallback($redirect->authority, ['Status' => 'NOK']);

    expect($attempt->status)->toBe(PaymentAttempt::STATUS_FAILED);
});

it('lets a shop retry after a failed attempt', function (): void {
    subscribe($this->tenant, 'basic');

    $invoice = $this->billing->invoiceForPlan($this->tenant, $this->pro);

    $failed = $this->billing->initiatePayment($invoice, 'https://example.test/cb');
    $this->gateway->willFail();
    $this->billing->verifyCallback($failed->authority, ['Status' => 'OK']);

    // Same invoice, new attempt — the first one being decided must not close the door.
    $this->gateway->willSucceed();
    $retry = $this->billing->initiatePayment($invoice, 'https://example.test/cb');
    $attempt = $this->billing->verifyCallback($retry->authority, ['Status' => 'OK']);

    expect($attempt->isVerified())->toBeTrue();
    expect($retry->authority)->not->toBe($failed->authority);
});

/* ---------------------------------------------------------------- credit -- */

it('applies stored credit and skips the gateway when it covers everything', function (): void {
    $subscription = subscribe($this->tenant, 'basic');

    app(TenantContext::class)->runAsPlatform(
        fn () => $subscription->update(['credit_balance' => 99_000_000])
    );

    $invoice = $this->billing->invoiceForPlan($this->tenant, $this->pro);

    expect($invoice->total)->toBe(0);
    expect($invoice->requiresPayment())->toBeFalse();

    $this->billing->settleWithoutPayment($invoice);

    app(TenantContext::class)->runAsPlatform(
        fn () => expect($invoice->refresh()->isPaid())->toBeTrue()
    );
});

it('consumes credit at draft time so it cannot be spent twice', function (): void {
    $subscription = subscribe($this->tenant, 'basic');

    app(TenantContext::class)->runAsPlatform(
        fn () => $subscription->update(['credit_balance' => 1_000_000])
    );

    $first = $this->billing->invoiceForPlan($this->tenant, $this->pro);
    $second = $this->billing->invoiceForPlan($this->tenant, $this->pro);

    expect($first->credit_applied)->toBe(1_000_000);
    // Two open invoices must not both claim the same credit.
    expect($second->credit_applied)->toBe(0);
});

/* ------------------------------------------------------------------ http -- */

it('shows the billing page to an owner', function (): void {
    subscribe($this->tenant, 'basic');

    $this->actingAs($this->user)
        ->get($this->url.'/billing')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('platform/billing/index')
            ->has('plans', 3)
        );
});

it('refuses billing to a role without the permission', function (): void {
    $cashier = app(TenantContext::class)->runFor($this->tenant, function (): User {
        $user = User::factory()->create();
        $user->assignRole('Cashier');

        return $user;
    });

    // Manager and below cannot spend the owner's money.
    $this->actingAs($cashier)->get($this->url.'/billing')->assertForbidden();
});

it('reaches the callback without a session', function (): void {
    subscribe($this->tenant, 'basic');

    $invoice = $this->billing->invoiceForPlan($this->tenant, $this->pro);
    $redirect = $this->billing->initiatePayment($invoice, 'https://example.test/cb');

    /*
    | Nobody is logged in: the customer came back from the gateway in a fresh context.
    |
    | With one address for every shop (ADR 0017) there is no hostname to name the tenant
    | either, so this asserts the whole of the new arrangement: the route sits outside
    | `tenant` — inside it, ResolveTenant answers a 302 to /login and the payment below is
    | never settled — and the controller finds the shop from the `payment_attempts` row
    | the authority identifies.
    */
    $this->get(appUrl('/billing/callback?Authority='.$redirect->authority.'&Status=OK'))
        ->assertRedirect(appUrl('/billing/invoices/'.$invoice->getKey()));

    app(TenantContext::class)->runAsPlatform(
        fn () => expect($invoice->refresh()->isPaid())->toBeTrue()
    );
});

it('does not show one shop invoice to another', function (): void {
    pest()->group('isolation');

    $other = Tenant::factory()->withDomain()->create();
    app(TenantProvisioner::class)->seedRoles($other);

    $intruder = app(TenantContext::class)->runFor($other, function (): User {
        $user = User::factory()->create();
        $user->assignRole('Owner');

        return $user;
    });

    $invoice = $this->billing->invoiceForPlan($this->tenant, $this->pro);

    $this->actingAs($intruder)
        ->get(appUrl().'/billing/invoices/'.$invoice->getKey())
        ->assertNotFound();
});
