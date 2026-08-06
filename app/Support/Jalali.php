<?php

declare(strict_types=1);

namespace App\Support;

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

        $jalalian = Jalalian::fromCarbon(self::toDisplayTime($value));

        $formatted = $jalalian->format($format);

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

        return Jalalian::fromCarbon(self::toDisplayTime($value))->ago();
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
