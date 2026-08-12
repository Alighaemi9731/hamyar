<?php

declare(strict_types=1);

use App\Modules\Installments\Services\InstallmentScheduler;
use App\Support\Jalali;

/**
 * The schedule maths, against exact expected rial.
 *
 * `InstallmentScheduler` is pure — no database, no clock — which is what lets these
 * assert figures rather than infer them from a seeded scenario. Every one of these is a
 * number a customer could point at on a printed contract.
 */
beforeEach(fn () => $this->scheduler = new InstallmentScheduler);

/* ------------------------------------------------------------- the split -- */

it('divides evenly when it can', function (): void {
    $schedule = $this->scheduler->schedule(
        financed: 60_000_000,
        count: 6,
        profitPercent: 0,
        firstDueAt: Jalali::parse('1405/06/15'),
    );

    expect($schedule['total_payable'])->toBe(60_000_000)
        ->and($schedule['rows'])->toHaveCount(6);

    foreach ($schedule['rows'] as $row) {
        expect($row['amount'])->toBe(10_000_000);
    }
});

it('puts the remainder on the last instalment, not the first', function (): void {
    // 10,000,000 over three is 3,333,333.33 — and none of those is payable.
    $schedule = $this->scheduler->schedule(
        financed: 10_000_000,
        count: 3,
        profitPercent: 0,
        firstDueAt: Jalali::parse('1405/06/15'),
    );

    $amounts = array_column($schedule['rows'], 'amount');

    // Floored to a whole toman, with the difference carried to the end.
    expect($amounts)->toBe([3_333_330, 3_333_330, 3_333_340]);

    // And the parts sum to the whole exactly, which is the point.
    expect(array_sum($amounts))->toBe($schedule['total_payable'])
        ->and(array_sum($amounts))->toBe(10_000_000);
});

it('always sums to the total, however awkward the division', function (int $financed, int $count): void {
    $schedule = $this->scheduler->schedule(
        financed: $financed,
        count: $count,
        profitPercent: 17,
        firstDueAt: Jalali::parse('1405/01/01'),
    );

    // The invariant that matters: the parts equal the whole. The whole is what the
    // customer signed for, so losing a rial to make a rounder final figure means the
    // shop collects less than the contract says.
    expect(array_sum(array_column($schedule['rows'], 'amount')))->toBe($schedule['total_payable']);

    // Every row but the last is a whole toman.
    foreach (array_slice($schedule['rows'], 0, -1) as $row) {
        expect($row['amount'] % 10)->toBe(0);
    }
})->with([
    'awkward thirds' => [10_000_000, 3],
    'an odd rial in the input' => [87_654_321, 12],
    'seven ways' => [55_555_550, 7],
    'a big one' => [999_999_990, 11],
]);

it('makes every instalment payable when the financed amount is a whole toman', function (int $financed, int $count): void {
    $schedule = $this->scheduler->schedule(
        financed: $financed,
        count: $count,
        profitPercent: 17,
        firstDueAt: Jalali::parse('1405/01/01'),
    );

    // The production case: invoice totals are rounded to the shop's step and every
    // amount typed at the till is in toman, so the last row lands on a whole one too.
    foreach ($schedule['rows'] as $row) {
        expect($row['amount'] % 10)->toBe(0);
    }
})->with([
    'awkward thirds' => [10_000_000, 3],
    'a year of them' => [87_654_320, 12],
    'seven ways' => [55_555_550, 7],
    'a big one' => [999_999_990, 11],
]);

/* -------------------------------------------------------------- the profit -- */

it('applies the profit flat, on the whole financed amount', function (): void {
    $schedule = $this->scheduler->schedule(
        financed: 60_000_000,
        count: 6,
        profitPercent: 20,
        firstDueAt: Jalali::parse('1405/06/15'),
    );

    // «۲۰٪ سود روی ۶ قسط» is a flat markup, not a reducing-balance rate. An APR would be
    // more defensible as finance and would print a schedule nobody agreed to.
    expect($schedule['profit_amount'])->toBe(12_000_000)
        ->and($schedule['total_payable'])->toBe(72_000_000);

    foreach ($schedule['rows'] as $row) {
        expect($row['amount'])->toBe(12_000_000);
    }
});

it('floors the profit rather than rounding it up', function (): void {
    // 7% of 33,333,333 is 2,333,333.31 — floored to a whole toman.
    expect($this->scheduler->profitOn(33_333_333, 7))->toBe(2_333_330);
});

/* --------------------------------------------------------------- the dates -- */

it('spaces the instalments by Jalali months, not by thirty days', function (): void {
    $schedule = $this->scheduler->schedule(
        financed: 12_000_000,
        count: 4,
        profitPercent: 0,
        // ۱۵ اردیبهشت — in the 31-day half of the Jalali year.
        firstDueAt: Jalali::parse('1405/02/15'),
    );

    $dates = array_map(
        fn (array $row): string => Jalali::format($row['due_at'], Jalali::DATE, false),
        $schedule['rows'],
    );

    // The same day of each Jalali month. Adding a fixed thirty days would have drifted
    // to ۱۴ and ۱۳, and by the twelfth instalment the schedule and the signed contract
    // would disagree by days.
    expect($dates)->toBe(['1405/02/15', '1405/03/15', '1405/04/15', '1405/05/15']);
});

it('honours an interval of more than one month', function (): void {
    $schedule = $this->scheduler->schedule(
        financed: 9_000_000,
        count: 3,
        profitPercent: 0,
        firstDueAt: Jalali::parse('1405/01/10'),
        intervalMonths: 3,
    );

    $dates = array_map(
        fn (array $row): string => Jalali::format($row['due_at'], Jalali::DATE, false),
        $schedule['rows'],
    );

    expect($dates)->toBe(['1405/01/10', '1405/04/10', '1405/07/10']);
});

it('clamps a due date onto a shorter month', function (): void {
    $schedule = $this->scheduler->schedule(
        financed: 6_000_000,
        count: 2,
        profitPercent: 0,
        // ۳۱ شهریور — the last 31-day month of the year. مهر has 30.
        firstDueAt: Jalali::parse('1405/06/31'),
    );

    $dates = array_map(
        fn (array $row): string => Jalali::format($row['due_at'], Jalali::DATE, false),
        $schedule['rows'],
    );

    // What a shop means by "the same date next month" when that date does not exist.
    expect($dates)->toBe(['1405/06/31', '1405/07/30']);
});

/* -------------------------------------------------------------- refusals -- */

it('refuses a schedule that makes no sense', function (int $financed, int $count, int $percent): void {
    expect(fn () => $this->scheduler->schedule(
        financed: $financed,
        count: $count,
        profitPercent: $percent,
        firstDueAt: Jalali::parse('1405/06/15'),
    ))->toThrow(InvalidArgumentException::class);
})->with([
    'nothing to finance' => [0, 6, 0],
    'a negative amount' => [-1_000, 6, 0],
    'no instalments' => [10_000_000, 0, 0],
    'more instalments than a lifetime' => [10_000_000, 500, 0],
    'a nonsense rate' => [10_000_000, 6, 500],
    // 1,000 rial over 600 instalments is under a toman each — a typo in the count
    // field, not a plan.
    'instalments below a toman' => [1_000, 600, 0],
]);
