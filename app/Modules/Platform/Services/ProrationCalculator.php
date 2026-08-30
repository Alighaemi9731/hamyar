<?php

declare(strict_types=1);

namespace App\Modules\Platform\Services;

use App\Modules\Platform\Models\Plan;
use App\Modules\Platform\Models\Subscription;
use App\Support\Money;
use Carbon\CarbonImmutable;

/**
 * The ONE place subscription proration arithmetic lives (ADR 0006).
 *
 * Every surface that shows a proration figure — the upgrade preview, the invoice, the
 * Filament panel — calls this. Recomputing it anywhere else is how a preview ends up
 * disagreeing with the invoice the customer is then charged.
 *
 * Integer rial throughout. `intdiv` truncates, which rounds in the customer's favour;
 * that direction is deliberate (ADR 0006).
 */
final class ProrationCalculator
{
    /**
     * What changing plan costs right now.
     *
     * @return array{
     *     kind: 'upgrade'|'downgrade'|'same',
     *     amount_due: int,
     *     unused_credit: int,
     *     new_charge: int,
     *     remaining_days: int,
     *     period_days: int,
     *     effective_at: 'immediately'|'period_end',
     * }
     */
    public function preview(Subscription $subscription, Plan $newPlan, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();

        $currentPlan = $subscription->plan;

        $periodDays = $this->periodDays($subscription);
        $remainingDays = $this->remainingDays($subscription, $now);

        // A trial upgrade costs nothing now and does not move the trial end date:
        // charging during a period advertised as free is a promise broken.
        if ($subscription->isTrialing($now)) {
            return [
                'kind' => $this->kind($currentPlan, $newPlan),
                'amount_due' => 0,
                'unused_credit' => 0,
                'new_charge' => 0,
                'remaining_days' => $remainingDays,
                'period_days' => $periodDays,
                'effective_at' => 'immediately',
            ];
        }

        $kind = $this->kind($currentPlan, $newPlan);

        // Downgrades take effect at period end. The shop keeps what it paid for;
        // we never take features back mid-period or claw money back.
        if ($kind === 'downgrade') {
            return [
                'kind' => $kind,
                'amount_due' => 0,
                'unused_credit' => 0,
                'new_charge' => 0,
                'remaining_days' => $remainingDays,
                'period_days' => $periodDays,
                'effective_at' => 'period_end',
            ];
        }

        if ($kind === 'same') {
            return [
                'kind' => $kind,
                'amount_due' => 0,
                'unused_credit' => 0,
                'new_charge' => 0,
                'remaining_days' => $remainingDays,
                'period_days' => $periodDays,
                'effective_at' => 'immediately',
            ];
        }

        $unusedCredit = $this->portion($currentPlan->price, $remainingDays, $periodDays);
        $newCharge = $this->portion($newPlan->price, $remainingDays, $periodDays);

        return [
            'kind' => $kind,
            // Never negative: an upgrade cannot produce a refund.
            'amount_due' => max(0, $newCharge - $unusedCredit),
            'unused_credit' => $unusedCredit,
            'new_charge' => $newCharge,
            'remaining_days' => $remainingDays,
            'period_days' => $periodDays,
            'effective_at' => 'immediately',
        ];
    }

    /**
     * price × remaining ÷ period, truncated to a whole TOMAN.
     *
     * Multiply first so the truncation happens once, at the end — dividing first would
     * compound rounding error per term.
     *
     * ## Why whole toman and not whole rial
     *
     * `intdiv` alone lands on figures like ۳٬۹۳۳٬۳۳۳ ریال, which is not a whole toman and
     * therefore not a number this product can say out loud: `Money::toToman()` refuses to
     * round money and throws, so the billing page 500s rather than quote a price it cannot
     * express. That was reachable the moment a plan was free — with no unused credit to
     * subtract, the amount due IS the raw portion.
     *
     * Truncating to the toman keeps ADR 0006's direction (the remainder is left with the
     * customer, never taken from them) and makes all three figures on the invoice line —
     * credit, charge, total — whole toman that add up. A rounding that only applied to the
     * total would produce a receipt whose lines do not sum, which is worse than the
     * fraction it was hiding.
     */
    public function portion(int $price, int $remainingDays, int $periodDays): int
    {
        if ($periodDays <= 0 || $remainingDays <= 0) {
            return 0;
        }

        $rial = intdiv($price * min($remainingDays, $periodDays), $periodDays);

        return intdiv($rial, Money::RIAL_PER_TOMAN) * Money::RIAL_PER_TOMAN;
    }

    /**
     * Whole days left in the period, floored and never negative.
     */
    public function remainingDays(Subscription $subscription, ?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();
        $end = $subscription->current_period_end;

        if ($end === null || $end->lessThanOrEqualTo($now)) {
            return 0;
        }

        return (int) $now->startOfDay()->diffInDays($end->startOfDay(), absolute: true);
    }

    public function periodDays(Subscription $subscription): int
    {
        $start = $subscription->current_period_start;
        $end = $subscription->current_period_end;

        if ($start === null || $end === null) {
            return 0;
        }

        return max(1, (int) $start->startOfDay()->diffInDays($end->startOfDay(), absolute: true));
    }

    /**
     * @return 'upgrade'|'downgrade'|'same'
     */
    private function kind(Plan $current, Plan $new): string
    {
        return match (true) {
            $new->price > $current->price => 'upgrade',
            $new->price < $current->price => 'downgrade',
            default => 'same',
        };
    }
}
