<?php

declare(strict_types=1);

use App\Modules\CRM\Models\Party;
use App\Modules\CRM\Models\PartyContact;
use App\Modules\Installments\Models\InstallmentPlan;
use App\Modules\Installments\Models\InstallmentRow;
use App\Modules\Inventory\Models\Branch;
use App\Modules\Messaging\Contracts\SmsDriver;
use App\Modules\Messaging\Drivers\FakeSmsDriver;
use App\Modules\Messaging\Enums\AutomationKey;
use App\Modules\Messaging\Models\Message;
use App\Modules\Messaging\Models\MessageTemplate;
use App\Modules\Messaging\Services\Automations;
use App\Modules\Messaging\Services\DailyMessagingSweep;
use App\Modules\Messaging\Services\SmsWallet;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Platform\Services\PlanCatalogueSeeder;
use App\Modules\Platform\Services\SubscriptionResolver;
use App\Support\Settings\ShopSettings;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Exceptions;

/**
 * `messaging:sweep` — the machinery behind the five switches on the messaging screen.
 *
 * ## What was wrong, and what each test below pins
 *
 * - **Nothing ran the sweep.** `DailyMessagingSweep` was tested by calling it directly, so
 *   every test was green while no shop in production was ever reminded of anything. These
 *   tests go through the artisan command and the schedule, which is the path that was
 *   missing.
 * - **It ran on the UTC clock.** Quiet hours are a shopkeeper's wall clock, and between
 *   00:00 and 03:30 in Tehran the UTC date is still yesterday's. The frozen instants below
 *   are each chosen so that UTC and Tehran give DIFFERENT answers — an instant where they
 *   agree would pass against the bug as well as the fix.
 * - **"Due today" went out the day before.** An instalment's `due_at` is stored as the
 *   instant a Tehran day begins, which is 20:30 UTC the previous evening. The fixtures here
 *   store it that way, as the plan screen does — `BusyDayTest` stores midnight UTC, which
 *   is why it never saw this.
 */
beforeEach(function (): void {
    // Pinned rather than inherited: every instant below is chosen against +03:30.
    config(['app.display_timezone' => 'Asia/Tehran']);

    // 2026-08-14 is ۲۳ مرداد ۱۴۰۵. 08:00 UTC is 11:30 in Tehran — open by default, and the
    // same calendar date on both clocks, so a test that moves the clock says why it moved.
    // Set before anything is provisioned, so the subscriptions cover the frozen day.
    $this->travelTo(CarbonImmutable::parse('2026-08-14 08:00:00', 'UTC'));

    app(PlanCatalogueSeeder::class)->sync();

    /** @var FakeSmsDriver $driver */
    $driver = app(SmsDriver::class);
    $driver->reset();
    $this->driver = $driver;
});

afterEach(fn () => app(TenantContext::class)->forget());

/**
 * A shop on `pro`, with credit, a registered template for every swept automation, and only
 * the given automations switched on — so on or off is decided by the switch and nothing
 * else.
 *
 * @param  list<AutomationKey>  $on
 * @param  array<string, int>  $quiet  `quiet_until_hour` / `quiet_from_hour`
 */
function sweepShop(array $on, array $quiet = [], ?Tenant $tenant = null): Tenant
{
    $tenant ??= Tenant::factory()->withDomain()->create();

    subscribe($tenant, 'pro');
    app(SubscriptionResolver::class)->forget();

    $automations = [];

    foreach ($on as $key) {
        $automations[$key->value] = true;
    }

    $tenant->forceFill(['settings' => ['messaging' => ['automations' => $automations, ...$quiet]]])->save();
    app(TenantContext::class)->forget();

    inTenantContext($tenant, function (): void {
        foreach ([
            AutomationKey::InstallmentDueSoon,
            AutomationKey::InstallmentDueToday,
            AutomationKey::InstallmentOverdue,
            AutomationKey::ChequeDueSoon,
            AutomationKey::Birthday,
        ] as $key) {
            MessageTemplate::query()->updateOrCreate(
                ['automation_key' => $key->value],
                ['body' => 'سلام {name}', 'provider_template_id' => 'pattern-'.$key->value, 'is_active' => true],
            );
        }

        app(SmsWallet::class)->topUp(10_000_000);
    });

    return $tenant;
}

/**
 * Switch one automation on for a shop that already exists, as the settings screen does.
 */
function sweepSwitchOn(Tenant $tenant, AutomationKey $key): void
{
    $tenant->refresh();

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
}

function sweepCustomer(Tenant $tenant, string $mobile, ?string $birthday = null): Party
{
    // `runFor()` rather than `inTenantContext()`: it is generic over the callback's return,
    // so the helper's own return type holds without a cast.
    return app(TenantContext::class)->runFor($tenant, function () use ($mobile, $birthday): Party {
        $party = Party::factory()->create(['birthday' => $birthday]);

        PartyContact::query()->create([
            'party_id' => $party->id,
            'type' => PartyContact::TYPE_MOBILE,
            'value' => $mobile,
            'is_primary' => true,
        ]);

        return $party;
    });
}

/**
 * The instant a Tehran calendar day begins, in UTC — how `Jalali::startOfDay()` stores a
 * due date picked on the plan screen.
 */
function tehranMidnight(string $date): CarbonImmutable
{
    return CarbonImmutable::parse($date.' 00:00:00', 'Asia/Tehran')->utc();
}

function sweepInstalment(Tenant $tenant, Party $party, CarbonImmutable $dueAt): InstallmentRow
{
    return app(TenantContext::class)->runFor($tenant, function () use ($party, $dueAt): InstallmentRow {
        $branch = Branch::factory()->create();

        $plan = InstallmentPlan::query()->create([
            'branch_id' => $branch->id,
            'party_id' => $party->id,
            'number' => 'INS-'.random_int(100000, 999999),
            'down_payment' => 0,
            'principal' => 10_000_000,
            'profit_percent' => 0,
            'profit_amount' => 0,
            'total_payable' => 10_000_000,
            'installment_count' => 1,
            'interval_months' => 1,
            'first_due_at' => $dueAt,
            'status' => 'active',
        ]);

        return InstallmentRow::query()->create([
            'installment_plan_id' => $plan->id,
            'sequence' => 1,
            'due_at' => $dueAt,
            'amount' => 10_000_000,
            'status' => InstallmentRow::STATUS_PENDING,
        ]);
    });
}

/**
 * @return Collection<int, Message>
 */
function sweptMessages(Tenant $tenant): Collection
{
    return app(TenantContext::class)->runFor($tenant, fn (): Collection => Message::query()->orderBy('id')->get()->toBase());
}

/* ================================ THE TENANT LOOP ================================ */

it('sweeps every usable shop, each against its own customers and nobody else\'s', function (): void {
    $alpha = sweepShop([AutomationKey::InstallmentDueToday, AutomationKey::Birthday]);
    $beta = sweepShop([AutomationKey::InstallmentDueToday, AutomationKey::Birthday]);
    $suspended = sweepShop([AutomationKey::Birthday], tenant: Tenant::factory()->suspended()->withDomain()->create());

    $alphaCustomer = sweepCustomer($alpha, '09121110001', birthday: '1990-08-14');
    sweepInstalment($alpha, $alphaCustomer, tehranMidnight('2026-08-14'));

    sweepCustomer($beta, '09123330003', birthday: '1985-08-14');
    sweepCustomer($suspended, '09124440004', birthday: '1980-08-14');

    $this->artisan('messaging:sweep')->assertSuccessful();

    $alphaLog = sweptMessages($alpha);
    $betaLog = sweptMessages($beta);

    expect($alphaLog->pluck('template_key')->sort()->values()->all())->toBe([
        AutomationKey::Birthday->value,
        AutomationKey::InstallmentDueToday->value,
    ])
        ->and($alphaLog->pluck('to')->unique()->values()->all())->toBe(['+989121110001'])
        ->and($alphaLog->every(fn (Message $m): bool => $m->status === Message::STATUS_SENT))->toBeTrue();

    // Beta has the instalment reminder switched on, with a template, and still gets no
    // instalment message: the only instalment due in the building is Alpha's, and RLS is
    // what keeps it there while the loop runs as Beta.
    expect($betaLog->pluck('template_key')->all())->toBe([AutomationKey::Birthday->value])
        ->and($betaLog->pluck('to')->all())->toBe(['+989123330003']);

    // A suspended shop is skipped on an unattended run, switch or no switch.
    expect(sweptMessages($suspended))->toHaveCount(0);
    $this->driver->assertNothingSentTo('+989124440004');

    $this->driver->assertSentCount(3);

    // And the console process is not left pinned to the last shop it swept.
    expect(app(TenantContext::class)->id())->toBeNull();
})->group('isolation');

it('keeps sweeping the other shops when one throws, and reports the failure', function (): void {
    Exceptions::fake();

    $broken = sweepShop([AutomationKey::Birthday]);
    $healthy = sweepShop([AutomationKey::Birthday]);

    sweepCustomer($broken, '09121110001', birthday: '1990-08-14');
    sweepCustomer($healthy, '09123330003', birthday: '1990-08-14');

    // The broken shop is created first, and tenants are swept in id order, so it throws
    // BEFORE the healthy one is reached — which is the order that would lose it.
    $brokenId = $broken->getKey();

    app()->bind(DailyMessagingSweep::class, function () use ($brokenId): DailyMessagingSweep {
        if (app(TenantContext::class)->id() === $brokenId) {
            throw new RuntimeException('a shop whose sweep cannot run');
        }

        return new DailyMessagingSweep(app(Automations::class), app(ShopSettings::class));
    });

    // Non-zero, so the scheduler's log shows a failed run rather than a clean one.
    $this->artisan('messaging:sweep')->assertFailed();

    expect(sweptMessages($healthy))->toHaveCount(1)
        ->and(sweptMessages($broken))->toHaveCount(0);

    // Reported to the handler, not just printed: a scheduled run's console output is read
    // by nobody.
    Exceptions::assertReported(RuntimeException::class);
});

/* ============================ THE SWITCH, AND RUNNING TWICE ============================ */

it('sends nothing while the switch is off, one message once it is on, and still one after the next hourly run', function (): void {
    $shop = sweepShop([]);
    $customer = sweepCustomer($shop, '09121110001');
    $row = sweepInstalment($shop, $customer, tehranMidnight('2026-08-14'));

    // Off — the default for every shop that has never opened the messaging screen.
    $this->artisan('messaging:sweep')->assertSuccessful();

    expect(sweptMessages($shop))->toHaveCount(0);

    sweepSwitchOn($shop, AutomationKey::InstallmentDueToday);

    $this->artisan('messaging:sweep')->assertSuccessful();

    // The scheduler's next run, an hour later on the same Tehran day.
    $this->travelTo(CarbonImmutable::parse('2026-08-14 09:00:00', 'UTC'));
    $this->artisan('messaging:sweep')->assertSuccessful();

    $log = sweptMessages($shop);

    expect($log)->toHaveCount(1)
        ->and($log->first()?->status)->toBe(Message::STATUS_SENT)
        // The period key from docs/specs/treasury.md: the Jalali DATE, in Latin digits.
        ->and($log->first()?->idempotency_key)->toBe(AutomationKey::InstallmentDueToday->value.':'.$row->id.':1405-05-23');

    $this->driver->assertSentCount(1);
});

/* =============================== THE SHOP'S CLOCK =============================== */

it('holds back when Tehran is past closing, though the UTC hour is inside the window', function (): void {
    $shop = sweepShop([AutomationKey::Birthday]);
    sweepCustomer($shop, '09121110001', birthday: '1990-08-14');

    // 18:00 UTC is 21:30 in Tehran — past the default 21:00. On the UTC clock the hour is
    // 18, well inside a 9→21 window, and that is the hour the old sweep texted customers.
    $this->travelTo(CarbonImmutable::parse('2026-08-14 18:00:00', 'UTC'));

    $this->artisan('messaging:sweep')->assertSuccessful();

    expect(sweptMessages($shop))->toHaveCount(0);
});

it('sends once Tehran has opened, though the UTC hour is still before the window', function (): void {
    $shop = sweepShop([AutomationKey::Birthday]);
    sweepCustomer($shop, '09121110001', birthday: '1990-08-14');

    // 06:00 UTC is 09:30 in Tehran — half an hour after the default 09:00. On the UTC clock
    // the hour is 6, and the old sweep would have waited until 12:30 Tehran to send.
    $this->travelTo(CarbonImmutable::parse('2026-08-14 06:00:00', 'UTC'));

    $this->artisan('messaging:sweep')->assertSuccessful();

    expect(sweptMessages($shop))->toHaveCount(1);
});

it('sweeps as Tehran\'s date once Tehran is past midnight and UTC is not', function (): void {
    // Open all night, so the date is what is being tested and not the hour.
    $shop = sweepShop(
        [AutomationKey::Birthday, AutomationKey::InstallmentDueToday],
        ['quiet_until_hour' => 0, 'quiet_from_hour' => 23],
    );

    $tehranToday = sweepCustomer($shop, '09121110001', birthday: '1990-08-15');
    sweepCustomer($shop, '09122220002', birthday: '1990-08-14');
    $row = sweepInstalment($shop, $tehranToday, tehranMidnight('2026-08-15'));

    // 22:00 UTC on the 14th is 01:30 on the 15th in Tehran — ۲۴ مرداد ۱۴۰۵. The UTC date
    // is still the 14th, whose birthday customer the old sweep would have greeted.
    $this->travelTo(CarbonImmutable::parse('2026-08-14 22:00:00', 'UTC'));

    $this->artisan('messaging:sweep')->assertSuccessful();

    expect(sweptMessages($shop)->pluck('idempotency_key')->sort()->values()->all())->toBe([
        'birthday:'.$tehranToday->id.':1405',
        AutomationKey::InstallmentDueToday->value.':'.$row->id.':1405-05-24',
    ]);

    $this->driver->assertNothingSentTo('+989122220002');
});

it('does not send "due today" the day before an instalment falls due', function (): void {
    $shop = sweepShop([AutomationKey::InstallmentDueToday, AutomationKey::InstallmentOverdue]);
    $customer = sweepCustomer($shop, '09121110001');

    // Due on the 15th in Tehran, stored as 20:30 UTC on the 14th.
    sweepInstalment($shop, $customer, tehranMidnight('2026-08-15'));

    // 11:30 on the 14th in Tehran: the instalment is due TOMORROW. Read on the UTC clock,
    // `due_at` is "the 14th" and the old sweep sent «امروز سررسید است» a day early.
    $this->artisan('messaging:sweep')->assertSuccessful();

    expect(sweptMessages($shop))->toHaveCount(0);
});

/* ================================= THE SCHEDULE ================================= */

it('is on the schedule every hour, locked against overlap and to one server', function (): void {
    expect(Artisan::all())->toHaveKey('messaging:sweep');

    $events = collect(app(Schedule::class)->events())
        ->filter(fn (Event $event): bool => str_contains((string) $event->command, 'messaging:sweep'))
        ->values();

    expect($events)->toHaveCount(1);

    /** @var Event $event */
    $event = $events->first();

    expect($event->expression)->toBe('0 * * * *')
        ->and($event->withoutOverlapping)->toBeTrue()
        // An hour, not the default day: a killed run must not silence tomorrow morning.
        ->and($event->expiresAt)->toBe(60)
        ->and($event->onOneServer)->toBeTrue();
});
