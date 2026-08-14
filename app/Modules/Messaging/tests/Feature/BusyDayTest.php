<?php

declare(strict_types=1);

use App\Modules\CRM\Models\Party;
use App\Modules\CRM\Models\PartyContact;
use App\Modules\Identity\Models\User;
use App\Modules\Installments\Models\InstallmentPlan;
use App\Modules\Installments\Models\InstallmentRow;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Messaging\Contracts\SmsDriver;
use App\Modules\Messaging\Drivers\FakeSmsDriver;
use App\Modules\Messaging\Enums\AutomationKey;
use App\Modules\Messaging\Models\Message;
use App\Modules\Messaging\Models\MessageOptOut;
use App\Modules\Messaging\Models\MessageTemplate;
use App\Modules\Messaging\Services\DailyMessagingSweep;
use App\Modules\Messaging\Services\SmsWallet;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Platform\Services\PlanCatalogueSeeder;
use App\Modules\Platform\Services\SubscriptionResolver;
use App\Modules\Platform\Services\TenantProvisioner;
use App\Modules\Repairs\Enums\TicketStatus;
use App\Modules\Repairs\Services\TicketIntake;
use App\Modules\Repairs\Services\TicketStateMachine;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;

/**
 * The Phase 8 Definition of Done: one busy day, and the log that has to survive it.
 *
 * ## The scenario
 *
 * A customer whose repair is finished, whose instalment falls due, and whose birthday it
 * is — all on the same day. Plus a second customer with the same three things who has
 * opted out. The sweep runs three times, because a scheduler does.
 *
 * ## What is asserted, and why each is the one that bites
 *
 * - **No number receives the same event twice.** The sweep runs hourly; without
 *   period-keyed idempotency the customer gets three birthday messages before lunch.
 * - **An opted-out number appears nowhere.** Not in one automation — in none of them. This
 *   is the complaint that reaches a regulator, and it happens because one send path did not
 *   know about the list.
 * - **The wallet is charged once per message actually sent**, and suppressed messages cost
 *   nothing. A shop billed for messages it did not send finds out at reconciliation, which
 *   is months later.
 * - **Three different events to one number is correct.** Three of the SAME event is not.
 *   The distinction is the whole design, and a test that only counted messages per number
 *   would call the correct behaviour a bug.
 */
beforeEach(function (): void {
    app(PlanCatalogueSeeder::class)->sync();

    $this->tenant = Tenant::factory()->withDomain()->create();
    $this->today = CarbonImmutable::parse('2026-08-14 11:00:00');

    subscribe($this->tenant, 'pro');
    app(SubscriptionResolver::class)->forget();
    app(TenantProvisioner::class)->seedRoles($this->tenant);

    // Every automation this day exercises, switched on with a registered pattern.
    $settings = ['messaging' => ['automations' => []]];

    foreach ([AutomationKey::RepairReady, AutomationKey::InstallmentDueToday, AutomationKey::Birthday] as $key) {
        $settings['messaging']['automations'][$key->value] = true;
    }

    // An approval cap, so the repair can reach `ready` without a customer sign-off. The
    // Phase 6 default is zero — every job needs approval — which is correct there and
    // simply in the way here.
    $settings['repairs'] = ['approval_cap' => 999_999_999];

    $this->tenant->forceFill(['settings' => $settings])->save();
    app(TenantContext::class)->forget();

    /** @var array{User, Party, Party, Warehouse} $fixtures */
    $fixtures = inTenantContext($this->tenant, function (): array {
        $owner = User::factory()->create();
        $owner->assignRole('Owner');

        foreach ([
            [AutomationKey::RepairReady, 'سلام {name}، دستگاه {device} آماده است.'],
            [AutomationKey::InstallmentDueToday, 'سلام {name}، قسط {amount} امروز سررسید است.'],
            [AutomationKey::Birthday, 'تولدت مبارک {name}!'],
        ] as [$key, $body]) {
            MessageTemplate::query()->create([
                'automation_key' => $key->value,
                'body' => $body,
                'provider_template_id' => 'pattern-'.$key->value,
            ]);
        }

        $makeCustomer = function (string $name, string $mobile): Party {
            $party = Party::factory()->create([
                'name' => $name,
                // Same day and month as the seeded day, a different year.
                'birthday' => '1990-08-14',
            ]);

            PartyContact::query()->create([
                'party_id' => $party->id,
                'type' => PartyContact::TYPE_MOBILE,
                'value' => $mobile,
                'is_primary' => true,
            ]);

            return $party;
        };

        app(SmsWallet::class)->topUp(10_000_000);

        return [
            $owner,
            $makeCustomer('حسن رضایی', '09121110001'),
            $makeCustomer('مریم احمدی', '09122220002'),
            Warehouse::factory()->create(['is_sellable' => true, 'is_default' => true]),
        ];
    });

    [$this->owner, $this->busy, $this->optedOut, $this->warehouse] = $fixtures;

    // The second customer asked to be left alone. Everything below must respect that,
    // through every automation, not merely the one somebody remembered.
    inTenantContext($this->tenant, fn () => MessageOptOut::query()->create([
        'phone' => '+989122220002',
        'reason' => 'درخواست مشتری',
        'opted_out_at' => CarbonImmutable::now(),
    ]));

    /** @var FakeSmsDriver $driver */
    $driver = app(SmsDriver::class);
    $driver->reset();
    $this->driver = $driver;

    $this->inTenant = fn (Closure $callback): mixed => inTenantContext($this->tenant, $callback);
});

afterEach(fn () => app(TenantContext::class)->forget());

/**
 * Give both customers a repair that finishes today and an instalment that falls due today.
 */
function seedBusyDay(): void
{
    /** @var Warehouse $warehouse */
    $warehouse = test()->warehouse;
    /** @var User $owner */
    $owner = test()->owner;
    /** @var CarbonImmutable $today */
    $today = test()->today;

    foreach ([test()->busy, test()->optedOut] as $party) {
        /** @var Party $party */
        $ticket = app(TicketIntake::class)->take([
            'branch_id' => $warehouse->branch_id,
            'party_id' => $party->id,
            'device_model' => 'گلکسی S23',
            'reported_issue' => 'صفحه شکسته',
            'estimate_amount' => 0,
        ], $owner->id);

        $machine = app(TicketStateMachine::class);

        foreach ([TicketStatus::Diagnosing, TicketStatus::Repairing, TicketStatus::Ready] as $status) {
            $machine->transition($ticket->fresh() ?? $ticket, $status, $owner->id);
        }

        $plan = InstallmentPlan::query()->create([
            'branch_id' => $warehouse->branch_id,
            'party_id' => $party->id,
            'number' => 'INS-'.random_int(100000, 999999),
            'down_payment' => 0,
            'principal' => 60_000_000,
            'profit_percent' => 20,
            'profit_amount' => 12_000_000,
            'total_payable' => 72_000_000,
            'installment_count' => 6,
            'interval_months' => 1,
            'first_due_at' => $today->toDateString(),
            'status' => 'active',
        ]);

        InstallmentRow::query()->create([
            'installment_plan_id' => $plan->id,
            'sequence' => 1,
            'due_at' => $today->startOfDay(),
            'amount' => 12_000_000,
            'status' => InstallmentRow::STATUS_PENDING,
        ]);
    }
}

function drainSms(): void
{
    app(TenantContext::class)->forget();

    Illuminate\Support\Facades\Artisan::call('queue:work', [
        'connection' => 'database',
        '--queue' => 'sms',
        '--stop-when-empty' => true,
        '--tries' => 1,
        '--memory' => 4096,
    ]);
}

/* ============================== THE BUSY DAY ============================== */

it('sends each event once to the busy customer, and nothing at all to the opted-out one', function (): void {
    ($this->inTenant)(fn () => seedBusyDay());

    // The scheduler runs hourly. Three sweeps, one day.
    ($this->inTenant)(function (): void {
        foreach ([0, 1, 2] as $_) {
            app(DailyMessagingSweep::class)->run($this->today);
        }
    });

    drainSms();

    ($this->inTenant)(function (): void {
        $messages = Message::query()->orderBy('id')->get();

        // ── The busy customer: three DIFFERENT events, each exactly once ──
        $busy = $messages->where('to', '+989121110001');

        $keys = $busy->where('status', Message::STATUS_SENT)
            ->pluck('template_key')
            ->sort()
            ->values()
            ->all();

        expect($keys)->toBe([
            AutomationKey::Birthday->value,
            AutomationKey::InstallmentDueToday->value,
            AutomationKey::RepairReady->value,
        ]);

        // Three different events to one number is CORRECT. Three of the same is not — a
        // test that merely counted messages per number would call the right behaviour a bug.
        foreach ($keys as $key) {
            expect($busy->where('template_key', $key)->where('status', Message::STATUS_SENT))
                ->toHaveCount(1);
        }

        // ── The opted-out customer: nothing left the building ──
        $suppressed = $messages->where('to', '+989122220002');

        expect($suppressed->where('status', Message::STATUS_SENT))->toHaveCount(0)
            ->and($suppressed->every(fn (Message $m): bool => $m->status === Message::STATUS_SUPPRESSED))
            ->toBeTrue();
    });

    // And the driver — the last word on what actually went out — agrees.
    $this->driver->assertNothingSentTo('+989122220002');
    $this->driver->assertSentCount(3);
});

it('charges the wallet once per message actually sent', function (): void {
    ($this->inTenant)(fn () => seedBusyDay());

    ($this->inTenant)(function (): void {
        foreach ([0, 1, 2] as $_) {
            app(DailyMessagingSweep::class)->run($this->today);
        }
    });

    drainSms();

    ($this->inTenant)(function (): void {
        $sent = Message::query()->where('status', Message::STATUS_SENT)->count();

        // Suppressed messages cost nothing, and a sweep running three times charges once.
        // A shop billed for messages it did not send finds out at reconciliation, months
        // later, and by then the trust is gone.
        expect($sent)->toBe(3)
            ->and(app(SmsWallet::class)->balance())
            ->toBe(10_000_000 - 3 * App\Modules\Messaging\Services\SendSms::DEFAULT_SEGMENT_COST);
    });
});

it('holds everything back outside working hours', function (): void {
    ($this->inTenant)(fn () => seedBusyDay());

    // 3am. A birthday greeting at this hour is not a kindness, and neither is a due-date
    // reminder — but a repair marked ready still texts, because that is event-driven and
    // the customer is waiting for exactly that message.
    ($this->inTenant)(function (): void {
        $result = app(DailyMessagingSweep::class)->run($this->today->setTime(3, 0));

        expect($result['queued'])->toBe(0);
    });

    drainSms();

    ($this->inTenant)(function (): void {
        expect(Message::query()->where('template_key', AutomationKey::Birthday->value)->count())->toBe(0);
    });
});

it('still sends the next morning — a quiet sweep defers rather than skips', function (): void {
    ($this->inTenant)(fn () => seedBusyDay());

    ($this->inTenant)(function (): void {
        // 3am: nothing.
        app(DailyMessagingSweep::class)->run($this->today->setTime(3, 0));
        // 11am the same day: the period key is the DATE, so the same period is still owed.
        app(DailyMessagingSweep::class)->run($this->today->setTime(11, 0));
    });

    drainSms();

    ($this->inTenant)(function (): void {
        expect(Message::query()->where('template_key', AutomationKey::Birthday->value)
            ->where('status', Message::STATUS_SENT)->count())->toBe(1);
    });
});
