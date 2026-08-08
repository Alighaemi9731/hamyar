<?php

declare(strict_types=1);

use App\Modules\Platform\Events\SubscriptionRenewalDue;
use App\Modules\Platform\Jobs\SendRenewalReminders;
use App\Modules\Platform\Models\Subscription;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Platform\Services\PlanCatalogueSeeder;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Event;

beforeEach(function (): void {
    app(PlanCatalogueSeeder::class)->sync();
    $this->tenant = Tenant::factory()->withDomain()->create();
});

afterEach(fn () => app(TenantContext::class)->forget());

it('reminds a shop on each of the configured days', function (int $daysLeft): void {
    Event::fake([SubscriptionRenewalDue::class]);

    subscribe($this->tenant, 'pro', [
        'current_period_end' => now()->addDays($daysLeft)->setTime(12, 0),
    ]);

    app(SendRenewalReminders::class)->handle(app(TenantContext::class));

    Event::assertDispatched(
        SubscriptionRenewalDue::class,
        fn (SubscriptionRenewalDue $event): bool => $event->daysLeft === $daysLeft
    );
})->with(SendRenewalReminders::REMIND_DAYS_BEFORE);

it('stays quiet on a day that is not a reminder day', function (): void {
    Event::fake([SubscriptionRenewalDue::class]);

    // Five days out sits between the 7-day and 3-day reminders. Sending on every day
    // would train shops to ignore us.
    subscribe($this->tenant, 'pro', [
        'current_period_end' => now()->addDays(5)->setTime(12, 0),
    ]);

    app(SendRenewalReminders::class)->handle(app(TenantContext::class));

    Event::assertNotDispatched(SubscriptionRenewalDue::class);
});

it('ignores a canceled subscription', function (): void {
    Event::fake([SubscriptionRenewalDue::class]);

    subscribe($this->tenant, 'pro', [
        'status' => Subscription::STATUS_CANCELED,
        'current_period_end' => now()->addDays(3)->setTime(12, 0),
    ]);

    app(SendRenewalReminders::class)->handle(app(TenantContext::class));

    // Nothing is renewing, so "your subscription renews in 3 days" would be a lie.
    Event::assertNotDispatched(SubscriptionRenewalDue::class);
});

it('reminds every shop that is due, across tenants', function (): void {
    Event::fake([SubscriptionRenewalDue::class]);

    $second = Tenant::factory()->withDomain()->create();

    subscribe($this->tenant, 'pro', ['current_period_end' => now()->addDays(3)->setTime(12, 0)]);
    subscribe($second, 'basic', ['current_period_end' => now()->addDays(3)->setTime(12, 0)]);

    // The job reads across every tenant via runAsPlatform; a scoped read would silently
    // remind nobody, because a queued job starts with no tenant context at all.
    app(SendRenewalReminders::class)->handle(app(TenantContext::class));

    Event::assertDispatchedTimes(SubscriptionRenewalDue::class, 2);
});

it('fires each reminder inside the right tenant context', function (): void {
    $seen = null;

    Event::listen(SubscriptionRenewalDue::class, function () use (&$seen): void {
        // A listener that reads shop settings or a customer's mobile number needs the
        // context already established — that is the job's contract, so assert it.
        $seen = app(TenantContext::class)->id();
    });

    subscribe($this->tenant, 'pro', ['current_period_end' => now()->addDays(1)->setTime(12, 0)]);

    app(SendRenewalReminders::class)->handle(app(TenantContext::class));

    expect($seen)->toBe($this->tenant->getKey());
});
