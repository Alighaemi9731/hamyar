<?php

declare(strict_types=1);

use App\Support\Digits;

it('converts Latin digits and separators to Persian ones', function (): void {
    expect(Digits::toPersian('1,250,000'))->toBe('۱٬۲۵۰٬۰۰۰');
});

it('normalises Persian digits to Latin', function (): void {
    expect(Digits::toLatin('۱۲۳۴۵۶۷۸۹۰'))->toBe('1234567890');
});

it('normalises Arabic-Indic digits to Latin', function (): void {
    // Iranian and Arabic-locale keyboards emit these interchangeably; an IMEI typed
    // on one must validate the same as one typed on the other.
    expect(Digits::toLatin('٠١٢٣٤٥٦٧٨٩'))->toBe('0123456789');
});

it('leaves non-digit characters untouched', function (): void {
    expect(Digits::toLatin('IMEI: ۳۵۶۹۳۸'))->toBe('IMEI: 356938');
});

it('round-trips', function (): void {
    expect(Digits::toLatin(Digits::toPersian('9,876,543,210')))->toBe('9,876,543,210');
});
