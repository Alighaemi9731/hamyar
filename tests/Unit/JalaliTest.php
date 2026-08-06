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
