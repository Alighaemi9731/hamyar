<?php

declare(strict_types=1);

use App\Support\Money;

/**
 * Golden rule 2. These are the tests that stop a rounding bug from reaching an
 * invoice, so they assert exact values rather than approximations.
 */
describe('formatting', function (): void {
    it('groups rial with Latin separators', function (): void {
        expect(Money::format(125_000_000))->toBe('125,000,000');
    });

    it('keeps the minus outside the digit grouping', function (): void {
        expect(Money::format(-4_500_000))->toBe('-4,500,000');
    });

    it('renders Persian digits and the Persian thousands separator', function (): void {
        expect(Money::format(1_250_000, Money::UNIT_RIAL, true))->toBe('۱٬۲۵۰٬۰۰۰');
    });

    it('formats zero without a sign', function (): void {
        expect(Money::format(0))->toBe('0');
    });

    it('appends the unit label', function (): void {
        expect(Money::formatWithUnit(125_000_000, Money::UNIT_TOMAN))->toBe('12,500,000 تومان');
        expect(Money::formatWithUnit(125_000_000, Money::UNIT_RIAL))->toBe('125,000,000 ریال');
    });
});

describe('toman conversion', function (): void {
    it('converts whole toman exactly', function (): void {
        expect(Money::toToman(125_000_000))->toBe(12_500_000)->toBeRial();
        expect(Money::fromToman(12_500_000))->toBe(125_000_000);
    });

    it('refuses to round a sub-toman remainder rather than losing money', function (): void {
        expect(fn () => Money::toToman(1_005))->toThrow(InvalidArgumentException::class);
    });
});

describe('parsing', function (): void {
    it('accepts separators, Persian digits and surrounding whitespace', function (string $input): void {
        expect(Money::parse($input))->toBe(1_250_000);
    })->with([
        '1250000',
        '1,250,000',
        ' 1250000 ',
        '۱۲۵۰۰۰۰',
        '۱٬۲۵۰٬۰۰۰',
    ]);

    it('treats input as toman when asked', function (): void {
        expect(Money::parse('12,500', Money::UNIT_TOMAN))->toBe(125_000);
    });

    it('rejects anything that is not an integer', function (string $input): void {
        expect(fn () => Money::parse($input))->toThrow(InvalidArgumentException::class);
    })->with([
        '',
        '12.50',
        '1,2ab',
        'abc',
        '۱۲.۵',
    ]);
});

describe('splitting into instalments', function (): void {
    it('sums back to exactly the original amount', function (int $total, int $parts): void {
        $rows = Money::split($total, $parts);

        expect($rows)->toHaveCount($parts);
        expect(array_sum($rows))->toBe($total);
    })->with([
        [10_000_000, 3],
        [10_000_001, 7],
        [999, 4],
        [1, 1],
        [0, 5],
    ]);

    it('puts the remainder on the LAST row, per the installment contract rule', function (): void {
        // 100 over 3 is 33/33/34 — never 34/33/33, because the printed contract shows
        // the customer equal instalments with the odd rial on the final one.
        expect(Money::split(100, 3))->toBe([33, 33, 34]);
    });

    it('rejects a non-positive part count', function (): void {
        expect(fn () => Money::split(100, 0))->toThrow(InvalidArgumentException::class);
    });
});

describe('percentages', function (): void {
    it('truncates toward zero so the shop never over-charges by a rounding artefact', function (): void {
        // 9% VAT on 1,050 rial is 94.5 — we charge 94, not 95.
        expect(Money::percent(1_050, 9))->toBe(94);
    });

    it('returns an integer for every input', function (): void {
        expect(Money::percent(123_456_789, 17))->toBeRial();
    });
});
