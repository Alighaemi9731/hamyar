<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Validator;

/**
 * Validation messages count in Persian, and only where counting is what is happening.
 *
 * `lang/fa/validation.php` is written in Persian and its numbers were not: Laravel
 * substitutes `:max` with whatever the rule was given, so «نباید بیشتر از 255 حرف باشد»
 * reached shopkeepers with Latin digits in the middle of Persian prose — in the most
 * frequent interaction this product has.
 */
it('counts in Persian digits', function (array $rules, array $data, string $expected): void {
    $validator = Validator::make($data, $rules);

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->first())->toContain($expected);
})->with([
    'max on a string' => [['name' => ['max:255']], ['name' => str_repeat('a', 300)], '۲۵۵'],
    'min on a string' => [['name' => ['min:3']], ['name' => 'a'], '۳'],
    'size on an array' => [['rows' => ['array', 'size:4']], ['rows' => [1, 2]], '۴'],
    'between' => [['n' => ['numeric', 'between:10,20']], ['n' => 99], '۱۰'],
    'digits' => [['code' => ['digits:6']], ['code' => '12'], '۶'],
]);

/*
| The reason this converts the substituted parameters rather than the finished message.
|
| `hex_color`'s Persian message carries `#1A2B3C` as an example of the format, not as a
| quantity. A blanket pass over the rendered string would turn it into «#۱A۲B۳C», which is
| not a thing anybody can type into a colour field — and the same for the IP messages.
*/
it('leaves a literal example in the message alone', function (): void {
    $validator = Validator::make(['colour' => 'nope'], ['colour' => ['hex_color']]);

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->first())->toContain('#1A2B3C')
        ->and($validator->errors()->first())->not->toContain('۱A۲B۳C');
});

/*
| A parameter that is not a number is not a count. `:other` carries a field name and an
| `in` rule's values carry enum keys — converting either would corrupt them.
*/
it('leaves non-numeric parameters alone', function (): void {
    $validator = Validator::make(
        ['kind' => 'nope'],
        ['kind' => ['in:customer,supplier']],
    );

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->first())->not->toContain('۰');
});
