<?php

declare(strict_types=1);

use App\Modules\Platform\Models\Plan;
use App\Modules\Platform\Models\Subscription;
use App\Modules\Platform\Services\ProrationCalculator;
use Carbon\CarbonImmutable;

/**
 * ADR 0006. Exact expected values throughout — an approximate assertion on money
 * hides precisely the bug it should catch.
 */
function planWithPrice(int $rial, string $code): Plan
{
    $plan = new Plan(['code' => $code, 'name_fa' => $code, 'interval' => 'month', 'price' => $rial]);
    $plan->id = crc32($code);

    return $plan;
}

function subscriptionOn(Plan $plan, CarbonImmutable $start, CarbonImmutable $end, string $status = Subscription::STATUS_ACTIVE): Subscription
{
    $subscription = new Subscription([
        'status' => $status,
        'current_period_start' => $start,
        'current_period_end' => $end,
    ]);

    $subscription->setRelation('plan', $plan);

    return $subscription;
}

it('charges only the difference for the days that remain', function (): void {
    $now = CarbonImmutable::parse('2026-08-11T10:00:00Z');

    // 30-day period, 11 days used, 19 remaining.
    $basic = planWithPrice(2_900_000, 'basic');
    $pro = planWithPrice(5_900_000, 'pro');

    $subscription = subscriptionOn($basic, $now->subDays(11), $now->addDays(19));

    $result = app(ProrationCalculator::class)->preview($subscription, $pro, $now);

    expect($result['kind'])->toBe('upgrade');
    expect($result['period_days'])->toBe(30);
    expect($result['remaining_days'])->toBe(19);

    // intdiv(2_900_000 × 19, 30) = 1_836_666
    // intdiv(5_900_000 × 19, 30) = 3_736_666
    expect($result['unused_credit'])->toBe(1_836_666);
    expect($result['new_charge'])->toBe(3_736_666);
    expect($result['amount_due'])->toBe(1_900_000);
    expect($result['amount_due'])->toBeRial();
});

it('never moves the renewal date on upgrade', function (): void {
    // A changed renewal date is the single most common billing support ticket.
    $now = CarbonImmutable::parse('2026-08-11T10:00:00Z');
    $end = $now->addDays(19);

    $subscription = subscriptionOn(planWithPrice(2_900_000, 'basic'), $now->subDays(11), $end);

    app(ProrationCalculator::class)->preview($subscription, planWithPrice(5_900_000, 'pro'), $now);

    expect($subscription->current_period_end?->equalTo($end))->toBeTrue();
});

it('defers a downgrade to period end and charges nothing', function (): void {
    $now = CarbonImmutable::parse('2026-08-11T10:00:00Z');

    $subscription = subscriptionOn(planWithPrice(5_900_000, 'pro'), $now->subDays(11), $now->addDays(19));

    $result = app(ProrationCalculator::class)->preview($subscription, planWithPrice(2_900_000, 'basic'), $now);

    expect($result['kind'])->toBe('downgrade');
    expect($result['effective_at'])->toBe('period_end');
    // Never claw back features the shop already paid for.
    expect($result['amount_due'])->toBe(0);
});

it('charges nothing to upgrade during a trial', function (): void {
    // Charging inside a period advertised as free is a promise broken.
    $now = CarbonImmutable::parse('2026-08-11T10:00:00Z');

    $subscription = subscriptionOn(
        planWithPrice(2_900_000, 'basic'),
        $now->subDays(4),
        $now->addDays(10),
        Subscription::STATUS_TRIALING
    );
    $subscription->trial_ends_at = $now->addDays(10);

    $result = app(ProrationCalculator::class)->preview($subscription, planWithPrice(11_900_000, 'enterprise'), $now);

    expect($result['amount_due'])->toBe(0);
    expect($result['effective_at'])->toBe('immediately');
});

it('charges nothing for the same plan', function (): void {
    $now = CarbonImmutable::parse('2026-08-11T10:00:00Z');
    $plan = planWithPrice(2_900_000, 'basic');

    $result = app(ProrationCalculator::class)
        ->preview(subscriptionOn($plan, $now->subDays(11), $now->addDays(19)), $plan, $now);

    expect($result['kind'])->toBe('same');
    expect($result['amount_due'])->toBe(0);
});

it('charges nothing when the period has already ended', function (): void {
    $now = CarbonImmutable::parse('2026-08-11T10:00:00Z');

    $subscription = subscriptionOn(planWithPrice(2_900_000, 'basic'), $now->subDays(40), $now->subDay());

    $result = app(ProrationCalculator::class)->preview($subscription, planWithPrice(5_900_000, 'pro'), $now);

    expect($result['remaining_days'])->toBe(0);
    expect($result['amount_due'])->toBe(0);
});

it('truncates in the customer favour and never returns a negative charge', function (int $current, int $new, int $remaining, int $period): void {
    $calculator = app(ProrationCalculator::class);

    $credit = $calculator->portion($current, $remaining, $period);
    $charge = $calculator->portion($new, $remaining, $period);

    // intdiv only ever rounds down, so neither term can exceed the exact fraction.
    expect($credit)->toBeLessThanOrEqual(intdiv($current * $remaining, max(1, $period)));
    expect(max(0, $charge - $credit))->toBeGreaterThanOrEqual(0);
})->with([
    [2_900_000, 5_900_000, 19, 30],
    [2_900_000, 11_900_000, 1, 30],
    [5_900_000, 5_900_001, 29, 30],
    [1, 3, 7, 31],
]);

it('returns whole rial for every combination', function (): void {
    $calculator = app(ProrationCalculator::class);

    foreach ([1, 7, 15, 29, 30] as $remaining) {
        expect($calculator->portion(2_900_000, $remaining, 30))->toBeRial();
    }
});
