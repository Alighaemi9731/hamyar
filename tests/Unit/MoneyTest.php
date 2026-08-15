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

    it('raises a derived cost to the next whole toman', function (): void {
        /*
        | The escape hatch for the one class of amount that legitimately lands off a
        | toman: a *derived* unit cost. A weighted average is a division — a hundred
        | chargers at 50,000 and ten at 90,000 come to 53,636 rial each — and `toToman()`
        | refuses that, so it can never be displayed. Raising it at the point it is
        | derived is what stops the sales report throwing on a real shop's data.
        */
        expect(Money::ceilToToman(53_636))->toBe(53_640)->toBeRial();
        expect(Money::ceilToToman(56_666_666))->toBe(56_666_670);
    });

    it('leaves an amount that is already whole toman exactly alone', function (): void {
        // No drift on the common case: most costs are typed in by a person, in toman.
        expect(Money::ceilToToman(125_000_000))->toBe(125_000_000);
        expect(Money::ceilToToman(0))->toBe(0);
    });

    it('rounds a negative amount away from zero too', function (): void {
        // A negative cost is a correction, and the same conservatism applies: the
        // adjustment must not shrink toward zero and quietly recover margin.
        expect(Money::ceilToToman(-53_636))->toBe(-53_640);
    });

    it('produces something toToman will accept, for every remainder', function (): void {
        // The property the whole helper exists for, over all ten residues.
        foreach (range(0, 9) as $remainder) {
            expect(fn () => Money::toToman(Money::ceilToToman(1_000 + $remainder)))->not->toThrow(InvalidArgumentException::class);
        }
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
