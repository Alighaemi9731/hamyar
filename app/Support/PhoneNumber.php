<?php

declare(strict_types=1);

namespace App\Support;

/**
 * An Iranian mobile number, in the one shape a gateway accepts.
 *
 * ## Why normalisation is not cosmetic
 *
 * The same customer's number arrives as `09121234567` from the till, `+989121234567` from
 * an import, `989121234567` from a spreadsheet, and `۰۹۱۲۱۲۳۴۵۶۷` from somebody typing on
 * a Persian keyboard. All four are one person. Left alone they are four rows an opt-out
 * list matches none of, four entries in a campaign audience, and four charges against a
 * credit wallet for one message.
 *
 * **The opt-out consequence is the serious one.** A customer who opted out as
 * `+989121234567` and is stored on an invoice as `09121234567` keeps receiving messages,
 * and that is the complaint that reaches the regulator.
 *
 * So there is exactly one canonical form — `+98` followed by ten digits — produced here,
 * once, by everything that sends or suppresses.
 *
 * ## Persian and Arabic digits normalise first
 *
 * `۰۹۱۲` and `٠٩١٢` are the same number typed on different keyboards. A shop's staff use
 * both, often in one session, and a comparison treating them as different strings is a bug
 * that only appears in this market.
 */
final class PhoneNumber
{
    private const PERSIAN = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];

    private const ARABIC = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];

    /**
     * The canonical `+98…` form, or null when this cannot be an Iranian mobile number.
     *
     * Null rather than a throw: a party imported from a shop's old spreadsheet may hold a
     * landline, a note, or nothing at all, and a campaign that crashed on the first bad row
     * would be useless. The caller skips what it cannot send to.
     */
    public static function normalise(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        $digits = preg_replace('/\D/', '', self::latinDigits($raw)) ?? '';

        // 0912…, 98912…, +98912… and 912… all collapse to the same ten digits beginning
        // with 9, which is what every Iranian mobile number starts with.
        $ten = match (true) {
            str_starts_with($digits, '0098') => substr($digits, 4),
            str_starts_with($digits, '98') && strlen($digits) === 12 => substr($digits, 2),
            str_starts_with($digits, '0') && strlen($digits) === 11 => substr($digits, 1),
            default => $digits,
        };

        if (strlen($ten) !== 10 || ! str_starts_with($ten, '9')) {
            return null;
        }

        return '+98'.$ten;
    }

    public static function isSendable(?string $raw): bool
    {
        return self::normalise($raw) !== null;
    }

    /**
     * What a shopkeeper expects to see: `0912…`.
     *
     * Storage and comparison are canonical; display is local. Showing `+98` on a Persian
     * invoice looks like somebody else's software.
     */
    public static function forDisplay(?string $raw): ?string
    {
        $normalised = self::normalise($raw);

        return $normalised === null ? null : '0'.substr($normalised, 3);
    }

    private static function latinDigits(string $value): string
    {
        return str_replace(
            [...self::PERSIAN, ...self::ARABIC],
            [...range('0', '9'), ...range('0', '9')],
            $value,
        );
    }
}
