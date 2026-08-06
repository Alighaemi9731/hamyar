<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Persian ↔ Latin digit conversion.
 *
 * Design-system rule 4: Persian digits in prose, Latin tabular digits in tables and
 * invoices, switchable per tenant. Both directions are needed — Persian for output,
 * Latin for anything a user typed (an IMEI scanned on a Persian keyboard, an amount
 * pasted from another app) before it reaches validation or the database.
 *
 * Arabic-Indic digits (٠١٢…) are also normalised on input, because Iranian keyboards
 * and Arabic-locale devices produce them interchangeably with Persian ones.
 */
final class Digits
{
    /** @var list<string> */
    private const LATIN = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];

    /** @var list<string> */
    private const PERSIAN = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];

    /** @var list<string> */
    private const ARABIC = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];

    /** Latin comma → Persian thousands separator. */
    private const LATIN_SEPARATOR = ',';

    private const PERSIAN_SEPARATOR = '٬';

    public static function toPersian(string $value): string
    {
        return str_replace(
            [...self::LATIN, self::LATIN_SEPARATOR],
            [...self::PERSIAN, self::PERSIAN_SEPARATOR],
            $value
        );
    }

    /**
     * Normalise any digit form the user could have typed into Latin digits.
     */
    public static function toLatin(string $value): string
    {
        return str_replace(
            [...self::PERSIAN, ...self::ARABIC, self::PERSIAN_SEPARATOR],
            [...self::LATIN, ...self::LATIN, self::LATIN_SEPARATOR],
            $value
        );
    }
}
