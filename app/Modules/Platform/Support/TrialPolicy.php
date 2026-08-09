<?php

declare(strict_types=1);

namespace App\Modules\Platform\Support;

use App\Modules\Platform\Models\Plan;
use App\Modules\Platform\Models\PlanLimit;

/**
 * What a free trial actually gets (DECISION GATE 2, item 3).
 *
 * The shape is deliberate and the two halves pull in opposite directions:
 *
 * - **Generous on features.** A trial runs the Professional plan, so an evaluating shop
 *   sees تعمیرات and اقساط — the modules that differentiate the product. Selling Basic to
 *   someone who never saw Repairs loses both the upsell and the customer.
 * - **Tight on quotas.** Everything metered is capped at the Basic tier, and bonus SMS
 *   credit is zero. Fourteen days of unlimited invoicing plus 500 free SMS is not an
 *   evaluation, it is a free month of business for anyone willing to re-register — and
 *   SMS costs us real money per message, which makes it the one quota worth defending
 *   outright rather than merely capping.
 *
 * No card is required to start, so there is no payment barrier and equally no
 * chargeback recourse. That is what makes the quota caps load-bearing rather than
 * decorative.
 */
final class TrialPolicy
{
    /**
     * Length in days. Mirrors `plans.trial_days`, which the panel may edit; this is the
     * value a fresh install starts from.
     */
    public const DAYS = 14;

    /**
     * Whose quotas a trial borrows, regardless of the plan being trialled.
     */
    public const BASELINE_PLAN_CODE = 'basic';

    /**
     * Metered limits forced to a fixed value during a trial, whatever any plan says.
     *
     * @var array<string, int>
     */
    private const FORCED = [
        // Bought, never granted. SMS is the only limit here that costs us cash per unit.
        PlanLimit::SMS_CREDIT_BONUS => 0,
    ];

    /**
     * Limits that fall back to the baseline plan's value during a trial.
     *
     * @var list<string>
     */
    private const BORROWED_FROM_BASELINE = [
        PlanLimit::INVOICES_PER_MONTH,
    ];

    /**
     * Resolve one limit for a trialing subscription.
     *
     * @param  Plan  $trialled  the plan the shop is trialling (Pro, normally)
     * @param  Plan|null  $baseline  the Basic plan as it exists in the database, or null
     *                               if it has been deleted — in which case the trialled
     *                               plan's own limit applies rather than no limit at all
     * @return int|null the effective limit, or null for unlimited
     */
    public static function limit(string $key, Plan $trialled, ?Plan $baseline): ?int
    {
        if (array_key_exists($key, self::FORCED)) {
            return self::FORCED[$key];
        }

        if (! in_array($key, self::BORROWED_FROM_BASELINE, true)) {
            return $trialled->limit($key);
        }

        if (! $baseline instanceof Plan) {
            return $trialled->limit($key);
        }

        $borrowed = $baseline->limit($key);
        $own = $trialled->limit($key);

        // Take whichever is tighter. `null` means unlimited, so it loses to any number —
        // a trial must never end up with a larger quota than the plan it borrows from
        // just because someone made Basic unlimited in the panel.
        return match (true) {
            $borrowed === null => $own,
            $own === null => $borrowed,
            default => min($borrowed, $own),
        };
    }
}
