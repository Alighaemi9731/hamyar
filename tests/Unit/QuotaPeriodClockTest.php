<?php

declare(strict_types=1);

use App\Support\Jalali;
use App\Support\Quota\PeriodClock;
use App\Support\Quota\Window;
use Carbon\CarbonImmutable;

/**
 * The month boundary — the one piece of this design that is wrong by three and a half
 * hours if anybody reaches for a Carbon convenience method.
 */

beforeEach(function (): void {
    $this->clock = new PeriodClock('Asia/Tehran');
});

it('keys a month by the Gregorian date the Jalali month starts on', function (): void {
    // Golden rule 5: no Jalali string in a column. The key is a real date, which also
    // makes it sortable, fixed-width and groupable against date() in SQL.
    $this->travelTo(CarbonImmutable::parse('2026-09-05 08:00:00', 'Asia/Tehran'));

    $key = $this->clock->periodKey(Window::Month);

    expect($key)->toMatch('/^\d{4}-\d{2}-\d{2}$/');
    expect($key)->toBe(
        Jalali::startOfMonth(CarbonImmutable::now())->setTimezone('Asia/Tehran')->toDateString()
    );
});

it('gives every day of one Jalali month the same key', function (): void {
    $keys = [];

    foreach (['2026-08-23 00:05', '2026-09-01 12:00', '2026-09-22 23:50'] as $moment) {
        $this->travelTo(CarbonImmutable::parse($moment, 'Asia/Tehran'));
        $keys[] = $this->clock->periodKey(Window::Month);
    }

    // شهریور ۱۴۰۵ runs 2026-08-23 to 2026-09-22. One credit, one row, one key.
    expect(array_unique($keys))->toHaveCount(1);
});

it('starts a new credit at Tehran midnight on the first of the Jalali month', function (): void {
    // The assertion the whole design turns on. 23:55 on the last day of Shahrivar and
    // 00:05 on the first of Mehr are ten minutes apart and must be different credits.
    $this->travelTo(CarbonImmutable::parse('2026-09-22 23:55:00', 'Asia/Tehran'));
    $before = $this->clock->periodKey(Window::Month);

    $this->travelTo(CarbonImmutable::parse('2026-09-23 00:05:00', 'Asia/Tehran'));
    $after = $this->clock->periodKey(Window::Month);

    expect($before)->not->toBe($after);
});

it('does NOT roll over at UTC midnight', function (): void {
    // The control case, and the one that fails if anyone swaps the clock for
    // now()->startOfMonth() or forgets the timezone. Tehran is UTC+3:30, so UTC midnight
    // is 03:30 in the middle of a shop's ordinary morning.
    $this->travelTo(CarbonImmutable::parse('2026-09-09 23:55:00', 'UTC'));
    $before = $this->clock->periodKey(Window::Month);

    $this->travelTo(CarbonImmutable::parse('2026-09-10 00:05:00', 'UTC'));

    expect($this->clock->periodKey(Window::Month))->toBe($before);
});

it('does NOT roll over at Gregorian month end', function (): void {
    // Same trap in the other calendar: a Jalali month straddles two Gregorian ones, so
    // Carbon's startOfMonth() would split one credit into two.
    $this->travelTo(CarbonImmutable::parse('2026-08-31 23:55:00', 'Asia/Tehran'));
    $before = $this->clock->periodKey(Window::Month);

    $this->travelTo(CarbonImmutable::parse('2026-09-01 00:05:00', 'Asia/Tehran'));

    expect($this->clock->periodKey(Window::Month))->toBe($before);
});

it('refills at MIDNIGHT TEHRAN, not midnight UTC', function (): void {
    // The assertion that earns its place. `Jalali::startOfMonth()` returns midnight UTC
    // on the first of the month, which is 03:30 Tehran — so returning it unchanged makes
    // the screen promise a refill three and a half hours after it has already happened.
    // Asserting only the DATE would pass either way; the time is the whole test.
    $this->travelTo(CarbonImmutable::parse('2026-09-05 08:00:00', 'Asia/Tehran'));

    $resetsAt = $this->clock->resetsAt(Window::Month);

    expect($resetsAt)->not->toBeNull();
    expect($resetsAt)->toBeUtc();
    expect($resetsAt?->greaterThan(CarbonImmutable::now()))->toBeTrue();
    expect($resetsAt?->setTimezone('Asia/Tehran')->format('H:i'))->toBe('00:00');
});

it('refills exactly when the next period key becomes current', function (): void {
    // The two halves have to agree: one instant before the reset the shop is still in
    // this period, one instant after it is in the next one. A boundary that disagrees
    // with the key would show a full meter against a spent counter.
    $this->travelTo(CarbonImmutable::parse('2026-09-05 08:00:00', 'Asia/Tehran'));

    $resetsAt = $this->clock->resetsAt(Window::Month);
    $current = $this->clock->periodKey(Window::Month);

    expect($resetsAt)->not->toBeNull();
    expect($this->clock->periodKey(Window::Month, $resetsAt?->subSecond()))->toBe($current);
    expect($this->clock->periodKey(Window::Month, $resetsAt))->not->toBe($current);
});

it('has no period, no key and no reset for a standing capacity', function (): void {
    // Seats and storage are not a flow. Asking for their period key is a bug in the
    // caller, and answering with '' would write a nonsense counter row instead.
    expect($this->clock->resetsAt(Window::Total))->toBeNull();
    expect(fn (): string => $this->clock->periodKey(Window::Total))
        ->toThrow(InvalidArgumentException::class);
});

it('labels the period the way the shop reads it', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-05 08:00:00', 'Asia/Tehran'));

    // Persian digits and a Jalali month name — a label, never a key.
    expect($this->clock->label(Window::Month))->toContain('شهریور');
});
