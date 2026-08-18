<?php

declare(strict_types=1);

namespace App\Support\Audit;

use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * Keeps secrets out of the audit trail.
 *
 * ## The hole this closes
 *
 * The product protects a handful of values properly: a repair ticket's
 * `device_passcode` is encrypted at rest, hidden from serialisation, gated by a
 * permission and audited on reveal (Phase 6). A Moadian private key, a storefront
 * link's password hash and a user's two-factor secret get the same treatment.
 *
 * An audit log undoes all of it in one line. `LogsActivity` on a model writes the
 * before-and-after of whatever attributes it is told to watch, straight into
 * `properties`, in clear. The reader of that log needs only `activity.view` — a
 * weaker permission than the one guarding the field itself. The log becomes the back
 * door to the secret the rest of the UI protects, and nothing anywhere would say so.
 *
 * ## Why the list is derived and not written down
 *
 * The tempting fix is a `const SECRETS = ['device_passcode', 'private_key', …]` here.
 * That list is correct on the day it is written and silently wrong afterwards: the
 * next secret field is added by someone thinking about encryption and permissions,
 * not about a log viewer in another module. The gap opens without a diff that looks
 * like it opened one.
 *
 * So the list is derived from the model itself, from the two declarations that
 * already exist wherever a secret does:
 *
 * - `$hidden` — "never serialise this"
 * - a cast of `encrypted`, `encrypted:array`, `encrypted:object`, … — "never store
 *   this in clear"
 *
 * **The log masks what the model masks.** A new secret field is protected on the day
 * it is added, by the same declaration that protects it everywhere else, rather than
 * on the day somebody remembers this class exists.
 *
 * Over-masking is the safe direction here — `$hidden` occasionally carries something
 * merely noisy rather than secret, and a masked value in an audit row costs a
 * support question, while an exposed one costs a customer's phone.
 */
final class Redactor
{
    /**
     * Bullets rather than a word like "[redacted]": it reads as a value that exists
     * and is being withheld, not as a bug or a literal string somebody typed.
     */
    public const MASK = '••••••';

    /**
     * Per-class secret lists, resolved once. Redacting a page of activity rows asks
     * the same question repeatedly, and each miss instantiates a model.
     *
     * @var array<class-string, list<string>>
     */
    private array $cache = [];

    /**
     * Attribute names on `$modelClass` whose values must never reach the log.
     *
     * @param  class-string|null  $modelClass
     * @return list<string>
     */
    public function secretsFor(?string $modelClass): array
    {
        if ($modelClass === null || ! is_subclass_of($modelClass, Model::class)) {
            return [];
        }

        if (isset($this->cache[$modelClass])) {
            return $this->cache[$modelClass];
        }

        try {
            $model = new $modelClass;

            $encrypted = array_keys(array_filter(
                $model->getCasts(),
                // `encrypted`, and every `encrypted:array` / `encrypted:collection`
                // variant. str_starts_with rather than an equality list, so a cast
                // type added by a future Laravel version is covered by default.
                fn (mixed $cast): bool => is_string($cast) && str_starts_with($cast, 'encrypted'),
            ));

            $secrets = array_values(array_unique([...$model->getHidden(), ...$encrypted]));
        } catch (Throwable) {
            // A model that cannot be constructed without arguments must not take the
            // audit log down with it. Failing to *mask* is the dangerous direction,
            // so an unreadable model is treated as all-secret by the caller below
            // rather than as nothing-secret.
            $secrets = ['*'];
        }

        return $this->cache[$modelClass] = $secrets;
    }

    /**
     * Mask every secret value inside one activity row's `properties` payload.
     *
     * Walks `attributes` and `old` (what `LogsActivity` writes) as well as the top
     * level (what a hand-written `->withProperties([...])` writes), because both land
     * in the same column and a reader cannot tell them apart.
     *
     * @param  array<string, mixed>  $properties
     * @param  class-string|null  $subjectClass
     * @return array<string, mixed>
     */
    public function redact(array $properties, ?string $subjectClass): array
    {
        $secrets = $this->secretsFor($subjectClass);

        if ($secrets === []) {
            return $properties;
        }

        return $this->mask($properties, $secrets);
    }

    /**
     * @param  array<array-key, mixed>  $payload
     * @param  list<string>  $secrets
     * @return array<array-key, mixed>
     */
    private function mask(array $payload, array $secrets): array
    {
        $maskEverything = in_array('*', $secrets, true);

        foreach ($payload as $key => $value) {
            if ($maskEverything || in_array((string) $key, $secrets, true)) {
                $payload[$key] = self::MASK;

                continue;
            }

            // `attributes` and `old` are nested one level; a secret cast to
            // `encrypted:array` is itself an array of secrets. Recursing covers both
            // without the caller needing to know which shape it has.
            if (is_array($value)) {
                $payload[$key] = $this->mask($value, $secrets);
            }
        }

        return $payload;
    }
}
