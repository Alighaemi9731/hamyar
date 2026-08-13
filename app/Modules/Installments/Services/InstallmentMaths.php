<?php

declare(strict_types=1);

namespace App\Modules\Installments\Services;

use App\Modules\Installments\Models\InstallmentPlan;
use App\Modules\Installments\Models\InstallmentRow;
use App\Support\Settings\ShopSettings;
use Carbon\CarbonImmutable;

/**
 * Late fees and early settlement, exactly as `docs/specs/installment-collection.md` says.
 *
 * **That document is the specification and this is the implementation.** Every method names
 * the section it implements, and `InstallmentMathsTest` pins each rule with the worked
 * example from the spec — the same standard the cheque posting matrix is held to, because
 * these are the figures a customer disputes at the counter.
 *
 * Pure: no database writes, no clock of its own. Everything arrives as an argument, which
 * is what lets the rounding be asserted against exact rial rather than inferred from a
 * seeded scenario.
 *
 * ## ADR 0009 runs through all of it
 *
 * Every figure a customer sees is a whole number of toman, derived figures floor, and
 * `floorToman()` is applied at the last step rather than to intermediates — flooring early
 * and multiplying afterwards is the classic way to be nine rial out per day and produce a
 * fee that does not match a hand calculation.
 */
final class InstallmentMaths
{
    public function __construct(private readonly ShopSettings $settings) {}

    /**
     * §1 — how much of a row is financing profit rather than principal.
     *
     * Derived, never stored: two columns that must sum to a third is an invariant nobody
     * checks until it breaks. `principalPartOf()` is defined as the remainder, so the two
     * halves sum to the row exactly by construction.
     */
    public function profitPartOf(InstallmentRow $row, InstallmentPlan $plan): int
    {
        if ($plan->total_payable <= 0) {
            return 0;
        }

        // The last row carries the residue, so the parts sum to the plan's profit exactly
        // — the same rule, in the same place, as the scheduler's last-row rule.
        if ($row->sequence === $plan->installment_count) {
            $earlier = 0;

            foreach ($plan->rows()->where('sequence', '<', $row->sequence)->get() as $before) {
                $earlier += $this->rawProfitPart($before, $plan);
            }

            return $plan->profit_amount - $earlier;
        }

        return $this->rawProfitPart($row, $plan);
    }

    public function principalPartOf(InstallmentRow $row, InstallmentPlan $plan): int
    {
        return $row->amount - $this->profitPartOf($row, $plan);
    }

    /**
     * §2 — the late fee on one overdue row.
     *
     * Per-day, on the row amount fixed at contract time, never compounding. Interest on
     * unpaid interest is ربا in the reading most of this market follows, and a shop that
     * charges it will be told so by a customer.
     */
    public function lateFeeOn(InstallmentRow $row, ?CarbonImmutable $asOf = null): int
    {
        $policy = $this->settings->installments();

        if ($policy->lateFeePercentPerMonth <= 0) {
            // Off by default. A fee appearing on a customer's account that the owner never
            // configured is the kind of surprise that ends a relationship.
            return 0;
        }

        $asOf ??= CarbonImmutable::now();

        $daysLate = (int) $row->due_at->startOfDay()->diffInDays($asOf->startOfDay(), false);
        $chargeable = max(0, $daysLate - $policy->lateFeeGraceDays);

        if ($chargeable === 0) {
            return 0;
        }

        // Multiply before dividing: computing a daily rate first and flooring it
        // under-charges by up to nine rial a day and produces a figure that does not match
        // a hand calculation.
        $raw = intdiv($row->amount * $policy->lateFeePercentPerMonth * $chargeable, 100 * 30);

        $cap = $this->floorToman(intdiv($row->amount * $policy->lateFeeCapPercent, 100));

        return min($this->floorToman($raw), $cap);
    }

    /**
     * §3 — what a customer pays to clear the whole plan today.
     *
     * @return array{principal: int, profit_due: int, rebate: int, fees: int, total: int}
     */
    public function earlySettlement(InstallmentPlan $plan, ?CarbonImmutable $asOf = null): array
    {
        $asOf ??= CarbonImmutable::now();

        $remaining = $plan->rows()
            ->whereIn('status', [InstallmentRow::STATUS_PENDING, InstallmentRow::STATUS_OVERDUE])
            ->orderBy('sequence')
            ->get();

        $principal = 0;
        $profit = 0;
        $fees = 0;

        foreach ($remaining as $row) {
            $principal += $this->principalPartOf($row, $plan);
            $profit += $this->profitPartOf($row, $plan);
            $fees += $this->lateFeeOn($row, $asOf);
        }

        /*
        | Pro rata by instalment COUNT, never by days.
        |
        | The customer was quoted a per-instalment figure, not a rate. A day-count rebate
        | gives a settlement figure that changes between Monday and Tuesday, which cannot
        | be quoted over the phone and reads as the shop making the number up. «سه قسط
        | مونده» is what this means to both sides of the counter.
        */
        $rebate = $plan->installment_count > 0
            ? $this->floorToman(intdiv($profit * $remaining->count(), $plan->installment_count))
            : 0;

        return [
            'principal' => $principal,
            'profit_due' => $profit - $rebate,
            'rebate' => $rebate,
            // Never rebated: a fee is a charge for a breach that happened, and settling
            // early does not un-happen it.
            'fees' => $fees,
            'total' => $principal + ($profit - $rebate) + $fees,
        ];
    }

    /**
     * §4 — how a part payment is applied: fee first, then profit, then principal.
     *
     * Fee first because it is the charge most likely to be disputed later, so a customer
     * who pays anything has cleared it. Profit before principal because the shop's
     * earnings should not be the last thing collected from somebody already struggling —
     * and because it is the order every Iranian lender uses, so it matches expectation.
     *
     * @return array{fee: int, profit: int, principal: int, unapplied: int}
     */
    public function applyPayment(InstallmentRow $row, InstallmentPlan $plan, int $amount, ?CarbonImmutable $asOf = null): array
    {
        $remaining = max(0, $amount);

        $fee = min($remaining, $this->lateFeeOn($row, $asOf));
        $remaining -= $fee;

        $profit = min($remaining, $this->profitPartOf($row, $plan));
        $remaining -= $profit;

        $principal = min($remaining, $this->principalPartOf($row, $plan));
        $remaining -= $principal;

        return [
            'fee' => $fee,
            'profit' => $profit,
            'principal' => $principal,
            // Anything left over — an overpayment. It belongs on the party's balance as a
            // credit, not silently absorbed into this row.
            'unapplied' => $remaining,
        ];
    }

    private function rawProfitPart(InstallmentRow $row, InstallmentPlan $plan): int
    {
        return $this->floorToman(intdiv($plan->profit_amount * $row->amount, $plan->total_payable));
    }

    /**
     * Down to a whole toman. ADR 0009 — `Money` refuses to render anything else.
     */
    private function floorToman(int $rial): int
    {
        return $rial - ($rial % 10);
    }
}
