<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Services;

use App\Support\Jalali;
use Carbon\CarbonImmutable;

/**
 * The date range every report is asked for, parsed once.
 *
 * ## Jalali in, UTC out
 *
 * A shopkeeper types «۱۴۰۵/۰۵/۰۱ تا ۱۴۰۵/۰۵/۳۱» and the database holds UTC timestamps.
 * Doing that conversion inside each of twenty-five reports is twenty-five chances to be a
 * day out at a month boundary — and a sales report that silently omits the 31st is the kind
 * of wrong nobody notices until they add the months up and the year does not match.
 *
 * So it happens here, once, and every report takes one of these rather than two strings.
 *
 * ## The end of the range is inclusive
 *
 * «تا ۳۱ مرداد» means including the 31st, all of it. A range built as `<= 2026-08-22
 * 00:00:00` drops everything that happened that day, which is most of a shop's business on
 * the day somebody is asking about.
 *
 * ## A backwards range is swapped, not rejected
 *
 * Somebody typing 1405/05/31 into the "from" box has mistyped, not asked for nothing.
 * Returning an empty report leaves them to work out why; swapping gives them what they
 * meant and costs nothing.
 *
 * ## Every bound is a shop day's bound, whichever way the range was built
 *
 * `from` is 00:00 in Tehran on the first day and `to` is the last microsecond of the last
 * day, both as UTC instants — what {@see Jalali::startOfDay()} and {@see Jalali::endOfDay()}
 * produce. Carbon's own `startOfDay()`/`endOfDay()` on a UTC value are UTC midnights, 03:30
 * in Tehran, and they were used for the default range, the swap and `of()`: the default
 * "to" was 03:29 tomorrow in Tehran, so the range's label read tomorrow's date and the
 * dashboard's "today" took the first three and a half hours of the next day.
 *
 * Because the bounds are Tehran midnights, their UTC DATE is the day before the shop's
 * first day. Anything that wants the day back — a filename, a date column, a chart's
 * x-axis — reads it with {@see Jalali::calendarDate()}, never `toDateString()`.
 */
final readonly class ReportPeriod
{
    private function __construct(
        public CarbonImmutable $from,
        public CarbonImmutable $to,
        public string $fromJalali,
        public string $toJalali,
    ) {}

    /**
     * From two Jalali date strings, as a filter form submits them.
     *
     * Persian digits are normalised by {@see Jalali::parse()}, so «۱۴۰۵/۰۵/۰۱» and
     * "1405/05/01" behave identically — a shop's staff type both, often in one session.
     *
     * Either end left empty is the current Jalali month so far: from its first day to the
     * end of today, both on the shop's calendar.
     */
    public static function fromJalali(?string $from, ?string $to): self
    {
        $now = CarbonImmutable::now();

        $start = $from === null || $from === ''
            // `startOfMonth()` is the first day as a calendar date (midnight UTC), which
            // `startOfDay()` reads as that day and turns into its Tehran midnight.
            ? Jalali::startOfDay(Jalali::startOfMonth($now))
            : Jalali::startOfDay($from);

        $end = $to === null || $to === ''
            ? Jalali::endOfDay($now)
            : Jalali::endOfDay($to);

        if ($start->greaterThan($end)) {
            // The start of the day typed as "to", and the end of the day typed as "from".
            [$start, $end] = [Jalali::startOfDay($end), Jalali::endOfDay($start)];
        }

        return new self($start, $end, Jalali::format($start), Jalali::format($end));
    }

    /**
     * From two instants, each standing for the shop day it falls on.
     */
    public static function of(CarbonImmutable $from, CarbonImmutable $to): self
    {
        return new self(
            Jalali::startOfDay($from),
            Jalali::endOfDay($to),
            Jalali::format($from),
            Jalali::format($to),
        );
    }

    /**
     * The current Jalali month — what a dashboard opens on.
     *
     * `Jalali::startOfMonth()`, not Carbon's: the first of the Gregorian month falls in the
     * middle of the Jalali one, and "this month" would cover parts of two.
     *
     * ## The month's DAYS come from `Jalali`; their bounds are made here
     *
     * `Jalali::startOfMonth()` is the first day as a calendar date — 00:00 UTC, which is
     * 03:30 in Tehran — and `Jalali::endOfMonth()` is 23:59:59 UTC on the last day, 03:29
     * the next morning in Tehran. Used as they stand, "this month" began three and a half
     * hours late and ran three and a half hours into the next month.
     *
     * They are not changed at the source because the quota clock is built on them:
     * `PeriodClock::periodKey()` is `startOfMonth()->toDateString()`, and a Tehran-midnight
     * instant would move every `usage_counters` key back a day and refill every shop's
     * credit mid-month. So the first and last DAYS are read from them, and each is turned
     * into a shop day's bound here.
     */
    public static function thisMonth(?CarbonImmutable $now = null): self
    {
        $now ??= CarbonImmutable::now();

        $firstDay = Jalali::startOfMonth($now);
        // The last day as a calendar date, clamped to the month's length by `dayInMonthOf`.
        // Not `endOfMonth()`: 23:59:59 UTC is already the next Jalali day in Tehran.
        $lastDay = Jalali::dayInMonthOf($now, 31);

        return new self(
            Jalali::startOfDay($firstDay),
            Jalali::endOfDay($lastDay),
            Jalali::format($firstDay),
            Jalali::format($lastDay),
        );
    }

    /**
     * @return array{from: string, to: string, from_jalali: string, to_jalali: string}
     */
    public function toArray(): array
    {
        return [
            'from' => $this->from->toIso8601String(),
            'to' => $this->to->toIso8601String(),
            'from_jalali' => $this->fromJalali,
            'to_jalali' => $this->toJalali,
        ];
    }
}
