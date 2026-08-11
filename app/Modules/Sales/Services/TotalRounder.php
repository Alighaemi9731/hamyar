<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services;

use App\Modules\Sales\Enums\RoundingDirection;

/**
 * Rounding the grand total to something a counter can actually settle.
 *
 * ## Why this exists at all
 *
 * A total of 12,847,300 rial cannot be paid. Iranian shops do not handle coins, and the
 * smallest note in normal circulation makes anything under a few thousand rial
 * meaningless. So the counter rounds — and if the software does not, the salesperson
 * does it in their head, the drawer stops reconciling, and nobody can say why.
 *
 * ## The three rules this obeys
 *
 * 1. **It is applied once, to the grand total, and never to a line.** Rounding lines
 *    would make VAT wrong (tax is computed per line on the discounted price) and would
 *    make per-line profit drift from the invoice it belongs to.
 *
 * 2. **The adjustment is recorded, not absorbed.** `apply()` returns the delta so the
 *    invoice can show it as its own figure. An invoice whose lines sum to one number
 *    and whose total is another, with nothing explaining the gap, is an invoice a
 *    customer argues with — and a shop cannot reconcile.
 *
 * 3. **Integer rial throughout.** `intdiv` only; no float touches money.
 *
 * ## Worked examples
 *
 * At `step = 10,000` rial (1,000 toman), on a total of **12,847,300**:
 *
 * | direction | result | adjustment |
 * |---|---|---|
 * | `none`    | 12,847,300 | 0 |
 * | `nearest` | 12,850,000 | +2,700 |
 * | `down`    | 12,840,000 | −7,300 |
 * | `up`      | 12,850,000 | +2,700 |
 *
 * And on **12,842,000**, where nearest and up diverge:
 *
 * | direction | result | adjustment |
 * |---|---|---|
 * | `nearest` | 12,840,000 | −2,000 |
 * | `up`      | 12,850,000 | +8,000 |
 *
 * A total already on the step is never moved, in any direction — including `up`, which
 * would otherwise add a full step to a number that was already payable.
 */
final class TotalRounder
{
    /**
     * The shop's default until Settings owns it (Phase 11).
     *
     * 1,000 rial = 100 toman. Deliberately modest: it is the smallest step that removes
     * unpayable change, and a shop that wants a coarser one can say so. `Nearest` rather
     * than `Down` because a default that always costs the shop money is a default nobody
     * audits until the year-end.
     */
    public const DEFAULT_STEP = 1_000;

    public const DEFAULT_DIRECTION = RoundingDirection::Nearest;

    /**
     * @return array{total: int, adjustment: int}
     */
    public function apply(int $total, ?int $step = null, ?RoundingDirection $direction = null): array
    {
        $step ??= self::DEFAULT_STEP;
        $direction ??= self::DEFAULT_DIRECTION;

        if ($direction === RoundingDirection::None || $step <= 1 || $total <= 0) {
            return ['total' => $total, 'adjustment' => 0];
        }

        $remainder = $total % $step;

        // Already payable. Nothing to do — and this is the branch that stops `up` from
        // adding a whole step to a round number.
        if ($remainder === 0) {
            return ['total' => $total, 'adjustment' => 0];
        }

        $rounded = match ($direction) {
            RoundingDirection::Down => $total - $remainder,
            RoundingDirection::Up => $total - $remainder + $step,
            // Half-up: exactly half a step rounds away from zero, which is what a person
            // does in their head and therefore what the customer expects to see.
            // `None` returned early above, so this arm covers it and Nearest together.
            default => $remainder * 2 >= $step
                ? $total - $remainder + $step
                : $total - $remainder,
        };

        return ['total' => $rounded, 'adjustment' => $rounded - $total];
    }
}
