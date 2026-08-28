<?php

declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;

/**
 * Money handling for Hamyar.
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
     * Accepts "1,250,000", "۱۲۵۰۰۰۰", " 1250000 ", and a fractional amount written with
     * any of the three decimal marks in circulation here: the Persian slash
     * («۱۲۵۰/۵»), the Arabic decimal separator U+066B, or a Latin point.
     *
     * ## Why the slash matters
     *
     * An Iranian sheet writes a decimal with `/`, and the obvious way to normalise money
     * — strip everything that is not a digit — **concatenates the fraction onto the
     * amount**: `12500000/0` toman becomes 125000000 toman, ten times the price, with no
     * error anywhere. That was live in the customer import (opening balances and credit
     * limits) and is what this method now exists to prevent. A dot is worse: `12500000.00`
     * lands a hundred times high.
     *
     * ## Exact, and integer throughout
     *
     * Golden rule 2 forbids a float anywhere near money, so the fraction is carried as an
     * integer numerator over a power-of-ten scale and the division happens once, only
     * after it has been proven exact. An amount that does not land on a whole rial is
     * **rejected, never rounded** — the same conservatism as {@see toToman}. So
     * `1250/5` toman is a legitimate 12,505 rial, while `1250/55` toman is 12,505.5 rial
     * and throws.
     *
     * Two decimal marks in one value (a European-style `12.500.000`) are ambiguous
     * between grouping and fraction, and are rejected rather than guessed.
     */
    public static function parse(string $input, string $unit = self::UNIT_RIAL): int
    {
        if (! in_array($unit, [self::UNIT_RIAL, self::UNIT_TOMAN], true)) {
            throw new InvalidArgumentException("Unknown currency unit [{$unit}].");
        }

        // Thousands grouping goes first: a separator is noise, and `Digits::toLatin`
        // has already turned the Persian mark into a comma. The three space-like
        // characters are a plain space, a non-breaking space and a ZWNJ, all of which
        // a Persian keyboard produces inside numbers.
        $normalised = str_replace([',', '٬', ' ', ' ', '‌'], '', Digits::toLatin(trim($input)));

        $negative = str_starts_with($normalised, '-');
        $normalised = ltrim($normalised, '-');

        // Every decimal mark becomes one character so there is a single thing to count.
        $normalised = str_replace(['/', '٫'], '.', $normalised);

        if ($normalised === '' || substr_count($normalised, '.') > 1) {
            throw new InvalidArgumentException("Cannot parse [{$input}] as a money amount.");
        }

        [$whole, $fraction] = array_pad(explode('.', $normalised), 2, '');

        if (preg_match('/^\d+$/', $whole) !== 1 || ($fraction !== '' && preg_match('/^\d+$/', $fraction) !== 1)) {
            throw new InvalidArgumentException("Cannot parse [{$input}] as a money amount.");
        }

        // A cap, because `10 ** strlen($fraction)` overflows silently past 18 digits and
        // no real price carries seven decimal places.
        if (strlen($fraction) > 6) {
            throw new InvalidArgumentException("Amount [{$input}] carries more decimal places than any price has.");
        }

        $scale = 10 ** strlen($fraction);
        $multiplier = $unit === self::UNIT_TOMAN ? self::RIAL_PER_TOMAN : 1;

        $numerator = ((int) $whole * $scale + (int) ($fraction === '' ? '0' : $fraction)) * $multiplier;

        if ($numerator % $scale !== 0) {
            throw new InvalidArgumentException(
                "Amount [{$input}] is not a whole number of rial; refusing to round money."
            );
        }

        $rial = intdiv($numerator, $scale);

        return $negative ? -$rial : $rial;
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

    /**
     * Raise a rial amount to the next whole toman.
     *
     * ## Why anything needs this
     *
     * {@see toToman()} refuses a sub-toman remainder rather than rounding, on the stated
     * grounds that "any amount that is not a whole number of toman is a bug upstream".
     * That is true of every amount a **person** enters and of every price, tax and
     * instalment split — and it is not true of a **derived** unit cost. A weighted average
     * is a division: a hundred chargers at 50,000 and ten at 90,000 come to 53,636 rial
     * each, which is 5,363.6 toman and unrenderable.
     *
     * So a derived per-unit cost is normalised here, at the point it is derived, rather
     * than at the point somebody tries to display it — where it surfaces as an exception
     * thrown from a report the shop opens every morning.
     *
     * ## Up, not down
     *
     * Cost rounds **away from zero**: understating what something cost overstates the
     * profit on it, and a report that flatters the shop is the wrong direction to be
     * wrong in. The counterpart is {@see percent()}, which truncates so the shop never
     * over-charges — the same principle, pointed at the customer instead.
     *
     * The principle is stated once for the whole family in `docs/adr/0009-invoice-rounding.md`
     * (Amendment): every rounding of a derived figure goes in the direction that does not
     * flatter the party doing the rounding. That is why VAT floors and this ceils.
     *
     * The adjustment is at most nine rial on a unit cost, which is a rounding artefact
     * rather than money; what matters is that every figure derived from it can be shown.
     */
    public static function ceilToToman(int $rial): int
    {
        if ($rial % self::RIAL_PER_TOMAN === 0) {
            return $rial;
        }

        $step = $rial < 0 ? -self::RIAL_PER_TOMAN : self::RIAL_PER_TOMAN;

        return intdiv($rial, self::RIAL_PER_TOMAN) * self::RIAL_PER_TOMAN + $step;
    }

    /**
     * The wire shape for money crossing into Inertia or an API resource.
     *
     * Both halves matter. `value` is the integer rial the frontend does arithmetic and
     * comparisons on; `formatted` is the string it renders. Sending only the formatted
     * string forces the client to parse Persian digits back into a number, and sending
     * only the integer scatters formatting rules across components that will drift.
     *
     * @return array{value: int, formatted: string}
     */
    public static function toArray(int $rial, string $unit = self::UNIT_TOMAN): array
    {
        return [
            'value' => $rial,
            'formatted' => self::formatWithUnit($rial, $unit),
        ];
    }
}
