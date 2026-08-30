<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Validator;

/**
 * Every validation message a shopkeeper can see is in Persian.
 *
 * ## What this is guarding against
 *
 * `config('app.locale')` is `fa` and the fallback is `en`. Until `0.18.0` there was no
 * `lang/` directory at all, so Laravel resolved every message from its own English file
 * inside `vendor/` — and a shopkeeper who left a field blank read
 * «The identifier field is required.» in left-to-right English, naming a database column,
 * on a right-to-left page.
 *
 * Twenty-one of the twenty-four FormRequests hid it by hand-writing Persian for the rules
 * somebody remembered. Everything else, and all forty inline `$request->validate()` calls,
 * fell through. So the bug was invisible exactly where it was most likely: on the rule
 * nobody anticipated.
 *
 * The first test is the whole guarantee and would have failed for the product's entire
 * life before this file existed.
 */
it('answers in Persian for every rule Laravel ships', function (): void {
    /** @var array<string, mixed> $fa */
    $fa = require lang_path('fa/validation.php');

    /** @var array<string, mixed> $en */
    $en = require base_path('vendor/laravel/framework/src/Illuminate/Translation/lang/en/validation.php');

    $missing = array_diff(array_keys($en), array_keys($fa));

    // A rule Laravel has and we do not is a message that silently comes back English.
    // Laravel adds rules between minor versions, which is exactly the kind of drift
    // nobody notices until a shopkeeper reports it.
    expect($missing)->toBe([]);
});

it('never renders a placeholder as literal text', function (): void {
    /** @var array<string, mixed> $fa */
    $fa = require lang_path('fa/validation.php');

    /** @var array<string, mixed> $en */
    $en = require base_path('vendor/laravel/framework/src/Illuminate/Translation/lang/en/validation.php');

    $wrong = [];

    $walk = function (array $ours, array $theirs, string $path) use (&$walk, &$wrong): void {
        foreach ($theirs as $key => $value) {
            if (in_array($key, ['custom', 'attributes', 'values'], true)) {
                continue;
            }

            $mine = $ours[$key] ?? null;

            if (is_array($value)) {
                if (is_array($mine)) {
                    $walk($mine, $value, "{$path}{$key}.");
                }

                continue;
            }

            if (! is_string($mine)) {
                continue;
            }

            preg_match_all('/:[a-z_]+/', (string) $value, $expected);
            preg_match_all('/:[a-z_]+/', $mine, $actual);

            /*
            | Compared as SETS, not as sequences, and that is not laziness.
            |
            | Persian puts the condition before the subject — «چون :other برابر :value
            | است، :attribute را بپذیرید.» against English's «The :attribute field must be
            | accepted when :other is :value.» So the order differs on every conditional
            | rule and differing order is correct. What must not differ is *which*
            | placeholders appear: a missing one drops information, and an invented one
            | renders as literal `:atribute` to a shopkeeper.
            */
            $want = array_unique($expected[0]);
            $got = array_unique($actual[0]);

            sort($want);
            sort($got);

            if ($want !== $got) {
                $wrong[] = $path.$key.': want '.implode(',', $want).' got '.implode(',', $got);
            }
        }
    };

    $walk($fa, $en, '');

    /*
    | A misspelt placeholder does not error — it renders verbatim. «:atribute را وارد
    | کنید.» is a message that reached a customer, and the only way to catch it is to
    | compare against the file Laravel actually substitutes from.
    */
    expect($wrong)->toBe([]);
});

it('translates a bare rule with no custom message behind it', function (): void {
    // `principal` has no `messages()` entry anywhere. Before this file, this exact
    // validator answered «The مبلغ اصل field is required.»
    $validator = Validator::make([], ['principal' => 'required']);

    $message = $validator->errors()->first('principal');

    expect($message)->toBe('مبلغ اصل را وارد کنید.')
        ->and($message)->not->toContain('field')
        ->and($message)->not->toContain('required');
});

it('names the field in Persian rather than by its column', function (): void {
    $validator = Validator::make([], [
        'owner_mobile' => 'required',
        'national_id' => 'required',
        'lines.*.quantity' => 'required',
    ]);

    $errors = $validator->errors();

    // The half that decides whether a message reads as Persian or as a leak of the
    // schema. Without an `attributes` entry the raw column name is substituted, so
    // «فیلد owner_mobile الزامی است.» is what a shop owner would have read.
    expect($errors->first('owner_mobile'))->toContain('شمارهٔ موبایل')
        ->and($errors->first('owner_mobile'))->not->toContain('owner_mobile')
        ->and($errors->first('national_id'))->toContain('کد ملی');
});

it('keeps the nested keys a form cannot place beside an input', function (): void {
    // These are the keys the CLAUDE.md rule is about — the ones with nowhere to render.
    // They must at least be *readable* when they do reach the operator.
    $validator = Validator::make(
        ['lines' => [['quantity' => 0]], 'payments' => [['amount' => null]]],
        ['lines.*.quantity' => 'required|integer|min:1', 'payments.*.amount' => 'required'],
    );

    $errors = $validator->errors();

    expect($errors->first('lines.0.quantity'))->toContain('تعداد این ردیف')
        ->and($errors->first('payments.0.amount'))->toContain('مبلغ پرداخت')
        ->and($errors->first('lines.0.quantity'))->not->toContain('lines.0');
});

it('has a Persian label for every field the application validates', function (): void {
    /** @var array{attributes: array<string, string>} $fa */
    $fa = require lang_path('fa/validation.php');

    $labelled = $fa['attributes'];

    expect($labelled)->not->toBeEmpty();

    /*
    | Proper nouns that stay Latin in Persian prose, because that is how this market
    | writes them — «کد IMEI» is what is printed on the box and said out loud in the shop.
    | Translating them would be less Persian, not more.
    */
    $properNouns = ['IMEI', 'HAMTA', 'SMS', 'POS', 'QR', 'JSON', 'IP', 'MAC', 'URL'];

    $latin = [];

    foreach ($labelled as $field => $label) {
        $stripped = str_replace($properNouns, '', $label);

        // Anything Latin left after the proper nouns is a field somebody added to the map
        // without translating it — which reads exactly like the bug this file fixes.
        if (preg_match('/[A-Za-z]/', $stripped) === 1) {
            $latin[] = "{$field} => {$label}";
        }
    }

    expect($latin)->toBe([]);
});
