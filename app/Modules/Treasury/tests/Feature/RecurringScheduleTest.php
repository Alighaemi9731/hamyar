<?php

declare(strict_types=1);

use App\Modules\CRM\Models\Account;
use App\Modules\CRM\Models\Party;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Treasury\Enums\CashDirection;
use App\Modules\Treasury\Models\CashTransaction;
use App\Modules\Treasury\Models\RecurringTemplate;
use App\Modules\Treasury\Models\RentalContract;
use App\Modules\Treasury\Models\TransactionCategory;
use App\Modules\Treasury\Services\GenerateRecurring;
use App\Modules\Treasury\Services\RecordCashTransaction;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Exceptions;

/**
 * `treasury:generate-recurring` — the machinery behind a recurring template.
 *
 * ## What was wrong
 *
 * `GenerateRecurring` was tested by calling it directly, and was never called by anything
 * else: no schedule entry, no route. Every test was green while no shop's rent, wages or
 * leased-desk income was ever booked. These tests go through the artisan command and the
 * schedule, which is the path that was missing.
 *
 * The clock is frozen at 00:30 on ۱ شهریور ۱۴۰۵ in Tehran — 21:00 UTC on 2026-08-22, when
 * the UTC date is still ۳۱ مرداد — so the run the scheduler makes just after Tehran
 * midnight is the run under test. Shahrivar's rent is due at that minute.
 */
beforeEach(function (): void {
    // Pinned rather than inherited: the frozen instant is chosen against +03:30.
    config(['app.display_timezone' => 'Asia/Tehran']);

    $this->travelTo(CarbonImmutable::parse('2026-08-22 21:00:00', 'UTC'));
});

afterEach(fn () => app(TenantContext::class)->forget());

/**
 * A shop with a till, a rent heading, and rent of `$amount` due on the first of every
 * Jalali month since ۱ مرداد ۱۴۰۵.
 */
function shopPayingMonthlyRent(int $amount, ?Tenant $tenant = null): Tenant
{
    $tenant ??= Tenant::factory()->withDomain()->create();

    inTenantContext($tenant, function () use ($amount): void {
        $till = Account::factory()->create([
            'type' => Account::TYPE_CASH, 'name' => 'صندوق', 'opening_balance' => 900_000_000,
        ]);

        $rent = TransactionCategory::query()->create([
            'account_id' => Account::factory()->create(['type' => Account::TYPE_EXPENSE, 'name' => 'اجاره'])->id,
            'name' => 'اجاره', 'direction' => CashDirection::Expense, 'is_active' => true,
        ]);

        RecurringTemplate::query()->create([
            'transaction_category_id' => $rent->id,
            'account_id' => $till->id,
            'name' => 'اجاره ماهانه',
            'direction' => CashDirection::Expense,
            'amount' => $amount,
            'day_of_month' => 1,
            // ۱ مرداد ۱۴۰۵.
            'starts_on' => '2026-07-23',
        ]);
    });

    return $tenant;
}

/**
 * What a shop has booked, as `generated_key => amount`, read inside that shop.
 *
 * @return array<string, int>
 */
function bookedRecurring(Tenant $tenant): array
{
    /** @var array<string, int> $booked */
    $booked = inTenantContext($tenant, fn (): array => CashTransaction::query()
        ->whereNotNull('generated_key')
        ->orderBy('generated_key')
        ->pluck('amount', 'generated_key')
        ->map(fn (mixed $amount): int => is_numeric($amount) ? (int) $amount : 0)
        ->all());

    return $booked;
}

/**
 * The key a template's period is booked under.
 */
function rentKeyOf(Tenant $tenant, string $period): string
{
    /** @var int $id */
    $id = inTenantContext($tenant, fn (): int => RecurringTemplate::query()->firstOrFail()->id);

    return "template:{$id}:{$period}";
}

/* ================================ THE TENANT LOOP ================================ */

it('books every usable shop its own rent and rentals, and nobody else\'s', function (): void {
    $alpha = shopPayingMonthlyRent(80_000_000);
    $beta = shopPayingMonthlyRent(30_000_000);
    $suspended = shopPayingMonthlyRent(50_000_000, Tenant::factory()->suspended()->withDomain()->create());

    // Alpha also leases a desk out: rentals are booked by the same run.
    /** @var int $rentalId */
    $rentalId = inTenantContext($alpha, function (): int {
        $till = Account::query()->where('type', Account::TYPE_CASH)->firstOrFail();

        $desk = TransactionCategory::query()->create([
            'account_id' => Account::factory()->create(['type' => Account::TYPE_INCOME, 'name' => 'اجاره میز'])->id,
            'name' => 'اجاره میز', 'direction' => CashDirection::Income, 'is_active' => true,
        ]);

        return (int) RentalContract::query()->create([
            'party_id' => Party::factory()->create()->id,
            'transaction_category_id' => $desk->id,
            'account_id' => $till->id,
            'number' => 'RNT-000001',
            'title' => 'میز تعمیرات',
            'monthly_amount' => 12_000_000,
            'due_day' => 1,
            'starts_on' => '2026-07-23',
        ])->id;
    });

    $this->artisan('treasury:generate-recurring')->assertSuccessful();

    // Mordad and Shahrivar: the 1st of Shahrivar began half an hour ago on the shop clock.
    expect(bookedRecurring($alpha))->toBe([
        "rental:{$rentalId}:1405-05" => 12_000_000,
        "rental:{$rentalId}:1405-06" => 12_000_000,
        rentKeyOf($alpha, '1405-05') => 80_000_000,
        rentKeyOf($alpha, '1405-06') => 80_000_000,
    ]);

    // Beta's own rent, and nothing of Alpha's: RLS is what keeps Alpha's template out of
    // the loop while it runs as Beta.
    expect(bookedRecurring($beta))->toBe([
        rentKeyOf($beta, '1405-05') => 30_000_000,
        rentKeyOf($beta, '1405-06') => 30_000_000,
    ]);

    // A suspended shop is skipped on an unattended run.
    expect(bookedRecurring($suspended))->toBe([]);

    // And the console process is not left pinned to the last shop it booked for.
    expect(app(TenantContext::class)->id())->toBeNull()
        ->and(cache()->get('treasury.recurring_generated_at'))->not->toBeNull();
})->group('isolation');

it('books nothing twice when it runs again the same night, or by hand for one shop', function (): void {
    $shop = shopPayingMonthlyRent(80_000_000);

    $this->artisan('treasury:generate-recurring')->assertSuccessful();

    $once = inTenantContext($shop, fn (): int => CashTransaction::query()->count());

    // The scheduler's retry, a second server that missed the lock, an operator curious
    // what the command does. Each finds every period's key taken.
    $this->artisan('treasury:generate-recurring')->assertSuccessful();
    $this->artisan('treasury:generate-recurring', ['--tenant' => [$shop->slug]])->assertSuccessful();

    expect($once)->toBe(2)
        ->and(inTenantContext($shop, fn (): int => CashTransaction::query()->count()))->toBe(2)
        ->and(bookedRecurring($shop))->toBe([
            rentKeyOf($shop, '1405-05') => 80_000_000,
            rentKeyOf($shop, '1405-06') => 80_000_000,
        ]);
});

it('books a shop alone when asked for one, and leaves the others to the nightly run', function (): void {
    $alpha = shopPayingMonthlyRent(80_000_000);
    $beta = shopPayingMonthlyRent(30_000_000);

    $this->artisan('treasury:generate-recurring', ['--tenant' => [$beta->slug]])->assertSuccessful();

    expect(bookedRecurring($alpha))->toBe([])
        ->and(bookedRecurring($beta))->toHaveCount(2);
})->group('isolation');

it('keeps booking the other shops when one throws, and reports the failure', function (): void {
    Exceptions::fake();

    $broken = shopPayingMonthlyRent(80_000_000);
    $healthy = shopPayingMonthlyRent(30_000_000);

    // Tenants are visited in id order, so the broken shop throws BEFORE the healthy one is
    // reached — the order that would lose it.
    $brokenId = $broken->getKey();

    app()->bind(GenerateRecurring::class, function () use ($brokenId): GenerateRecurring {
        if (app(TenantContext::class)->id() === $brokenId) {
            throw new RuntimeException('a shop whose recurring bookings cannot run');
        }

        return new GenerateRecurring(app(RecordCashTransaction::class));
    });

    // Non-zero, so the scheduler's log shows a failed run rather than a clean one.
    $this->artisan('treasury:generate-recurring')->assertFailed();

    expect(bookedRecurring($healthy))->toHaveCount(2)
        ->and(bookedRecurring($broken))->toBe([]);

    Exceptions::assertReported(RuntimeException::class);
});

/* ================================= THE SCHEDULE ================================= */

it('is on the schedule just after Tehran midnight, locked against overlap and to one server', function (): void {
    expect(Artisan::all())->toHaveKey('treasury:generate-recurring');

    $events = collect(app(Schedule::class)->events())
        ->filter(fn (Event $event): bool => str_contains((string) $event->command, 'treasury:generate-recurring'))
        ->values();

    expect($events)->toHaveCount(1);

    /** @var Event $event */
    $event = $events->first();

    expect($event->expression)->toBe('10 0 * * *')
        ->and($event->timezone)->toBe('Asia/Tehran')
        ->and($event->withoutOverlapping)->toBeTrue()
        // An hour, not the default day: a killed run must not hold tomorrow's lock.
        ->and($event->expiresAt)->toBe(60)
        ->and($event->onOneServer)->toBeTrue();
});
