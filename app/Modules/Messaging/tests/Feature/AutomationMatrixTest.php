<?php

declare(strict_types=1);

use App\Modules\CRM\Models\Party;
use App\Modules\CRM\Models\PartyContact;
use App\Modules\Identity\Models\User;
use App\Modules\Messaging\Contracts\SmsDriver;
use App\Modules\Messaging\Drivers\FakeSmsDriver;
use App\Modules\Messaging\Enums\AutomationKey;
use App\Modules\Messaging\Models\Message;
use App\Modules\Messaging\Models\MessageOptOut;
use App\Modules\Messaging\Models\MessageTemplate;
use App\Modules\Messaging\Services\Automations;
use App\Modules\Messaging\Services\SmsWallet;
use App\Modules\Messaging\Services\TemplateRenderer;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Platform\Services\PlanCatalogueSeeder;
use App\Modules\Platform\Services\SubscriptionResolver;
use App\Modules\Platform\Services\TenantProvisioner;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;

/**
 * The automation matrix: what fires, what does not, and what must never.
 *
 * Two rules drive almost every test here:
 *
 * 1. **Everything is off until a shop says otherwise.** A tenant that has never opened the
 *    messaging screen must not wake up to sent messages and a drained wallet.
 * 2. **Opt-out is asserted per automation, not once at the door.** The door is where the
 *    guarantee lives, and per-automation tests are how we know no automation found a way
 *    round it. An opted-out customer receiving a birthday SMS is the complaint that reaches
 *    a regulator, and it happens because somebody added a send path that did not know.
 */
beforeEach(function (): void {
    app(PlanCatalogueSeeder::class)->sync();

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

    $this->inTenant = fn (Closure $callback): mixed => inTenantContext($this->tenant, $callback);
});

afterEach(fn () => app(TenantContext::class)->forget());

/**
 * Switch an automation on and give it a template pointing at a registered pattern.
 */
function enableAutomation(AutomationKey $key, string $body = 'سلام {name}'): void
{
    /** @var Tenant $tenant */
    $tenant = test()->tenant;

    /** @var array<string, mixed> $settings */
    $settings = is_array($tenant->settings) ? $tenant->settings : [];

    /** @var array<string, mixed> $messaging */
    $messaging = is_array($settings['messaging'] ?? null) ? $settings['messaging'] : [];

    /** @var array<string, bool> $automations */
    $automations = is_array($messaging['automations'] ?? null) ? $messaging['automations'] : [];

    $automations[$key->value] = true;
    $messaging['automations'] = $automations;
    $settings['messaging'] = $messaging;

    $tenant->forceFill(['settings' => $settings])->save();

    app(TenantContext::class)->forget();

    inTenantContext($tenant, fn () => MessageTemplate::query()->updateOrCreate(
        ['automation_key' => $key->value],
        ['body' => $body, 'provider_template_id' => 'pattern-'.$key->value, 'is_active' => true],
    ));
}

/* ================== EVERYTHING IS OFF UNTIL A SHOP SAYS SO ================== */

it('fires nothing for a tenant that has never configured messaging', function (): void {
    ($this->inTenant)(function (): void {
        app(SmsWallet::class)->topUp(1_000_000);

        // A template exists but the toggle does not. The shop still gets nothing.
        MessageTemplate::query()->create([
            'automation_key' => AutomationKey::RepairReady->value,
            'body' => 'سلام {name}',
            'provider_template_id' => 'pattern-ready',
        ]);

        foreach (AutomationKey::cases() as $key) {
            $fired = app(Automations::class)->fire($key, $this->customer, ['name' => 'حسن']);

            expect($fired)->toBeFalse();
        }

        // The single most damaging thing this module could do is switch itself on for
        // every existing tenant the day it deploys.
        expect(Message::query()->count())->toBe(0)
            ->and(app(SmsWallet::class)->balance())->toBe(1_000_000);
    });
});

it('treats a non-boolean toggle as off', function (): void {
    $this->tenant->forceFill(['settings' => [
        'messaging' => ['automations' => [AutomationKey::Birthday->value => 'yes']],
    ]])->save();
    app(TenantContext::class)->forget();

    ($this->inTenant)(function (): void {
        app(SmsWallet::class)->topUp(1_000_000);

        MessageTemplate::query()->create([
            'automation_key' => AutomationKey::Birthday->value,
            'body' => 'تولدت مبارک {name}',
            'provider_template_id' => 'pattern-birthday',
        ]);

        // 'yes' is a guess about what somebody meant. Guessing wrong here sends messages
        // the shop never authorised, so only an explicit true counts.
        expect(app(Automations::class)->fire(AutomationKey::Birthday, $this->customer, ['name' => 'حسن']))
            ->toBeFalse();
    });
});

it('sends nothing when the template has no registered pattern', function (): void {
    ($this->inTenant)(function (): void {
        app(SmsWallet::class)->topUp(1_000_000);
    });

    enableAutomation(AutomationKey::RepairReady);

    ($this->inTenant)(function (): void {
        MessageTemplate::query()->where('automation_key', AutomationKey::RepairReady->value)
            ->update(['provider_template_id' => null]);

        // Free text to a number on the national do-not-disturb list is dropped by the
        // carrier without an error, so "sent" would be a lie the shop learns from a
        // customer.
        expect(app(Automations::class)->fire(AutomationKey::RepairReady, $this->customer, ['name' => 'حسن']))
            ->toBeFalse();
    });
});

/* ====================== OPT-OUT, PER AUTOMATION ====================== */

it('suppresses every automation for an opted-out number', function (AutomationKey $key): void {
    enableAutomation($key, 'سلام {name}');

    ($this->inTenant)(function () use ($key): void {
        app(SmsWallet::class)->topUp(1_000_000);

        MessageOptOut::query()->create([
            'phone' => '+989121234567',
            'opted_out_at' => CarbonImmutable::now(),
        ]);

        app(Automations::class)->fire($key, $this->customer, ['name' => 'حسن']);
    });

    // The job was queued — the gate does not know about opt-out, deliberately — and the
    // door refused it. Asserted for EVERY automation, because the guarantee living in one
    // place is only worth anything if no automation has found a way round it.
    Illuminate\Support\Facades\Artisan::call('queue:work', [
        '--queue' => 'sms', '--stop-when-empty' => true, '--tries' => 1, '--memory' => 4096,
    ]);

    $this->driver->assertNothingSentTo('+989121234567');

    ($this->inTenant)(function (): void {
        expect(Message::query()->firstOrFail()->status)->toBe(Message::STATUS_SUPPRESSED)
            // And it cost nothing.
            ->and(app(SmsWallet::class)->balance())->toBe(1_000_000);
    });
})->with([
    'invoice issued' => AutomationKey::InvoiceIssued,
    'repair status' => AutomationKey::RepairStatusChanged,
    'repair ready' => AutomationKey::RepairReady,
    'abandoned step' => AutomationKey::RepairAbandonedStep,
    'installment due soon' => AutomationKey::InstallmentDueSoon,
    'installment due today' => AutomationKey::InstallmentDueToday,
    'installment overdue' => AutomationKey::InstallmentOverdue,
    'cheque due soon' => AutomationKey::ChequeDueSoon,
    'birthday' => AutomationKey::Birthday,
]);

/* ====================== TEMPLATE RENDERING ====================== */

it('orders tokens by where the variables appear in the sentence', function (): void {
    $renderer = app(TemplateRenderer::class);

    $body = 'سلام {name}، دستگاه {device} با کد {ticket_code} آماده است.';

    // Position in the sentence IS position on the wire. Reordering the sentence reorders
    // the tokens — the sharp edge of pattern sends, and it cannot be designed away.
    expect($renderer->variablesIn($body))->toBe(['name', 'device', 'ticket_code'])
        ->and($renderer->tokensFor($body, [
            'ticket_code' => 'REP-000001',
            'device' => 'گلکسی S23',
            'name' => 'حسن',
        ]))->toBe(['حسن', 'گلکسی S23', 'REP-000001']);
});

it('counts a repeated variable once, keeping its first position', function (): void {
    $renderer = app(TemplateRenderer::class);

    // «{name} … {name}» is one token the pattern uses twice, not two tokens.
    expect($renderer->variablesIn('سلام {name}، {device} شما آماده است {name} عزیز'))
        ->toBe(['name', 'device']);
});

it('renders a missing variable as empty rather than as its own name', function (): void {
    // «amount» appearing literally in a customer's message reads as a bug they can see.
    expect(app(TemplateRenderer::class)->preview('مبلغ {amount} تومان', []))
        ->toBe('مبلغ  تومان');
});

it('names variables an automation will never supply', function (): void {
    // The editor refuses to save while this is non-empty: an {amount} in a birthday
    // message is a hole in a sentence somebody receives.
    expect(app(TemplateRenderer::class)->unknownVariables('تولدت مبارک {name}، {amount}', AutomationKey::Birthday))
        ->toBe(['amount']);
});

/* ====================== THE HAPPY PATH ====================== */

it('queues a repair-ready message with the tokens in template order', function (): void {
    enableAutomation(AutomationKey::RepairReady, 'سلام {name}، دستگاه {device} با کد {ticket_code} آماده است.');

    ($this->inTenant)(function (): void {
        app(SmsWallet::class)->topUp(1_000_000);

        app(Automations::class)->fire(AutomationKey::RepairReady, $this->customer, [
            'name' => 'حسن رضایی',
            'device' => 'گلکسی S23',
            'ticket_code' => 'REP-000001',
        ]);
    });

    Illuminate\Support\Facades\Artisan::call('queue:work', [
        '--queue' => 'sms', '--stop-when-empty' => true, '--tries' => 1, '--memory' => 4096,
    ]);

    $this->driver->assertSent(
        '+989121234567',
        'pattern-repair.ready',
        ['حسن رضایی', 'گلکسی S23', 'REP-000001'],
    );
});
