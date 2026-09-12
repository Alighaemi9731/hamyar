<?php

declare(strict_types=1);

use App\Modules\Reporting\Services\ReportPeriod;
use Carbon\CarbonImmutable;

/**
 * A report range is a run of shop days: 00:00 in Tehran on the first to the last
 * microsecond of the last, as UTC instants.
 *
 * ## The defect this file exists for
 *
 * `ReportPeriod` built three of its bounds with Carbon's `startOfDay()`/`endOfDay()` on UTC
 * values — the default "to", the backwards-range swap, and `of()`. Those are UTC midnights,
 * 03:30 in Tehran. The default range's "to" was therefore 03:29 TOMORROW on the shop's
 * clock, and its label read tomorrow's date. And in the first three and a half hours of a
 * Jalali month the default range came out backwards — its "from" (the month's first day at
 * 00:00 UTC) was after its "to" — and was swapped into a range that started on the last
 * day of the previous month.
 *
 * Every instant below is written in UTC, and the Tehran wall clock is in the comment.
 */
beforeEach(function (): void {
    // Pinned rather than inherited: every instant below is chosen against +03:30.
    config(['app.display_timezone' => 'Asia/Tehran']);
});

it('opens the default range on the first of the Jalali month, in its first minutes', function (): void {
    // 00:30 in Tehran on ۱ شهریور ۱۴۰۵. The UTC date is still ۳۱ مرداد.
    $this->travelTo(CarbonImmutable::parse('2026-08-22 21:00:00', 'UTC'));

    $period = ReportPeriod::fromJalali(null, null);

    // Tehran midnight of ۱ شهریور to the end of the same shop day. The old range came out
    // backwards, was swapped onto UTC midnights — 03:30 on ۳۱ مرداد to 03:29 on ۲ شهریور in
    // Tehran — and was labelled ۱۴۰۵/۰۵/۳۱ to ۱۴۰۵/۰۶/۰۲.
    expect($period->from->toIso8601ZuluString('microsecond'))->toBe('2026-08-22T20:30:00.000000Z')
        ->and($period->to->toIso8601ZuluString('microsecond'))->toBe('2026-08-23T20:29:59.999999Z')
        ->and($period->fromJalali)->toBe('۱۴۰۵/۰۶/۰۱')
        ->and($period->toJalali)->toBe('۱۴۰۵/۰۶/۰۱');
});

it('ends the default range tonight on the shop clock, and labels it today', function (string $now, string $today, string $endsAt): void {
    $this->travelTo(CarbonImmutable::parse($now, 'UTC'));

    $period = ReportPeriod::fromJalali(null, null);

    expect($period->toJalali)->toBe($today)
        ->and($period->to->toIso8601ZuluString('microsecond'))->toBe($endsAt);
})->with([
    // 00:30 in Tehran on ۲۲ شهریور. The old "to" was 23:59:59 UTC on the 12th — 03:29 on
    // the 22nd in Tehran: the right label by accident, and three and a half hours of the
    // day missing from the end of it.
    'just after Tehran midnight' => ['2026-09-12 21:00:00', '۱۴۰۵/۰۶/۲۲', '2026-09-13T20:29:59.999999Z'],
    // 13:30 in Tehran on ۲۲ شهریور. The old "to" was 03:29 on the 23rd in Tehran, and the
    // range was labelled with tomorrow's date.
    'mid-afternoon' => ['2026-09-13 10:00:00', '۱۴۰۵/۰۶/۲۲', '2026-09-13T20:29:59.999999Z'],
]);

it('swaps a backwards range onto whole shop days', function (): void {
    // 00:30 in Tehran — the hour does not change a typed range, and is here so it cannot.
    $this->travelTo(CarbonImmutable::parse('2026-09-12 21:00:00', 'UTC'));

    $period = ReportPeriod::fromJalali('1405/06/22', '1405/06/20');

    // The whole of the 20th to the whole of the 22nd. The old swap took UTC midnights:
    // 03:30 on the 20th to 03:29 on the 22nd on the shop's clock — nearly all of the last
    // day the shopkeeper asked for was missing.
    expect($period->from->toIso8601ZuluString('microsecond'))->toBe('2026-09-10T20:30:00.000000Z')
        ->and($period->to->toIso8601ZuluString('microsecond'))->toBe('2026-09-13T20:29:59.999999Z')
        ->and($period->fromJalali)->toBe('۱۴۰۵/۰۶/۲۰')
        ->and($period->toJalali)->toBe('۱۴۰۵/۰۶/۲۲');
});

it('bounds a range built from instants by the shop days they fall on', function (): void {
    // 00:30 in Tehran on ۲۲ شهریور — the dashboard asking for "today".
    $now = CarbonImmutable::parse('2026-09-12 21:00:00', 'UTC');

    $period = ReportPeriod::of($now, $now);

    // The old bounds were the 12th's UTC midnights: 03:30 on the 21st to 03:29 on the 22nd
    // in Tehran, so "today's trade" at half past midnight was yesterday's.
    expect($period->from->toIso8601ZuluString('microsecond'))->toBe('2026-09-12T20:30:00.000000Z')
        ->and($period->to->toIso8601ZuluString('microsecond'))->toBe('2026-09-13T20:29:59.999999Z')
        ->and($period->fromJalali)->toBe('۱۴۰۵/۰۶/۲۲')
        ->and($period->toJalali)->toBe('۱۴۰۵/۰۶/۲۲');
});
