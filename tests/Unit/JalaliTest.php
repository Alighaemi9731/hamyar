<?php

declare(strict_types=1);

use App\Support\Jalali;
use Carbon\CarbonImmutable;

/**
 * Golden rule 5. The test that matters most here is the late-evening one: it is the
 * bug this class exists to prevent.
 */
it('renders a UTC instant as a Jalali date', function (): void {
    // 2026-08-06 12:00 UTC = 1405-05-15 15:30 Tehran
    expect(Jalali::format('2026-08-06T12:00:00Z', Jalali::DATE, false))->toBe('1405/05/15');
});

it('shifts into Tehran BEFORE converting the calendar', function (): void {
    // 21:00 Tehran on 1405/05/15 is already 2026-08-06 17:30 UTC — same day.
    // But 23:00 Tehran is 19:30 UTC, and naive conversion of the *UTC* date would
    // still say the 6th while the shop's till is closing on the 15th of Mordad.
    // The real trap is the other direction: 2026-08-06 21:00 UTC is already
    // 2026-08-07 00:30 in Tehran — the NEXT Jalali day.
    expect(Jalali::format('2026-08-06T21:00:00Z', Jalali::DATE, false))->toBe('1405/05/16');

    // …while an hour earlier is still the previous day.
    expect(Jalali::format('2026-08-06T20:00:00Z', Jalali::DATE, false))->toBe('1405/05/15');
});

it('renders Persian digits by default', function (): void {
    expect(Jalali::format('2026-08-06T12:00:00Z'))->toBe('۱۴۰۵/۰۵/۱۵');
});

it('renders an empty string for a null date so templates need no guard', function (): void {
    expect(Jalali::format(null))->toBe('');
    expect(Jalali::format(''))->toBe('');
    expect(Jalali::ago(null))->toBe('');
});

it('renders a long Persian month name', function (): void {
    expect(Jalali::format('2026-08-06T12:00:00Z', Jalali::LONG, false))->toContain('مرداد');
});

it('parses a Jalali string back to a UTC instant', function (): void {
    $parsed = Jalali::parse('1405/05/15');

    expect($parsed)->toBeUtc();
    expect($parsed->format('Y-m-d'))->toBe('2026-08-06');
});

it('parses Persian digits identically to Latin ones', function (): void {
    expect(Jalali::parse('۱۴۰۵/۰۵/۱۵')->toIso8601String())
        ->toBe(Jalali::parse('1405/05/15')->toIso8601String());
});

it('rejects an empty date', function (): void {
    expect(fn () => Jalali::parse(''))->toThrow(InvalidArgumentException::class);
});

/*
| «1405/6/2» is what a shop's staff write, and what the daily close and the instalment
| wizard both let through validation. Handed to the package unpadded, it read an undefined
| array key and the page was a 500. `parse()` pads it now, so every caller is fixed at once.
*/
it('parses a Jalali date with a single-digit month and day, in either digit set', function (): void {
    $padded = Jalali::parse('1405/06/02')->toIso8601String();

    expect(Jalali::parse('1405/6/2')->toIso8601String())->toBe($padded)
        ->and(Jalali::parse('۱۴۰۵/۶/۲')->toIso8601String())->toBe($padded)
        ->and(Jalali::parse('1405/6/22')->toIso8601String())->toBe(Jalali::parse('1405/06/22')->toIso8601String())
        // The day bounds go through `parse()`, so they take the short shape too.
        ->and(Jalali::startOfDay('۱۴۰۵/۶/۲')->toIso8601String())->toBe('2026-08-23T20:30:00+00:00')
        ->and(Jalali::endOfDay('1405/6/2')->format('Y-m-d H:i'))->toBe('2026-08-24 20:29');
});

it('produces UTC bounds for a Jalali day, for range filters', function (): void {
    $start = Jalali::startOfDay('1405/05/15');
    $end = Jalali::endOfDay('1405/05/15');

    expect($start)->toBeUtc();
    expect($end)->toBeUtc();

    // Tehran is UTC+03:30, so a Tehran day starts at 20:30 UTC the day before.
    expect($start->format('Y-m-d H:i'))->toBe('2026-08-05 20:30');
    expect($end->format('Y-m-d H:i'))->toBe('2026-08-06 20:29');

    expect($start->lessThan($end))->toBeTrue();
});

it('round-trips today', function (): void {
    $today = Jalali::today(Jalali::DATE, false);

    expect(Jalali::format(Jalali::parse($today), Jalali::DATE, false))->toBe($today);
});

it('never lets a Jalali string reach storage', function (): void {
    // The parse result is what a repository would persist: a real UTC instant.
    expect(Jalali::parse('1405/01/01'))->toBeInstanceOf(CarbonImmutable::class);
});

/* ------------------------------------------------------- calendar dates -- */

/*
| `calendarDate()` is what a `date` column is filled from and what "is it due yet" is
| asked of. The first case is the bug: the picker's value for ۱۴۰۵/۰۶/۲۲ is 20:30 UTC on
| the 21st, and a `date` cast handed that string stored the 21st.
*/
it('reads the picker instant for a Tehran midnight as that Tehran day', function (): void {
    expect(Jalali::calendarDate('2026-09-12T20:30:00.000Z')->toDateString())->toBe('2026-09-13');
});

it('takes a bare Gregorian date as the date it already is', function (): void {
    expect(Jalali::calendarDate('2026-09-13')->toDateString())->toBe('2026-09-13');
});

it('puts an instant after 20:30 UTC on the next Tehran day', function (): void {
    // 21:00 UTC is 00:30 in Tehran — already the 13th.
    expect(Jalali::calendarDate('2026-09-12T21:00:00Z')->toDateString())->toBe('2026-09-13');
});

it('keeps an instant before 20:30 UTC on its own day', function (): void {
    // 19:00 UTC is 22:30 in Tehran — still the 12th, the same day as its UTC date.
    expect(Jalali::calendarDate('2026-09-12T19:00:00Z')->toDateString())->toBe('2026-09-12');
});

it('returns midnight UTC, the value a date cast reads back', function (): void {
    $date = Jalali::calendarDate('2026-09-12T20:30:00.000Z');

    expect($date)->toBeUtc()
        ->and($date->format('H:i:s'))->toBe('00:00:00')
        // …and renders as the day that was picked, which is the whole point.
        ->and(Jalali::format($date, Jalali::DATE, false))->toBe('1405/06/22');
});

it('reads back what a date column is sent to the client as, unchanged', function (): void {
    // An edit form posts the prop straight back when the field is not touched: the
    // `toIso8601String()` of a date cast. It must not drift a day on every save.
    expect(Jalali::calendarDate('2026-09-13T00:00:00+00:00')->toDateString())->toBe('2026-09-13')
        ->and(Jalali::calendarDate(Jalali::calendarDate('2026-09-12T20:30:00Z'))->toDateString())->toBe('2026-09-13');
});

it('reads a DateTimeInterface on the shop clock, whatever zone it carries', function (): void {
    $instant = CarbonImmutable::parse('2026-09-12T20:30:00Z');

    expect(Jalali::calendarDate($instant)->toDateString())->toBe('2026-09-13')
        ->and(Jalali::calendarDate($instant->setTimezone('Asia/Tehran'))->toDateString())->toBe('2026-09-13');
});

it('reads a Jalali date in either digit set, padded or not', function (): void {
    expect(Jalali::calendarDate('۱۴۰۵/۰۶/۲۲')->toDateString())->toBe('2026-09-13')
        ->and(Jalali::calendarDate('1405/6/22')->toDateString())->toBe('2026-09-13');
});

it('reads a picker instant from a year Tehran kept summer time', function (): void {
    // 1370/05/23 is 1991-08-14, when Tehran was UTC+04:30. The picker still encodes
    // +03:30, so its instant is 01:00 local — still the right day.
    expect(Jalali::calendarDate('1991-08-13T20:30:00.000Z')->toDateString())->toBe('1991-08-14');
});

it('refuses an empty value rather than inventing today', function (): void {
    expect(fn () => Jalali::calendarDate(''))->toThrow(InvalidArgumentException::class);
});
