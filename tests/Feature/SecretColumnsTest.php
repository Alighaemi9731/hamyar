<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Symfony\Component\Finder\Finder;

/**
 * The inventory of encrypted columns, derived rather than written down.
 *
 * Roadmap 11.1 asks for an "encrypted-columns inventory (device passcodes!)". A list in
 * a document is correct the day it is written; this asks the models, so it is correct
 * the day it is run.
 *
 * ## The invariant
 *
 * **An attribute cast to `encrypted` must also be `$hidden`.**
 *
 * The cast protects it at rest and decrypts it on access — which means `toArray()`,
 * `toJson()`, an API resource that spreads the model, a `Log::info($model)` while
 * debugging, and every Inertia prop built from a model all hand back the **plaintext**.
 * The encryption is doing its job and the value is in the response anyway.
 *
 * `$hidden` is the second half, and the two are declared in different methods twenty
 * lines apart, which is exactly the distance at which one gets added without the other.
 *
 * This is also what {@see App\Support\Audit\Redactor} reads to keep secrets out of the
 * audit log, so a field that fails here is a field the audit log would have printed.
 */

/** @return list<class-string<Model>> */
function everyModelClass(): array
{
    $classes = [];

    /*
    | `exclude('tests')` is load-bearing. Module test files live under `app/Modules/
    | <Name>/tests`, and several declare global helper functions; walking them here
    | pulls those in a second time and the suite dies on "cannot redeclare". Models
    | never live in a tests directory, so nothing is lost by not looking.
    */
    $files = Finder::create()
        ->files()
        ->in(base_path('app'))
        ->exclude('tests')
        ->name('*.php');

    foreach ($files as $file) {
        $relative = str_replace([base_path('app').DIRECTORY_SEPARATOR, '.php'], ['', ''], $file->getRealPath());
        $class = 'App\\'.str_replace(DIRECTORY_SEPARATOR, '\\', $relative);

        if (! class_exists($class)) {
            continue;
        }

        $reflection = new ReflectionClass($class);

        if ($reflection->isAbstract() || ! $reflection->isSubclassOf(Model::class)) {
            continue;
        }

        /** @var class-string<Model> $class */
        $classes[] = $class;
    }

    sort($classes);

    return $classes;
}

/**
 * @return array<class-string<Model>, list<string>>
 */
function encryptedAttributes(): array
{
    $found = [];

    foreach (everyModelClass() as $class) {
        /** @var Model $model */
        $model = new $class;

        $encrypted = array_keys(array_filter(
            $model->getCasts(),
            static fn (mixed $cast): bool => is_string($cast) && str_starts_with($cast, 'encrypted'),
        ));

        if ($encrypted !== []) {
            $found[$class] = $encrypted;
        }
    }

    return $found;
}

it('finds the encrypted columns it is supposed to be guarding', function (): void {
    // Without this the test below is vacuous: a scanner that silently found nothing
    // passes every assertion it makes about what it found. Phase 6's device passcode
    // is named because it is the one whose leak costs a customer their phone.
    $inventory = encryptedAttributes();

    expect($inventory)->not->toBeEmpty()
        ->and($inventory)->toHaveKey(App\Modules\Repairs\Models\RepairTicket::class)
        ->and($inventory[App\Modules\Repairs\Models\RepairTicket::class])->toContain('device_passcode');
});

it('hides every attribute it encrypts', function (): void {
    $leaking = [];

    foreach (encryptedAttributes() as $class => $attributes) {
        /** @var Model $model */
        $model = new $class;

        $exposed = array_diff($attributes, $model->getHidden());

        if ($exposed !== []) {
            $leaking[$class] = array_values($exposed);
        }
    }

    // Named in the failure, because "some model somewhere" is not actionable and the
    // fix is one line in a file the reader has to be told the name of.
    expect($leaking)->toBe(
        [],
        'Encrypted but not $hidden — the cast decrypts on access, so these reach every '
        .'toArray(), JSON response and log line in plaintext: '.json_encode($leaking),
    );
});
