<?php

declare(strict_types=1);

use App\Modules\Identity\Models\User;
use App\Modules\Platform\Models\PaymentAttempt;
use App\Modules\Platform\Models\Plan;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Platform\Services\Payments\FakeGateway;
use App\Modules\Platform\Services\Payments\PaymentGateway;
use App\Modules\Platform\Services\Quota\LimitResolver;
use App\Modules\Platform\Services\SubscriptionResolver;
use App\Modules\Platform\Services\TenantProvisioner;
use App\Support\Tenancy\TenantContext;

/**
 * The Phase 12 Definition of Done, item one, walked by a machine instead of a person.
 *
 * > A shop on the free plan runs its monthly credit out, sees the block with the prorated
 * > price, pays, **lands back on the same form**, and finishes the job — with no counter
 * > reset.
 *
 * ## Why "lands back on the same form" is the load-bearing clause
 *
 * Everything else in that sentence was already true and already tested. This clause was
 * not built at all: the callback sent every shop to the billing receipt, so an operator
 * blocked mid-sale paid, landed on an invoice, and had to find their way back to the till
 * and retype a basket they had already built with a customer waiting. The upgrade worked
 * and the sale still did not happen, which reads as the payment having failed.
 *
 * ## And why the counter must NOT reset
 *
 * Paying buys a bigger ceiling, not a fresh month. If upgrading zeroed the counter, the
 * cheapest way to get unlimited credits would be to upgrade and downgrade repeatedly — and
 * a shop that legitimately upgraded mid-month would silently get its used credits back,
 * which is a discount nobody priced. The spend is history; only the limit moves.
 */
beforeEach(function (): void {
    $this->tenant = Tenant::factory()->withDomain()->create();
    $this->url = appUrl();

    app(TenantProvisioner::class)->seedRoles($this->tenant);

    $this->user = app(TenantContext::class)->runFor($this->tenant, function (): User {
        $user = User::factory()->create();
        $user->assignRole('Owner');

        return $user;
    });

    // The free rung, which is where a shop that has never paid actually lives.
    subscribe($this->tenant, 'basic');
    app(SubscriptionResolver::class)->forget();
    app(LimitResolver::class)->forget();

    /** @var FakeGateway $gateway */
    $gateway = app(PaymentGateway::class);
    $this->gateway = $gateway->willSucceed();
});

afterEach(fn () => app(TenantContext::class)->forget());

it('sends the shop back to the screen that blocked it, not to a receipt', function (): void {
    $pro = Plan::query()->where('code', 'pro')->firstOrFail();

    $response = $this->actingAs($this->user)->post(
        $this->url."/billing/subscribe/{$pro->code}",
        ['return_to' => '/sales/pos?branch=2'],
    );

    $response->assertRedirect();

    /** @var PaymentAttempt $attempt */
    $attempt = app(TenantContext::class)->runAsPlatform(
        fn (): PaymentAttempt => PaymentAttempt::query()->latest('id')->firstOrFail()
    );

    // On the attempt row, not in the session. The customer may come back from the gateway
    // in a different browser, and `billing/callback` sits outside `auth` and `tenant` for
    // exactly that reason (ADR 0017) — the session is the one thing that cannot be relied
    // on across this round trip.
    expect($attempt->return_to)->toBe('/sales/pos?branch=2');

    $this->get($this->url.'/billing/callback?Authority='.$attempt->authority.'&Status=OK')
        ->assertRedirect('/sales/pos?branch=2');
});

it('falls back to the receipt when there was no screen to go back to', function (): void {
    $pro = Plan::query()->where('code', 'pro')->firstOrFail();

    // A shop that started the upgrade from the billing page itself. There is nowhere to
    // return to, and inventing one would be worse than the receipt.
    $this->actingAs($this->user)->post($this->url."/billing/subscribe/{$pro->code}")->assertRedirect();

    /** @var PaymentAttempt $attempt */
    $attempt = app(TenantContext::class)->runAsPlatform(
        fn (): PaymentAttempt => PaymentAttempt::query()->latest('id')->firstOrFail()
    );

    expect($attempt->return_to)->toBeNull();

    $this->get($this->url.'/billing/callback?Authority='.$attempt->authority.'&Status=OK')
        ->assertRedirect($this->url.'/billing/invoices/'.$attempt->subscription_invoice_id);
});

it('refuses to be bounced off the site by a return path', function (string $hostile): void {
    $pro = Plan::query()->where('code', 'pro')->firstOrFail();

    $this->actingAs($this->user)->post(
        $this->url."/billing/subscribe/{$pro->code}",
        ['return_to' => $hostile],
    )->assertRedirect();

    /** @var PaymentAttempt $attempt */
    $attempt = app(TenantContext::class)->runAsPlatform(
        fn (): PaymentAttempt => PaymentAttempt::query()->latest('id')->firstOrFail()
    );

    // Never stored, so it can never be redirected to. Discarding is deliberate rather than
    // erroring: a bad `return_to` must cost the shop a convenience, never the payment.
    expect($attempt->return_to)->toBeNull();

    $this->get($this->url.'/billing/callback?Authority='.$attempt->authority.'&Status=OK')
        ->assertRedirect($this->url.'/billing/invoices/'.$attempt->subscription_invoice_id);
})->with([
    'protocol-relative — the one that passes a naive starts-with-slash check' => ['//evil.test/login'],
    'an absolute url' => ['https://evil.test/login'],
    'a backslash host' => ['/\\evil.test'],
    'a javascript scheme' => ['javascript:alert(1)'],
]);

it('does not give the month back when a shop upgrades', function (): void {
    // Two invoices already recorded this month, on the free rung.
    spendQuota($this->tenant, 'sales.invoices', 2);

    $pro = Plan::query()->where('code', 'pro')->firstOrFail();

    $this->actingAs($this->user)->post($this->url."/billing/subscribe/{$pro->code}")->assertRedirect();

    /** @var PaymentAttempt $attempt */
    $attempt = app(TenantContext::class)->runAsPlatform(
        fn (): PaymentAttempt => PaymentAttempt::query()->latest('id')->firstOrFail()
    );

    $this->get($this->url.'/billing/callback?Authority='.$attempt->authority.'&Status=OK');

    app(SubscriptionResolver::class)->forget();
    app(LimitResolver::class)->forget();

    // The ceiling moved; the spend did not. A counter that reset here would make
    // upgrade-then-downgrade the cheapest source of credits in the product.
    expect(quotaUsed($this->tenant, 'sales.invoices'))->toBe(2)
        ->and(app(LimitResolver::class)->forTenant(idOfModel($this->tenant), 'sales.invoices'))
        ->toBe(5_000);
});

it('honours the return path even when credit covers the whole upgrade', function (): void {
    $pro = Plan::query()->where('code', 'pro')->firstOrFail();

    // Enough stored credit that there is nothing to pay, so the gateway is skipped
    // entirely. The shopkeeper is still mid-sale and still needs to be put back.
    app(TenantContext::class)->runAsPlatform(fn () => App\Modules\Platform\Models\Subscription::query()
        ->where('tenant_id', $this->tenant->getKey())
        ->update(['credit_balance' => 99_000_000_0]));

    app(SubscriptionResolver::class)->forget();

    $this->actingAs($this->user)->post(
        $this->url."/billing/subscribe/{$pro->code}",
        ['return_to' => '/repairs/intake'],
    )->assertRedirect('/repairs/intake');
});
