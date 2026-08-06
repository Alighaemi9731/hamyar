<?php

declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;

/**
 * Money handling for MobiShop.
 *
 * Golden rule 2: money is an integer number of **rial** (IRR). There is no float
 * anywhere in this class and no `/` that can produce one. Iranian shops quote prices
 * in *toman* (1 toman = 10 rial) but invoices, tax filings and the database are in
 * rial, so conversion happens at the presentation boundary only.
 *
 * Everything here is deliberately static and side-effect free: the same amount must
 * format identically in a controller, a queued job and a print template.
 */
final class Money
{
    /** Rial per toman. */
    public const RIAL_PER_TOMAN = 10;

    public const UNIT_RIAL = 'rial';

    public const UNIT_TOMAN = 'toman';

    /**
     * Group an integer rial amount with thousands separators.
     *
     * @param  int  $rial  amount in rial; negatives are rendered with a leading minus
     */
    public static function format(int $rial, string $unit = self::UNIT_RIAL, bool $persianDigits = false): string
    {
        $amount = self::inUnit($rial, $unit);

        $negative = $amount < 0;
        // Formatting the absolute value keeps the minus outside the digit grouping,
        // which is what both Persian and Latin readers expect.
        $digits = number_format(abs($amount), 0, '.', ',');

        $formatted = ($negative ? '-' : '').$digits;

        return $persianDigits ? Digits::toPersian($formatted) : $formatted;
    }

    /**
     * Format with the unit label appended, e.g. "۱٬۲۵۰٬۰۰۰ تومان".
     */
    public static function formatWithUnit(int $rial, string $unit = self::UNIT_RIAL, bool $persianDigits = false): string
    {
        $label = $unit === self::UNIT_TOMAN ? 'تومان' : 'ریال';

        return self::format($rial, $unit, $persianDigits).' '.$label;
    }

    /**
     * Convert a rial amount into the requested display unit.
     *
     * Toman conversion is exact division by 10. Sub-toman rial remainders cannot be
     * displayed without losing money, so we refuse rather than silently round: any
     * amount that is not a whole number of toman is a bug upstream (all our prices,
     * taxes and installment splits are computed to land on whole toman).
     */
    public static function inUnit(int $rial, string $unit): int
    {
        return match ($unit) {
            self::UNIT_RIAL => $rial,
            self::UNIT_TOMAN => self::toToman($rial),
            default => throw new InvalidArgumentException("Unknown currency unit [{$unit}]."),
        };
    }

    public static function toToman(int $rial): int
    {
        if ($rial % self::RIAL_PER_TOMAN !== 0) {
            throw new InvalidArgumentException(
                "Amount {$rial} rial is not a whole number of toman; refusing to round money."
            );
        }

        return intdiv($rial, self::RIAL_PER_TOMAN);
    }

    public static function fromToman(int $toman): int
    {
        return $toman * self::RIAL_PER_TOMAN;
    }

    /**
     * Parse user input (Persian or Latin digits, with or without separators) into rial.
     *
     * Accepts "1,250,000", "۱۲۵۰۰۰۰", " 1250000 ". Rejects anything with a decimal
     * point or a stray character rather than guessing.
     */
    public static function parse(string $input, string $unit = self::UNIT_RIAL): int
    {
        $normalised = str_replace([',', '٬', ' ', ' ', '‌'], '', Digits::toLatin(trim($input)));

        if ($normalised === '' || preg_match('/^-?\d+$/', $normalised) !== 1) {
            throw new InvalidArgumentException("Cannot parse [{$input}] as an integer money amount.");
        }

        $value = (int) $normalised;

        return $unit === self::UNIT_TOMAN ? self::fromToman($value) : $value;
    }

    /**
     * Split an amount into `$parts` instalments whose sum is exactly the input.
     *
     * The rounding remainder lands on the **last** part — the rule agreed for
     * installment plans (roadmap 5.5) — so a customer never sees a schedule that
     * fails to add up to the contract total.
     *
     * @return list<int>
     */
    public static function split(int $rial, int $parts): array
    {
        if ($parts < 1) {
            throw new InvalidArgumentException('Cannot split money into fewer than one part.');
        }

        $base = intdiv($rial, $parts);
        $result = array_fill(0, $parts, $base);
        $result[$parts - 1] = $rial - ($base * ($parts - 1));

        return array_values($result);
    }

    /**
     * Percentage of an amount, truncated toward zero so the shop never over-charges
     * by a rounding artefact. Percentages are integer basis-agnostic: pass 9 for 9%.
     */
    public static function percent(int $rial, int $percent): int
    {
        return intdiv($rial * $percent, 100);
    }
}
