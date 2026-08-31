<?php

declare(strict_types=1);

namespace App\Support\Validation;

use App\Support\Digits;
use Illuminate\Validation\Validator;

/**
 * Validation messages that count in Persian.
 *
 * ## The leak
 *
 * `lang/fa/validation.php` is written in Persian and its numbers are not. Twenty messages
 * interpolate `:min`, `:max`, `:size` or `:value`, Laravel substitutes whatever the rule was
 * given, and a shopkeeper reads:
 *
 *     نام کالا نباید بیشتر از 255 حرف باشد.
 *
 * Latin digits in the middle of Persian prose, in the most frequent interaction the product
 * has — a validation message is what somebody sees every time they mistype something. The
 * rest of the app has been careful about this for a long time: `<Num variant="prose">`
 * exists for exactly this, `App\Support\Digits` mirrors it server-side, and both are used
 * on flash messages one call at a time. Validation was the one path with no funnel.
 *
 * ## Why the parameters and not the message
 *
 * The obvious fix — convert the finished string — is wrong, and visibly so:
 *
 *     'hex_color' => 'کد رنگ :attribute درست نیست؛ مثل #1A2B3C وارد کنید.'
 *
 * That `#1A2B3C` is an example of a hex colour, not a quantity, and «#۱A۲B۳C» is not a thing
 * anybody can type. Same for the IP messages. So this converts the **substituted parameters**
 * of counting rules, before they reach the message — literals in the translation are left
 * exactly as written, and so is `:attribute`, which may legitimately be «IMEI».
 *
 * A parameter is converted only when it is entirely digits. `:other` carries a field name and
 * `:value` on an `in` rule carries an enum key; neither is a number and neither is touched.
 */
final class PersianDigitsValidator extends Validator
{
    /**
     * Rules whose parameters are quantities a Persian sentence should count in Persian.
     *
     * Names are Laravel's studly rule names, as `makeReplacements()` receives them.
     * Deliberately a list rather than "anything numeric": `Digits` and `DigitsBetween` count
     * digits, `In` and `NotIn` carry values that only look like numbers.
     */
    private const COUNTING_RULES = [
        'Between',
        'Digits',
        'DigitsBetween',
        'Gt',
        'Gte',
        'Lt',
        'Lte',
        'Max',
        'MaxDigits',
        'Min',
        'MinDigits',
        'MultipleOf',
        'Size',
    ];

    /**
     * `public`, matching the trait it overrides — `FormatsMessages::makeReplacements()` is
     * public, and narrowing an inherited method's visibility is an error the analyser is
     * right to refuse.
     *
     * @param  array<int, string>  $parameters
     */
    public function makeReplacements($message, $attribute, $rule, $parameters)
    {
        if (in_array($rule, self::COUNTING_RULES, true) && $this->translator->getLocale() === 'fa') {
            $parameters = array_map(
                static fn (string $parameter): string => preg_match('/^\d+$/', $parameter) === 1
                    ? Digits::toPersian($parameter)
                    : $parameter,
                $parameters
            );
        }

        return parent::makeReplacements($message, $attribute, $rule, $parameters);
    }
}
