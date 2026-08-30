<?php

declare(strict_types=1);

use App\Modules\CRM\Models\Party;
use App\Modules\CRM\Models\PartyContact;
use App\Modules\Identity\Models\User;
use App\Modules\Messaging\Contracts\SmsDriver;
use App\Modules\Messaging\Drivers\FakeSmsDriver;
use App\Modules\Messaging\Models\Message;
use App\Modules\Messaging\Services\SendSms;
use App\Modules\Messaging\Services\SmsWallet;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Platform\Services\SubscriptionResolver;
use App\Modules\Platform\Services\TenantProvisioner;
use App\Support\Tenancy\TenantContext;

/**
 * `messaging.sms` — the one metric whose refusal must never throw.
 *
 * ## Why this metric is different from every other one
 *
 * Everywhere else, `QuotaGuard::consume()` throws and the transaction unwinds, because a
 * person is standing at a screen waiting for an answer. Almost nothing that sends an SMS
 * has a person in front of it: a queued automation on a repair status, the nightly reminder
 * sweep, a campaign draining through a throttle. A job that threw on quota would retry,
 * fail, and eventually page somebody — turning «پیامک‌هایتان تمام شد» into an incident.
 *
 * So `SendSms` calls `record()`, which returns a verdict rather than throwing, and a refusal
 * becomes a fifth *suppression reason* beside the four a shop can already read in its own
 * message log. These tests exist to hold that shape in place: the message is still written,
 * still visible, still explains itself in Persian — it simply never leaves.
 *
 * ## The free rung sends zero, and that is the honest number
 *
 * SMS is the only quota that costs the platform cash per unit, which is why Gate 6 set
 * `messaging.sms = 0` on the free plan rather than a small generous figure. A shop on the
 * free rung is not being teased with five messages; it is being told plainly that this one
 * is paid for.
 */
beforeEach(function (): void {
    $this->tenant = Tenant::factory()->withDomain()->create();

    subscribe($this->tenant, 'pro');
    app(SubscriptionResolver::class)->forget();
    app(TenantProvisioner::class)->seedRoles($this->tenant);

    /** @var Party $customer */
    $customer = inTenantContext($this->tenant, function (): Party {
        User::factory()->create()->assignRole('Owner');

        $party = Party::factory()->create(['name' => 'حسن رضایی']);

        PartyContact::query()->create([
            'party_id' => $party->id,
            'type' => PartyContact::TYPE_MOBILE,
            'value' => '09121234567',
            'is_primary' => true,
        ]);

        return $party;
    });

    $this->customer = $customer;

    /** @var FakeSmsDriver $driver */
    $driver = app(SmsDriver::class);
    $driver->reset();
    $this->driver = $driver;
});

afterEach(fn () => app(TenantContext::class)->forget());

/**
 * One message, through the real service, with a wallet that can pay for it.
 */
function sendOne(bool $system = false): ?Message
{
    /** @var Tenant $tenant */
    $tenant = test()->tenant;
    /** @var Party $customer */
    $customer = test()->customer;

    /** @var Message|null $message */
    $message = inTenantContext($tenant, function () use ($customer, $system): ?Message {
        app(SmsWallet::class)->topUp(1_000_000);

        return app(SendSms::class)->send(
            rawPhone: '09121234567',
            templateId: 'pattern-test',
            tokens: ['حسن'],
            partyId: $customer->id,
            systemMessage: $system,
        );
    });

    return $message;
}

it('spends one sms credit per message sent', function (): void {
    $message = sendOne();

    expect($message?->status)->toBe(Message::STATUS_SENT)
        ->and(quotaUsed($this->tenant, 'messaging.sms'))->toBe(1);
});

it('suppresses rather than throws when the monthly credit is gone', function (): void {
    capQuota($this->tenant, 'messaging.sms', 0);

    // The claim is the absence of an exception as much as the presence of a suppression.
    // If this ever throws, an automation somewhere becomes a failing queued job, and a
    // shop finds out it is out of messages from an alert rather than from its own screen.
    $message = sendOne();

    expect($message?->status)->toBe(Message::STATUS_SUPPRESSED)
        ->and($message?->error)->toBe('سهمیهٔ پیامک این ماه تمام شده است.')
        // Written, not silently dropped: a message that never appears anywhere is a shop
        // wondering why a customer was not told, with nothing to read.
        ->and($message?->exists)->toBeTrue();
});

it('does not charge the wallet for a message the quota refused', function (): void {
    capQuota($this->tenant, 'messaging.sms', 0);

    sendOne();

    /** @var int $balance */
    $balance = inTenantContext($this->tenant, fn (): int => app(SmsWallet::class)->balance());

    // The order matters and is deliberate: quota is checked before the wallet, so a shop
    // out of monthly credit is not also billed cash for the message it did not send.
    expect($balance)->toBe(1_000_000)
        ->and($this->driver->sent())->toBeEmpty();
});

it('never meters a system message, and never lets a plan withhold one', function (): void {
    capQuota($this->tenant, 'messaging.sms', 0);

    $message = sendOne(system: true);

    // A message telling somebody their credit is gone must not itself be refused for want
    // of credit, and a password reset must never be a thing a plan can withhold. The
    // platform pays for these (Gate 6, item 16).
    expect($message?->status)->toBe(Message::STATUS_SENT)
        ->and(quotaRowExists($this->tenant, 'messaging.sms'))->toBeFalse();
});

it('sends nothing at all on the free rung', function (): void {
    // Move this shop down to the free rung the way a lapse does, rather than by handing
    // it a second subscription — two live rows would make the test depend on which one the
    // resolver picks, which is not what it is about.
    $basic = App\Modules\Platform\Models\Plan::query()->where('code', 'basic')->firstOrFail();

    app(TenantContext::class)->runAsPlatform(fn () => App\Modules\Platform\Models\Subscription::query()
        ->where('tenant_id', $this->tenant->getKey())
        ->update(['plan_id' => $basic->getKey()]));

    app(App\Modules\Platform\Services\Quota\LimitResolver::class)->bump($this->tenant->getKey());

    $message = sendOne();

    // `basic` is free and its `messaging.sms` limit is 0 — not a small number, zero. This
    // asserts the catalogue and the guard agree about that, because a free rung that
    // quietly sent a few would be the platform paying a per-message cost it never priced.
    expect($message?->status)->toBe(Message::STATUS_SUPPRESSED);
});
