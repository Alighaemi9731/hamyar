<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Support;

/**
 * The shop's wall clock, as a SQL expression.
 *
 * ## Why this is not written inline in each report
 *
 * Timestamps are stored UTC (golden rule 5) and every report that buckets by day must
 * bucket by the **Tehran** day, because that is the day the shop had. The sales report
 * shipped without this and put everything sold between midnight and 03:30 Tehran onto the
 * previous day's row — and, eleven times a year, into the previous Jalali month, where the
 * monthly report reported it. That defect is one `date()` call away in every report that
 * groups or ages by date, so the expression lives in one place and each report asks for it.
 *
 * ## The timezone is inlined, and it has to be
 *
 * A bound placeholder is the reflex and it does not work. Postgres compares `GROUP BY`
 * against `SELECT` **by expression**, and `$1` in the select list is not the same
 * expression as `$5` in the group-by even when both carry `Asia/Tehran` — so the statement
 * fails with «column … must appear in the GROUP BY clause», which reads like a query-shape
 * bug and is a binding one.
 *
 * It is safe to inline because it is not user input — `app.display_timezone` is config —
 * and it is *proved* safe rather than argued safe: anything outside the character set an
 * IANA zone name can contain is stripped, and an empty result falls back to UTC. A
 * tenancy-scoped report is the last place to leave a hole open on the grounds that nobody
 * can currently reach it.
 */
final class ShopClock
{
    /**
     * The configured display zone, reduced to characters an IANA name may contain.
     */
    public static function timezone(): string
    {
        $timezone = preg_replace('/[^A-Za-z0-9_+\-\/]/', '', config()->string('app.display_timezone'));

        if (! is_string($timezone) || $timezone === '') {
            return 'UTC';
        }

        return $timezone;
    }

    /**
     * A stored UTC column, read in the shop's wall clock.
     *
     * @param  string  $column  a qualified column name — never user input
     */
    public static function localOf(string $column): string
    {
        return sprintf("((%s at time zone 'UTC') at time zone '%s')", $column, self::timezone());
    }

    /**
     * The shop's calendar day for a stored UTC column.
     */
    public static function dayOf(string $column): string
    {
        return sprintf('date(%s)', self::localOf($column));
    }
}
