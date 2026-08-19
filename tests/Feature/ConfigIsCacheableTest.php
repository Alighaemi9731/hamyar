<?php

declare(strict_types=1);

/**
 * Every configuration value must survive `php artisan config:cache`.
 *
 * ## Why this is a test and not a note
 *
 * `config:cache` writes the merged configuration to a PHP file with `var_export`. A
 * closure cannot be exported at all; an object exports to a `__set_state()` call that
 * almost never exists. Either one turns config caching into a **fatal error**.
 *
 * The reason that deserves a test rather than care is *where* it fires. Nothing in
 * development runs `config:cache` — `make up`, `make fresh` and the whole suite work
 * perfectly with a closure sitting in a config file. It is run in exactly one place:
 * the deploy, on the production box, between the migrations and the traffic switch.
 * So the defect is invisible everywhere it is cheap and fatal in the one place it is
 * not.
 *
 * It nearly happened here: `config/sentry.php` wants a `before_send` callback, and the
 * obvious spelling — a closure, or `new ScrubSensitiveData` — is the one that breaks.
 * It is a static callable (two strings in an array) for this reason, and this test is
 * what keeps it that way after somebody decides a closure reads better.
 *
 * Asserting the property directly rather than shelling out to `config:cache`, which
 * would write a real cache file into the environment the rest of the suite is running
 * in and leave it there if the assertion failed.
 */
it('has no closure or object anywhere in the merged configuration', function (): void {
    $offenders = [];

    $walk = function (mixed $value, string $path) use (&$walk, &$offenders): void {
        if (is_array($value)) {
            foreach ($value as $key => $child) {
                $walk($child, $path === '' ? (string) $key : $path.'.'.$key);
            }

            return;
        }

        if ($value instanceof Closure) {
            $offenders[] = "{$path} is a Closure — var_export() cannot write one at all";

            return;
        }

        if (is_object($value) && ! method_exists($value, '__set_state')) {
            $offenders[] = "{$path} is an instance of ".$value::class.' with no __set_state()';
        }
    };

    $walk(app('config')->all(), '');

    expect($offenders)->toBe([], "config:cache would fail on the production box:\n  ".implode("\n  ", $offenders));
});
