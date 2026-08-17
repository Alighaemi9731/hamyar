<?php

declare(strict_types=1);

use App\Support\Digits;
use App\Support\Jalali;
use App\Support\Money;

/**
 * A very small set of global helpers.
 *
 * These exist only so Blade print templates stay readable — application code should
 * call the underlying support classes directly, which Larastan can reason about
 * better. Nothing is added here that is not used by a template.
 */
if (! function_exists('money')) {
    /**
     * Format an integer rial amount for display.
     *
     * @param  int  $rial  golden rule 2: always an integer number of rial
     */
    function money(int $rial, string $unit = Money::UNIT_RIAL, bool $persianDigits = false): string
    {
        return Money::format($rial, $unit, $persianDigits);
    }
}

if (! function_exists('jdate')) {
    /**
     * Render a stored UTC timestamp as a Jalali string in the shop's timezone.
     */
    function jdate(DateTimeInterface|string|int|null $value, string $format = Jalali::DATE): string
    {
        return Jalali::format($value, $format);
    }
}

if (! function_exists('fa_digits')) {
    /**
     * Convert Latin digits in a string to Persian ones (prose only — never tables).
     */
    function fa_digits(string $value): string
    {
        return Digits::toPersian($value);
    }
}

if (! function_exists('idOfModel')) {
    /**
     * A model's primary key as an int.
     *
     * `getKey()` is declared `mixed`, so Larastan level 8 refuses `(int) $model->getKey()`
     * at every call site — and the alternative was the same four-line annotated cast copied
     * into a dozen controllers and services. One helper, one place the narrowing is
     * justified.
     */
    function idOfModel(Illuminate\Database\Eloquent\Model $model): int
    {
        /** @var int|numeric-string $key */
        $key = $model->getKey();

        return (int) $key;
    }
}
