<?php

declare(strict_types=1);

namespace App\Modules\Platform\Support;

/**
 * Where to put a shopkeeper back after they have paid.
 *
 * A shop hits its monthly cap mid-sale, presses «ارتقا», pays at the gateway, and comes
 * back. Landing them on the billing receipt means walking back to the till and rebuilding
 * a basket they had already typed — the upgrade worked and the sale still did not happen,
 * which is how a paid feature reads as broken. So the path they were on travels with the
 * payment and brings them home.
 *
 * ## This class exists because it is an open-redirect hole otherwise
 *
 * The value starts life in a query string, survives a round trip through a payment gateway,
 * and comes back to be handed straight to `redirect()`. That is the exact shape of an
 * open redirect, and the payment callback is the worst place in the product to have one:
 * `https://.../billing/callback?...` is a link a customer has been trained to trust, and a
 * value we accepted could bounce them to a page wearing our own branding and asking for a
 * card.
 *
 * So this is an allow-list, not a sanitiser. Anything not obviously a path on our own site
 * is discarded and the caller falls back to the receipt — losing a convenience, never the
 * payment.
 *
 * What is rejected, and why each one matters:
 *
 * - **Anything not starting with `/`** — `evil.test/x` is a relative path to the browser
 *   only until something prepends a scheme.
 * - **`//evil.test`** — a protocol-relative URL. It starts with `/`, and every browser
 *   reads it as a *host*. The single most missed case in hand-rolled checks of this kind.
 * - **`/\evil.test` and `/\t/`** — backslashes and control characters, which browsers and
 *   servers disagree about. Where two parsers disagree, one of them is the attacker's.
 * - **Anything with a scheme** — `javascript:`, `data:`, `http:`; caught by the `/` rule,
 *   and re-checked because a rule that only holds by accident is one refactoring away
 *   from not holding.
 * - **Anything over 512 characters** — the column is bounded, and a path that long is not
 *   a screen anybody was on.
 *
 * The query string is kept: `/sales/pos?branch=3` is a different screen from `/sales/pos`,
 * and it is the one they were looking at. The fragment is dropped — the server never sees
 * one anyway.
 */
final class ReturnPath
{
    /** Bounded so the column is bounded, and because no real screen path is this long. */
    public const MAX_LENGTH = 512;

    /**
     * The path if it is safe to send somebody back to, otherwise null.
     */
    public static function sanitise(mixed $candidate): ?string
    {
        if (! is_string($candidate) || $candidate === '') {
            return null;
        }

        if (strlen($candidate) > self::MAX_LENGTH) {
            return null;
        }

        // Control characters, including the tab and newline that let a value mean one
        // thing to a validator and another to whatever finally parses it.
        if (preg_match('/[\x00-\x1F\x7F]/', $candidate) === 1) {
            return null;
        }

        // A backslash is a path separator to some parsers and not others; there is no
        // legitimate one in a route this application generates.
        if (str_contains($candidate, '\\')) {
            return null;
        }

        // One leading slash exactly. `//host` and `/\host` are hosts, not paths.
        if (! str_starts_with($candidate, '/') || str_starts_with($candidate, '//')) {
            return null;
        }

        // Belt and braces: nothing above can currently produce a scheme, and this stays
        // true even if something above is relaxed.
        if (preg_match('#^/*[a-z][a-z0-9+.-]*:#i', $candidate) === 1) {
            return null;
        }

        // Parsed rather than trusted: if PHP can find a host or a scheme in here at all,
        // whatever we thought this was, it is not just a path.
        $parts = parse_url($candidate);

        if ($parts === false || isset($parts['host']) || isset($parts['scheme'])) {
            return null;
        }

        $path = $parts['path'] ?? '/';
        $query = isset($parts['query']) ? '?'.$parts['query'] : '';

        return $path.$query;
    }
}
