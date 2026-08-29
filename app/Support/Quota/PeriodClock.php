<?php

declare(strict_types=1);

namespace App\Support\Quota;

use App\Support\Jalali;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Which period a shop is spending right now, and when the next one starts.
 *
 * ## Why the key is a Gregorian date for a Jalali month
 *
 * The period key identifies one `usage_counters` row and has three jobs: be unique per
 * period, sort, and be groupable in SQL. `Jalali::monthKey()` gives «1405-06», which does
 * the first two and breaks golden rule 5 — a Jalali string in a column. So the key is the
 * **Gregorian date of the Tehran day the Jalali month begins on**, e.g. `2026-08-23` for
 * شهریور ۱۴۰۵: ten characters, fixed width, sortable, comparable against `date(...)` in
 * SQL, immune to being rendered in Persian digits by accident, and a real date rather than
 * a calendar-flavoured string. Jalali stays where it belongs — on the screen, via
 * {@see label()}.
 *
 * ## Why the wall clock and not the document's date
 *
 * The key is computed when the credit is spent, never from a user-supplied date. An
 * expense backdated to last month still spends this month's credit, and a campaign job
 * that runs after the turn of the month spends the new month's. Anything else lets a shop
 * choose which bucket to bill by typing a date.
 *
 * The boundary is 00:00 Asia/Tehran, which is 20:30 UTC — taken from the tz database, not
 * a fixed offset. Getting it wrong by three and a half hours would refill a shop's credit
 * while it is still closing yesterday's till.
 */
final class PeriodClock
{
    public function __construct(private readonly string $timezone) {}

    /**
     * Now, in the shop's wall clock.
     */
    public function nowLocal(): CarbonImmutable
    {
        return CarbonImmutable::now()->setTimezone($this->timezone);
    }

    /**
     * The current period's key for a counted window.
     *
     * @throws InvalidArgumentException for {@see Window::Total}, which has no period and
     *                                  therefore no row — asking for one is a bug worth
     *                                  surfacing rather than answering with `''`
     */
    public function periodKey(Window $window, ?CarbonImmutable $at = null): string
    {
        if (! $window->isCounted()) {
            throw new InvalidArgumentException(
                "Window {$window->value} has no period key: it is a standing capacity, measured from live rows."
            );
        }

        // `startOfMonth()` resolves the Jalali month through the display timezone and
        // hands back that month's first day at 00:00 UTC, so its DATE part is exactly the
        // key — read straight off, with no `setTimezone()` in between. Shifting it into
        // the shop's zone first would be a no-op for Tehran (+03:30 keeps the same date)
        // and would silently move the key back a day for any zone behind UTC.
        return Jalali::startOfMonth($at ?? CarbonImmutable::now())->toDateString();
    }

    /**
     * When the current credit is refilled, as a UTC instant, or null for a window that
     * never resets.
     */
    public function resetsAt(Window $window, ?CarbonImmutable $at = null): ?CarbonImmutable
    {
        if (! $window->isCounted()) {
            return null;
        }

        $now = $at ?? CarbonImmutable::now();

        // Jalali month arithmetic, not Carbon's: a Jalali month straddles two Gregorian
        // ones, so `addMonth()` here would land mid-month roughly every other time.
        //
        // And then the day is turned back into an instant IN THE SHOP'S ZONE, which is
        // the part that is easy to get wrong: `Jalali::startOfMonth()` returns midnight
        // **UTC** on that date, and the credit actually refills at midnight **Tehran** —
        // three and a half hours earlier. Returning the helper's instant unchanged would
        // have the screen promise a refill at ۰۳:۳۰ while the counter had already reset at
        // ۰۰:۰۰, and the only symptom is a shopkeeper told to wait for something that has
        // already happened.
        $nextKey = Jalali::startOfMonth(Jalali::addMonths($now, 1))->toDateString();

        return CarbonImmutable::parse($nextKey, $this->timezone)->startOfDay()->utc();
    }

    /**
     * What the shop calls this period — «شهریور ۱۴۰۵». For screens only; never stored.
     */
    public function label(Window $window, ?CarbonImmutable $at = null): string
    {
        if (! $window->isCounted()) {
            return 'ظرفیت کل';
        }

        return Jalali::format($at ?? CarbonImmutable::now(), 'F Y');
    }
}
