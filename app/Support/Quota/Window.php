<?php

declare(strict_types=1);

namespace App\Support\Quota;

/**
 * The period a quota is measured over.
 *
 * Two cases, and the list is deliberately this short.
 *
 * `Month` is the ordinary one: a credit for one Jalali month, refilled in full at 00:00
 * Tehran on the 1st. The shop spends it at whatever rhythm its week has, so a busy
 * Thursday borrows from a quiet Saturday — which is the whole reason the owner chose a
 * month over a day at DECISION GATE 6 («هر کاربر برای هر ماه یه کریدیتی … داشته باشه»).
 *
 * `Total` is not a flow but a standing capacity: seats, branches, storage, live
 * price-list links. It has no period, no counter row and no reset; usage is however many
 * live rows exist right now.
 *
 * **There is no `Day`.** The design carried one until the gate and it was removed rather
 * than kept "in case": an enum case nothing uses is a promise nobody keeps, and its
 * presence would invite a second window on a key whose counter rows are then
 * incomparable. If a day bucket is ever wanted, it is a case here plus a period-key
 * branch — and a deliberate decision, not an accident of a leftover.
 */
enum Window: string
{
    case Month = 'month';

    case Total = 'total';

    /**
     * Does this window keep a `usage_counters` row?
     *
     * The window IS the counted/computed distinction, which is why there is no separate
     * "kind" enum to disagree with it.
     */
    public function isCounted(): bool
    {
        return $this === self::Month;
    }

    public function labelFa(): string
    {
        return match ($this) {
            self::Month => 'در ماه',
            self::Total => 'ظرفیت',
        };
    }
}
