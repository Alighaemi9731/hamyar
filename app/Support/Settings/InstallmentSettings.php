<?php

declare(strict_types=1);

namespace App\Support\Settings;

/**
 * How a shop treats a late instalment, and what it gives back for an early one.
 *
 * Specified in `docs/specs/installment-collection.md` §2 and §3, and pinned by
 * `InstallmentMathsTest` against the worked examples there.
 *
 * ## The late fee is off by default, and that is the cautious end
 *
 * A shop that has not opened the settings screen has not decided its late-fee policy, and
 * a fee appearing on a customer's account that the owner never configured is the kind of
 * surprise that ends a relationship. Zero means no fee, ever — not "use a sensible
 * default", because there is no sensible default for a charge somebody has to defend.
 *
 * ## Never compounding, and it is not a setting
 *
 * The fee is computed on the row amount fixed at contract time, never on the row plus
 * accrued fee. Interest on unpaid interest is ربا in the reading most of this market
 * follows, and offering it as an option would make the software complicit in an argument
 * a shopkeeper cannot win. There is deliberately no flag for it.
 */
final readonly class InstallmentSettings
{
    public function __construct(
        /** Percent per month on an overdue row. Zero — the default — means no late fee. */
        public int $lateFeePercentPerMonth,
        /** Days late before anything is charged at all. */
        public int $lateFeeGraceDays,
        /** The fee stops growing at this share of the row, however late it becomes. */
        public int $lateFeeCapPercent,
    ) {}
}
