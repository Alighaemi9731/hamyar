<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Services;

use App\Support\Jalali;
use Carbon\CarbonImmutable;

/**
 * The date range every report is asked for, parsed once.
 *
 * ## Jalali in, UTC out
 *
 * A shopkeeper types «۱۴۰۵/۰۵/۰۱ تا ۱۴۰۵/۰۵/۳۱» and the database holds UTC timestamps.
 * Doing that conversion inside each of twenty-five reports is twenty-five chances to be a
 * day out at a month boundary — and a sales report that silently omits the 31st is the kind
 * of wrong nobody notices until they add the months up and the year does not match.
 *
 * So it happens here, once, and every report takes one of these rather than two strings.
 *
 * ## The end of the range is inclusive
 *
 * «تا ۳۱ مرداد» means including the 31st, all of it. A range built as `<= 2026-08-22
 * 00:00:00` drops everything that happened that day, which is most of a shop's business on
 * the day somebody is asking about.
 *
 * ## A backwards range is swapped, not rejected
 *
 * Somebody typing 1405/05/31 into the "from" box has mistyped, not asked for nothing.
 * Returning an empty report leaves them to work out why; swapping gives them what they
 * meant and costs nothing.
 */
final readonly class ReportPeriod
{
    private function __construct(
        public CarbonImmutable $from,
        public CarbonImmutable $to,
        public string $fromJalali,
        public string $toJalali,
    ) {}

    /**
     * From two Jalali date strings, as a filter form submits them.
     *
     * Persian digits are normalised by {@see Jalali::parse()}, so «۱۴۰۵/۰۵/۰۱» and
     * "1405/05/01" behave identically — a shop's staff type both, often in one session.
     */
    public static function fromJalali(?string $from, ?string $to): self
    {
        $start = $from === null || $from === ''
            ? Jalali::startOfMonth(CarbonImmutable::now())
            : Jalali::startOfDay($from);

        $end = $to === null || $to === ''
            ? CarbonImmutable::now()->endOfDay()
            : Jalali::endOfDay($to);

        if ($start->greaterThan($end)) {
            [$start, $end] = [$end->startOfDay(), $start->endOfDay()];
        }

        return new self($start, $end, Jalali::format($start), Jalali::format($end));
    }

    public static function of(CarbonImmutable $from, CarbonImmutable $to): self
    {
        return new self(
            $from->startOfDay(),
            $to->endOfDay(),
            Jalali::format($from),
            Jalali::format($to),
        );
    }

    /**
     * The current Jalali month — what a dashboard opens on.
     *
     * `Jalali::startOfMonth()`, not Carbon's: the first of the Gregorian month falls in the
     * middle of the Jalali one, and "this month" would cover parts of two.
     */
    public static function thisMonth(?CarbonImmutable $now = null): self
    {
        $now ??= CarbonImmutable::now();

        return new self(
            Jalali::startOfMonth($now),
            Jalali::endOfMonth($now),
            Jalali::format(Jalali::startOfMonth($now)),
            Jalali::format(Jalali::endOfMonth($now)),
        );
    }

    /**
     * @return array{from: string, to: string, from_jalali: string, to_jalali: string}
     */
    public function toArray(): array
    {
        return [
            'from' => $this->from->toIso8601String(),
            'to' => $this->to->toIso8601String(),
            'from_jalali' => $this->fromJalali,
            'to_jalali' => $this->toJalali,
        ];
    }
}
