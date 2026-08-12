<?php

declare(strict_types=1);

namespace App\Modules\Installments\Services;

use App\Support\Jalali;
use App\Support\Money;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * The arithmetic of an instalment plan, with nothing else in it.
 *
 * Pure: no database, no clock, no container. Everything it needs arrives as arguments,
 * which is what lets the rounding rule be tested against exact expected rial rather than
 * inferred from a seeded scenario.
 *
 * ## Flat profit, because that is what the shop quotes
 *
 * «۲۰٪ سود روی ۶ قسط» is a flat markup on the financed amount, not a reducing-balance
 * rate. Computing an APR here would be more defensible as finance and would print a
 * schedule that does not match what the customer was told, which is worse. The profit is
 * `principal × percent ÷ 100`, once, on the whole amount.
 *
 * ## The last instalment absorbs the remainder
 *
 * 10,000,000 over three instalments is 3,333,333.33 each, and none of those is a number
 * a customer can pay. Every row is floored to a whole toman and the last one carries
 * whatever the division left behind, so:
 *
 * | | |
 * |---|---|
 * | rows 1–2 | 3,333,330 rial each |
 * | row 3 | 3,333,340 rial |
 * | sum | 10,000,000 rial — exactly the total |
 *
 * The last row rather than the first, because that is the one still in the future when
 * somebody checks the arithmetic, and because a shop that quotes «قسط ۳,۳۳۳,۳۳۰ تومان»
 * wants that figure to be the one on most of the rows.
 *
 * One consequence worth stating, because it looks like a bug: **the last row is a whole
 * toman only if the financed amount is.** Every other row is floored to one, and the last
 * carries whatever is left — so an odd rial in the input comes out in the final
 * instalment rather than being quietly dropped. That is the right trade: the parts must
 * sum to the whole exactly, because the whole is what the customer signed for, and
 * losing a rial to make a rounder final figure means the shop collects less than the
 * contract says. In practice it never arises — invoice totals are rounded to the shop's
 * step and every amount typed at the till is in toman.
 *
 * ## The dates are Jalali months apart
 *
 * See {@see Jalali::addMonths()}. Adding a fixed thirty days drifts against the calendar
 * the contract is written in, and by the twelfth instalment the schedule and the paper
 * disagree.
 */
final class InstallmentScheduler
{
    /** More than a shop ever writes, and past it somebody has typed the wrong field. */
    public const MAX_INSTALLMENTS = 120;

    /**
     * Build a schedule.
     *
     * @param  int  $financed  what is left after the down payment, in rial
     * @return array{principal: int, profit_amount: int, total_payable: int, rows: list<array{sequence: int, due_at: CarbonImmutable, amount: int}>}
     */
    public function schedule(
        int $financed,
        int $count,
        int $profitPercent,
        CarbonImmutable $firstDueAt,
        int $intervalMonths = 1,
    ): array {
        $this->guard($financed, $count, $profitPercent, $intervalMonths);

        $profit = $this->profitOn($financed, $profitPercent);
        $total = $financed + $profit;

        // Floored to a whole toman: the shop cannot collect a fraction of one, the
        // customer cannot pay it, and `Money` will not print it.
        $each = $this->wholeToman(intdiv($total, $count));

        $rows = [];
        $allocated = 0;

        for ($sequence = 1; $sequence <= $count; $sequence++) {
            // The last row takes the total minus everything already scheduled, so the
            // parts sum to the whole exactly rather than approximately.
            $amount = $sequence === $count ? $total - $allocated : $each;
            $allocated += $amount;

            $rows[] = [
                'sequence' => $sequence,
                'due_at' => Jalali::addMonths($firstDueAt, ($sequence - 1) * $intervalMonths),
                'amount' => $amount,
            ];
        }

        return [
            'principal' => $financed,
            'profit_amount' => $profit,
            'total_payable' => $total,
            'rows' => $rows,
        ];
    }

    /**
     * The flat markup, floored to a whole toman.
     *
     * Floored rather than rounded: on a figure the shop chose and the customer agreed to,
     * being a few hundred rial under is the safer side to land on.
     */
    public function profitOn(int $financed, int $percent): int
    {
        return $this->wholeToman(intdiv($financed * $percent, 100));
    }

    private function guard(int $financed, int $count, int $percent, int $intervalMonths): void
    {
        if ($financed <= 0) {
            throw new InvalidArgumentException('مبلغ قابل تقسیط باید بیشتر از صفر باشد.');
        }

        if ($count < 1 || $count > self::MAX_INSTALLMENTS) {
            throw new InvalidArgumentException('تعداد اقساط معتبر نیست.');
        }

        if ($percent < 0 || $percent > 100) {
            throw new InvalidArgumentException('درصد سود معتبر نیست.');
        }

        if ($intervalMonths < 1 || $intervalMonths > 12) {
            throw new InvalidArgumentException('فاصله اقساط معتبر نیست.');
        }

        // A plan whose instalments would each be under a whole toman is not a plan; it is
        // a typo in the count field. Caught here rather than by the CHECK constraint,
        // which would surface as a database error at the counter.
        if (intdiv($financed, $count) < Money::RIAL_PER_TOMAN) {
            throw new InvalidArgumentException('تعداد اقساط نسبت به مبلغ خیلی زیاد است.');
        }
    }

    private function wholeToman(int $rial): int
    {
        return intdiv($rial, Money::RIAL_PER_TOMAN) * Money::RIAL_PER_TOMAN;
    }
}
