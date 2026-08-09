<?php

declare(strict_types=1);

namespace App\Support;

/**
 * IMEI validation and normalisation.
 *
 * An IMEI is 15 digits where the last is a Luhn check digit. Validating it catches the
 * everyday case this product exists to prevent: a number mistyped at intake, which then
 * fails to match when the same phone is sold, warranty-claimed, or checked against HAMTA
 * — by which time the paperwork trail is already broken.
 *
 * Persian and Arabic-Indic digits are normalised first. Iranian staff type on Persian
 * keyboards and paste from Persian documents, so `۳۵۲۰۹۹...` must be accepted as readily
 * as `352099...`.
 */
final class Imei
{
    public const LENGTH = 15;

    /**
     * Strip separators and convert Persian/Arabic digits to Latin.
     *
     * Returns digits only, so a caller can compare two IMEIs written differently.
     */
    public static function normalise(string $input): string
    {
        $latin = Digits::toLatin($input);

        return preg_replace('/\D+/', '', $latin) ?? '';
    }

    /**
     * A syntactically valid IMEI: 15 digits with a correct Luhn check digit.
     */
    public static function isValid(string $input): bool
    {
        $digits = self::normalise($input);

        if (strlen($digits) !== self::LENGTH) {
            return false;
        }

        // All-zeros passes Luhn but is never a real device — it is what a broken scanner
        // or a placeholder entry produces.
        if ($digits === str_repeat('0', self::LENGTH)) {
            return false;
        }

        return self::luhnChecksum($digits) === 0;
    }

    /**
     * The check digit that makes a 14-digit body valid. Useful for test fixtures and for
     * completing a number a scanner truncated.
     */
    public static function checkDigitFor(string $body): int
    {
        $digits = self::normalise($body);

        // Compute as though a zero check digit were appended, then take the complement.
        $remainder = self::luhnChecksum($digits.'0');

        return $remainder === 0 ? 0 : 10 - $remainder;
    }

    /**
     * The Type Allocation Code — the first 8 digits, identifying model and manufacturer.
     * Two devices of the same model share it, which makes it a useful grouping key.
     */
    public static function tac(string $input): ?string
    {
        $digits = self::normalise($input);

        return strlen($digits) >= 8 ? substr($digits, 0, 8) : null;
    }

    /**
     * Luhn sum modulo 10. Zero means the number checks out.
     */
    private static function luhnChecksum(string $digits): int
    {
        $sum = 0;
        $double = false;

        // Right to left: every second digit is doubled, and a result above 9 has its
        // digits added together (equivalently, 9 subtracted).
        for ($i = strlen($digits) - 1; $i >= 0; $i--) {
            $value = (int) $digits[$i];

            if ($double) {
                $value *= 2;

                if ($value > 9) {
                    $value -= 9;
                }
            }

            $sum += $value;
            $double = ! $double;
        }

        return $sum % 10;
    }
}
