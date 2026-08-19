<?php

declare(strict_types=1);

namespace App\Support;

use App\Support\Audit\Redactor;

/**
 * The request keys that must never be written down.
 *
 * ## Two doors, one list
 *
 * A value typed into a form has exactly two ways of escaping the request it arrived
 * in, and both of them write it somewhere durable:
 *
 * 1. **The session.** On a validation failure Laravel redirects with `withInput()`,
 *    which flashes the whole request body into the session store. `SESSION_DRIVER` is
 *    `database` and `SESSION_ENCRYPT` is false, so the value lands in
 *    `sessions.payload` in clear — one table over from the encrypted column that was
 *    supposed to be protecting it. Closed by `dontFlash()` in `bootstrap/app.php`.
 * 2. **The crash report.** Sentry attaches the request body to every event. A shop's
 *    repair intake throwing a 500 would ship the customer's unlock code to a
 *    third-party service, from where it is searchable by anyone with a Sentry login.
 *    Closed by {@see Observability\ScrubSensitiveData}.
 *
 * They are the same hole in two walls, and until this class existed only the first
 * one was patched. A list maintained in one place and forgotten in the other is worse
 * than no list, because the patched door is what everybody remembers.
 *
 * ## Why this one is written down when {@see Redactor} derives its own
 *
 * The audit-log redactor deliberately refuses a hardcoded list: it reads `$hidden`
 * and the `encrypted` casts off the model, so a new secret column is protected by the
 * same declaration that makes it a secret.
 *
 * That trick needs a model, and here there is none. These are **raw request keys** —
 * they exist before validation, before a model is resolved, and some of them
 * (`password_confirmation`, `otp`) never become a column at all. There is nothing to
 * derive from, so the list is explicit and this comment is the compensation.
 */
final class SensitiveInput
{
    /**
     * Names checked exactly, in both doors.
     *
     * @var list<string>
     */
    private const EXACT = [
        // Identity — sign-in and password change.
        'current_password',
        'password',
        'password_confirmation',

        // Repairs — the customer's unlock code or pattern. The reason this list exists:
        // the passcode is encrypted at rest precisely so a database dump shows nothing,
        // and a single failed intake put it in the clear in the same database.
        'device_passcode',

        // Identity — TOTP enrolment and recovery.
        'two_factor_secret',
        'two_factor_recovery_codes',
        'code',
        'otp',
        'token',

        // Treasury / Installments — a card or account number typed into a reference field.
        'card_number',
        'iban',
        'account_number',
    ];

    /**
     * Substrings that make a key suspicious, checked **only** by the crash-report door.
     *
     * The two doors are deliberately not equally aggressive, and the asymmetry is the
     * point:
     *
     * - Over-masking a crash report costs **nothing**. Nobody debugs from a request
     *   body they could have reconstructed from the stack trace anyway.
     * - Over-dropping a flashed field costs an operator **retyping it**, at a counter,
     *   with a customer waiting, after the form already rejected them once. That is a
     *   real cost paid by a real person, so `dontFlash` stays exact.
     *
     * So the shared list above is the **floor** for both, and this widens only the door
     * where widening is free. `MOADIAN_PRIVATE_KEY`, `kavenegar_api_key` and the next
     * integration credential nobody thought to add are caught here on the day they are
     * introduced rather than the day somebody reads this file.
     *
     * @var list<string>
     */
    private const SUSPICIOUS = [
        'password',
        'passcode',
        'secret',
        'token',
        'api_key',
        'apikey',
        'private_key',
        'credential',
        'authorization',
    ];

    /**
     * The exact-match list, for `dontFlash()`.
     *
     * @return list<string>
     */
    public static function keys(): array
    {
        return self::EXACT;
    }

    /**
     * Whether a crash report should mask this key.
     *
     * Case-insensitive: the same value arrives as `password` from a form, `PASSWORD`
     * from a header and `Password` from a JSON client, and a scrubber that only
     * catches one of the three reads as working.
     */
    public static function isSensitive(string $key): bool
    {
        $needle = mb_strtolower($key);

        if (in_array($needle, self::EXACT, true)) {
            return true;
        }

        foreach (self::SUSPICIOUS as $fragment) {
            if (str_contains($needle, $fragment)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Mask every sensitive key in a payload, at every depth.
     *
     * Recursive because a request body is not flat. A repair intake posts
     * `lines[0][device_passcode]`, and a scrubber that only reads the top level
     * reports itself as having scrubbed the request while shipping the value one
     * bracket down.
     *
     * @param  array<array-key, mixed>  $payload
     * @return array<array-key, mixed>
     */
    public static function scrub(array $payload): array
    {
        $scrubbed = [];

        foreach ($payload as $key => $value) {
            if (is_string($key) && self::isSensitive($key)) {
                $scrubbed[$key] = Redactor::MASK;

                continue;
            }

            $scrubbed[$key] = is_array($value) ? self::scrub($value) : $value;
        }

        return $scrubbed;
    }
}
