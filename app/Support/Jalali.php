<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use InvalidArgumentException;
use Morilog\Jalali\Jalalian;

/**
 * Jalali (Solar Hijri) rendering and parsing.
 *
 * Golden rule 5: timestamps are stored as UTC and rendered as Jalali. A Jalali string
 * never reaches the database, and this class is the only place the conversion lives.
 *
 * The two-step nature matters and is easy to get wrong: a UTC instant must first be
 * shifted into the shop's wall-clock timezone (Asia/Tehran) and only then converted to
 * the Jalali calendar. Converting UTC directly gives the wrong *day* for anything
 * between 20:30 and midnight Tehran time — which is exactly when a phone shop is
 * closing its till.
 */
final class Jalali
{
    public const DATE = 'Y/m/d';

    public const DATE_TIME = 'Y/m/d H:i';

    public const LONG = 'j F Y';

    /**
     * Render an instant as a Jalali string in the shop's display timezone.
     *
     * @param  DateTimeInterface|string|int|null  $value  null renders an empty string, so
     *                                                    templates do not need null guards
     */
    public static function format(
        DateTimeInterface|string|int|null $value,
        string $format = self::DATE,
        bool $persianDigits = true,
    ): string {
        if ($value === null || $value === '') {
            return '';
        }

        $formatted = self::jalalian($value)->format($format);

        return $persianDigits ? Digits::toPersian($formatted) : $formatted;
    }

    /**
     * Human-relative Persian rendering, e.g. «۳ روز پیش».
     */
    public static function ago(DateTimeInterface|string|int|null $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        return self::jalalian($value)->ago();
    }

    /**
     * morilog/jalali predates CarbonImmutable and its signature still demands the
     * mutable class, so the conversion is confined to this one place — the rest of
     * the application stays immutable (see AppServiceProvider::configureDates).
     */
    private static function jalalian(DateTimeInterface|string|int $value): Jalalian
    {
        return Jalalian::fromCarbon(Carbon::instance(self::toDisplayTime($value)));
    }

    /**
     * Parse a Jalali string into a UTC instant ready for storage.
     *
     * Persian digits are normalised first, so a value typed on a Persian keyboard
     * (۱۴۰۵/۰۵/۱۵) parses identically to its Latin form.
     */
    public static function parse(string $value, string $format = self::DATE): CarbonImmutable
    {
        $normalised = Digits::toLatin(trim($value));

        if ($normalised === '') {
            throw new InvalidArgumentException('Cannot parse an empty Jalali date.');
        }

        return CarbonImmutable::instance(
            Jalalian::fromFormat($format, $normalised)
                ->toCarbon()
                ->setTimezone(self::displayTimezone())
        )->utc();
    }

    /**
     * Start of a Jalali day, as a UTC instant — the lower bound for date-range filters.
     */
    public static function startOfDay(string $value, string $format = self::DATE): CarbonImmutable
    {
        return self::parse($value, $format)->setTimezone(self::displayTimezone())->startOfDay()->utc();
    }

    /**
     * End of a Jalali day, as a UTC instant — the upper bound for date-range filters.
     */
    public static function endOfDay(string $value, string $format = self::DATE): CarbonImmutable
    {
        return self::parse($value, $format)->setTimezone(self::displayTimezone())->endOfDay()->utc();
    }

    /**
     * The same day of the month, N Jalali months later.
     *
     * ## Why this is not `addMonths()` on the underlying Carbon instance
     *
     * A Jalali year is six months of 31 days, five of 30, and one of 29 or 30. An
     * instalment due on ۱۵ اردیبهشت and the next one due "a month later" fall 31 days
     * apart; the pair after that, 31 again; the ones in the second half of the year, 30.
     * Adding a fixed number of days drifts, and adding Gregorian months lands on the
     * wrong Jalali day — by the twelfth instalment of a year-long plan the schedule is
     * days away from the date on the contract the customer signed.
     *
     * The month arithmetic therefore happens in the Jalali calendar and the result comes
     * back as a UTC instant, which is what gets stored (golden rule 5).
     *
     * A day that does not exist in the target month — the 31st of a 30-day month, or ۳۰
     * اسفند in an ordinary year — clamps to the last day of that month, which is what
     * `Jalalian` does and what a shop means by "the same date next month".
     */
    public static function addMonths(DateTimeInterface|string|int $value, int $months): CarbonImmutable
    {
        // Zero is the ordinary case for the first row of a schedule, and `Jalalian`
        // asserts a positive count — so it is answered here rather than making every
        // caller special-case its own first iteration.
        if ($months === 0) {
            return CarbonImmutable::instance(self::toDisplayTime($value))->utc();
        }

        $jalalian = self::jalalian($value);

        return CarbonImmutable::instance(
            ($months > 0 ? $jalalian->addMonths($months) : $jalalian->subMonths(-$months))->toCarbon()
        )->utc();
    }

    /**
     * The first instant of the Jalali month a moment falls in.
     *
     * Not `Carbon::startOfMonth()`, which finds the first of the GREGORIAN month — a date
     * that falls somewhere in the middle of the Jalali one and produces a "this month"
     * report covering parts of two. The error is invisible for most of the year and
     * obvious to a shopkeeper adding their months up.
     */
    public static function startOfMonth(DateTimeInterface|string|int $value): CarbonImmutable
    {
        return self::dayInMonthOf($value, 1);
    }

    /**
     * The last instant of the Jalali month a moment falls in.
     *
     * Jalali months are 31, 30 or 29 days depending on which one and whether the year is a
     * leap year, so the length comes from the calendar rather than from a constant.
     */
    public static function endOfMonth(DateTimeInterface|string|int $value): CarbonImmutable
    {
        return self::dayInMonthOf($value, self::jalalian($value)->getMonthDays())->endOfDay();
    }

    /**
     * The Jalali month a moment falls in, as `1405-06`.
     *
     * The identity of a period, used by anything that must book something once per month
     * and know later that it did — recurring expenses, rent, and any future statement
     * that groups by the calendar the shop actually works in.
     *
     * A Gregorian `Y-m` key would be wrong for exactly the reason this class exists: a
     * Jalali month straddles two Gregorian ones, so «اجاره مرداد» would land in two
     * different buckets depending on which day it was booked.
     */
    public static function monthKey(DateTimeInterface|string|int $value): string
    {
        $jalalian = self::jalalian($value);

        return sprintf('%04d-%02d', $jalalian->getYear(), $jalalian->getMonth());
    }

    /**
     * A given day of the Jalali month that `$value` falls in, clamped to its length.
     *
     * Clamped rather than overflowing: rent due «سی‌ویکم هر ماه» must still fall due in a
     * thirty-day month, on the thirtieth. Rolling into the next month would book two
     * months' rent in one and none in the other, and skipping would quietly lose a month
     * every time Esfand came round.
     */
    public static function dayInMonthOf(DateTimeInterface|string|int $value, int $day): CarbonImmutable
    {
        $jalalian = self::jalalian($value);

        $clamped = max(1, min($day, $jalalian->getMonthDays()));

        return CarbonImmutable::instance(
            (new Jalalian($jalalian->getYear(), $jalalian->getMonth(), $clamped))->toCarbon()
        )->utc()->startOfDay();
    }

    /**
     * Today in the shop's timezone, as a Jalali string. Used for date-picker defaults.
     */
    public static function today(string $format = self::DATE, bool $persianDigits = true): string
    {
        return self::format(CarbonImmutable::now(), $format, $persianDigits);
    }

    private static function toDisplayTime(DateTimeInterface|string|int $value): CarbonImmutable
    {
        $instant = match (true) {
            $value instanceof DateTimeInterface => CarbonImmutable::instance($value),
            is_int($value) => CarbonImmutable::createFromTimestampUTC($value),
            default => CarbonImmutable::parse($value, 'UTC'),
        };

        return $instant->setTimezone(self::displayTimezone());
    }

    private static function displayTimezone(): string
    {
        /** @var string $timezone */
        $timezone = config('app.display_timezone', 'Asia/Tehran');

        return $timezone;
    }
}
