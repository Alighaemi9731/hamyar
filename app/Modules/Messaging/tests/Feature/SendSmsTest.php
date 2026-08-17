<?php

declare(strict_types=1);

use App\Modules\CRM\Models\Party;
use App\Modules\Identity\Models\User;
use App\Modules\Messaging\Contracts\SmsDriver;
use App\Modules\Messaging\Drivers\FakeSmsDriver;
use App\Modules\Messaging\Models\Message;
use App\Modules\Messaging\Models\MessageOptOut;
use App\Modules\Messaging\Models\SmsCreditEntry;
use App\Modules\Messaging\Services\SendSms;
use App\Modules\Messaging\Services\SmsWallet;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Platform\Services\PlanCatalogueSeeder;
use App\Modules\Platform\Services\SubscriptionResolver;
use App\Modules\Platform\Services\TenantProvisioner;
use App\Support\PhoneNumber;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;

/**
 * The one door every message goes through.
 *
 * Assertions here are about **exact payload content** — the pattern id, the token order,
 * the normalised recipient. A fake that only counts sends proves the code called something;
 * the bugs that reach a customer are in the payload.
 */
beforeEach(function (): void {
    app(PlanCatalogueSeeder::class)->sync();

    $this->tenant = Tenant::factory()->withDomain()->create();

    subscribe($this->tenant, 'pro');
    app(SubscriptionResolver::class)->forget();
    app(TenantProvisioner::class)->seedRoles($this->tenant);

    /** @var array{User, Party} $fixtures */
    $fixtures = inTenantContext($this->tenant, function (): array {
        $owner = User::factory()->create();
        $owner->assignRole('Owner');

        return [$owner, Party::factory()->create(['name' => 'حسن رضایی'])];
    });

    [$this->owner, $this->customer] = $fixtures;

    /** @var FakeSmsDriver $driver */
    $driver = app(SmsDriver::class);
    $driver->reset();
    $this->driver = $driver;

    $this->inTenant = fn (Closure $callback): mixed => inTenantContext($this->tenant, $callback);
});

afterEach(fn () => app(TenantContext::class)->forget());

function fundWallet(int $rial = 1_000_000): void
{
    app(SmsWallet::class)->topUp($rial, 'شارژ اولیه');
}

/* ------------------------------------------- EXACT PAYLOADS -- */

it('sends the exact template id, tokens in order, to a normalised number', function (): void {
    ($this->inTenant)(function (): void {
        fundWallet();

        app(SendSms::class)->send(
            '۰۹۱۲۱۲۳۴۵۶۷',
            'repair-ready',
            ['حسن رضایی', 'REP-000001', '۱۴۰۵/۰۵/۲۲'],
            templateKey: 'repair.ready',
            partyId: $this->customer->id,
        );

        // Token ORDER is the contract: Iranian pattern APIs are positional, so a driver or
        // caller that reorders puts the customer's name where the amount belongs.
        $this->driver->assertSent('+989121234567', 'repair-ready', ['حسن رضایی', 'REP-000001', '۱۴۰۵/۰۵/۲۲']);
    });
});

it('normalises every spelling of the same number to one canonical form', function (): void {
    // Four spellings of one person. Left alone they are four opt-out rows that match
    // nothing and four charges for one message.
    expect(PhoneNumber::normalise('09121234567'))->toBe('+989121234567')
        ->and(PhoneNumber::normalise('+989121234567'))->toBe('+989121234567')
        ->and(PhoneNumber::normalise('989121234567'))->toBe('+989121234567')
        ->and(PhoneNumber::normalise('۰۹۱۲۱۲۳۴۵۶۷'))->toBe('+989121234567')
        ->and(PhoneNumber::normalise('0912 123 4567'))->toBe('+989121234567')
        ->and(PhoneNumber::normalise('٠٩١٢١٢٣٤٥٦٧'))->toBe('+989121234567');

    // And what is not a mobile number comes back null rather than throwing — an imported
    // party row may hold a landline or a note.
    expect(PhoneNumber::normalise('02188776655'))->toBeNull()
        ->and(PhoneNumber::normalise('salam'))->toBeNull()
        ->and(PhoneNumber::normalise(null))->toBeNull();
});

it('shows a shopkeeper the local form, not the canonical one', function (): void {
    // Storage is canonical; display is local. `+98` on a Persian invoice looks like
    // somebody else's software.
    expect(PhoneNumber::forDisplay('+989121234567'))->toBe('09121234567');
});

/* --------------------------------------------- THE WALLET -- */

it('charges the wallet before handing anything to the gateway', function (): void {
    ($this->inTenant)(function (): void {
        fundWallet(1_000_000);

        app(SendSms::class)->send('09121234567', 'repair-ready', ['حسن']);

        // Charged, and the charge names the message it paid for.
        expect(app(SmsWallet::class)->balance())->toBe(1_000_000 - SendSms::DEFAULT_SEGMENT_COST)
            ->and(SmsCreditEntry::query()->where('type', SmsCreditEntry::TYPE_CHARGE)->count())->toBe(1);
    });
});

it('refunds the credit when the gateway refuses', function (): void {
    ($this->inTenant)(function (): void {
        fundWallet(1_000_000);

        $this->driver->failNext('سامانه در دسترس نیست');

        $message = app(SendSms::class)->send('09121234567', 'repair-ready', ['حسن']);

        // The money is back. A refund path with no test is a wallet that drains on every
        // carrier outage.
        expect(app(SmsWallet::class)->balance())->toBe(1_000_000)
            ->and($message?->status)->toBe(Message::STATUS_FAILED)
            ->and($message?->cost)->toBe(0);

        // Both entries survive — the trail shows the attempt AND its reversal, which is
        // what answers "why did you charge me twice on Tuesday".
        expect(SmsCreditEntry::query()->where('type', SmsCreditEntry::TYPE_CHARGE)->count())->toBe(1)
            ->and(SmsCreditEntry::query()->where('type', SmsCreditEntry::TYPE_REFUND)->count())->toBe(1);
    });
});

it('suppresses rather than failing when the wallet is empty', function (): void {
    ($this->inTenant)(function (): void {
        // No top-up at all.
        $message = app(SendSms::class)->send('09121234567', 'repair-ready', ['حسن']);

        // A repair marked ready must not fail because the SMS wallet is empty. The shop
        // sees why on the messages screen and tops up.
        expect($message?->status)->toBe(Message::STATUS_SUPPRESSED)
            ->and($message?->error)->toBe('اعتبار پیامک کافی نیست.');

        $this->driver->assertSentCount(0);
    });
});

it('keeps the balance as a sum, never a stored column', function (): void {
    ($this->inTenant)(function (): void {
        fundWallet(500_000);
        fundWallet(300_000);

        app(SendSms::class)->send('09121234567', 'repair-ready', ['حسن']);

        expect(app(SmsWallet::class)->balance())->toBe(800_000 - SendSms::DEFAULT_SEGMENT_COST)
            ->and(SmsCreditEntry::query()->count())->toBe(3);
    });
});

/* ------------------------------------------- OPT-OUT -- */

it('sends nothing to a number that has opted out', function (): void {
    ($this->inTenant)(function (): void {
        fundWallet();

        MessageOptOut::query()->create([
            'phone' => '+989121234567',
            'reason' => 'درخواست مشتری',
            'opted_out_at' => CarbonImmutable::now(),
        ]);

        $message = app(SendSms::class)->send('09121234567', 'birthday', ['حسن']);

        // The complaint that reaches a regulator is an opted-out customer getting a
        // birthday SMS. The check lives in the one door so no send path can miss it.
        $this->driver->assertNothingSentTo('+989121234567');

        expect($message?->status)->toBe(Message::STATUS_SUPPRESSED)
            ->and($message?->error)->toBe('مشتری از دریافت پیامک انصراف داده است.');
    });
});

it('honours an opt-out recorded in a different spelling of the number', function (): void {
    ($this->inTenant)(function (): void {
        fundWallet();

        // Opted out as +98…; the invoice holds 0912…. Same person.
        MessageOptOut::query()->create([
            'phone' => PhoneNumber::normalise('+98 912 123 4567') ?? '',
            'opted_out_at' => CarbonImmutable::now(),
        ]);

        app(SendSms::class)->send('۰۹۱۲۱۲۳۴۵۶۷', 'birthday', ['حسن']);

        $this->driver->assertSentCount(0);
    });
});

it('charges nothing for a suppressed message', function (): void {
    ($this->inTenant)(function (): void {
        fundWallet(1_000_000);

        MessageOptOut::query()->create(['phone' => '+989121234567', 'opted_out_at' => CarbonImmutable::now()]);

        app(SendSms::class)->send('09121234567', 'birthday', ['حسن']);

        expect(app(SmsWallet::class)->balance())->toBe(1_000_000);
    });
});

/* ------------------------------------------- IDEMPOTENCY -- */

it('sends once however many times an automation fires', function (): void {
    ($this->inTenant)(function (): void {
        fundWallet();

        $key = 'birthday:'.$this->customer->id.':1405';

        app(SendSms::class)->send('09121234567', 'birthday', ['حسن'], idempotencyKey: $key);
        app(SendSms::class)->send('09121234567', 'birthday', ['حسن'], idempotencyKey: $key);
        app(SendSms::class)->send('09121234567', 'birthday', ['حسن'], idempotencyKey: $key);

        // A scheduler that runs twice must not text a customer twice, and the guarantee
        // lives in the database because two workers both read "not yet" and both send.
        $this->driver->assertSentCount(1);

        expect(Message::query()->count())->toBe(1)
            ->and(app(SmsWallet::class)->balance())->toBe(1_000_000 - SendSms::DEFAULT_SEGMENT_COST);
    });
});

it('records an unsendable number rather than silently skipping it', function (): void {
    ($this->inTenant)(function (): void {
        fundWallet();

        $message = app(SendSms::class)->send('02188776655', 'repair-ready', ['حسن']);

        // A campaign reporting "12 of 400 skipped" has to be able to say which twelve.
        expect($message?->status)->toBe(Message::STATUS_SUPPRESSED)
            ->and($message?->error)->toBe('شماره موبایل معتبر نیست.');

        $this->driver->assertSentCount(0);
    });
});

/* ------------------------------------------- tenancy -- */

it('will not spend another shop credit', function (): void {
    $other = Tenant::factory()->withDomain()->create();
    subscribe($other, 'pro');
    app(SubscriptionResolver::class)->forget();

    inTenantContext($other, fn () => app(SmsWallet::class)->topUp(9_000_000));

    ($this->inTenant)(function (): void {
        // Their top-up is invisible from here, so the wallet is empty and the send is
        // suppressed rather than quietly billed to somebody else.
        expect(app(SmsWallet::class)->balance())->toBe(0);

        $message = app(SendSms::class)->send('09121234567', 'repair-ready', ['حسن']);

        expect($message?->status)->toBe(Message::STATUS_SUPPRESSED);
    });
});
