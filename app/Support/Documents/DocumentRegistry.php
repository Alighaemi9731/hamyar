<?php

declare(strict_types=1);

namespace App\Support\Documents;

use Closure;

/**
 * How a record names itself when a screen in another module has to show it.
 *
 * The IMEI passport is what forced this. A passport line points at the document that
 * caused it — a purchase invoice, a transfer, later a sale or a repair ticket — and at
 * the party the device was acquired from. All of those live in other modules, and
 * Inventory may not import them (ADR 0003). A table of class-name strings inside
 * Inventory would work exactly until someone moved a class.
 *
 * So this lives in the shared kernel and neither side depends on the other: the owning
 * module registers a resolver for its own records in its service provider, and any
 * screen that needs a label asks here.
 *
 * ## Types
 *
 * A `$type` is whatever the referring column holds:
 *
 * - a **class string**, for a polymorphic reference (`reference_type`);
 * - a **short key** such as `party`, for a plain foreign key whose target module is
 *   known but whose class is not.
 *
 * ## Resolvers take a list
 *
 * Deliberately batch, never one id at a time. A passport with twenty history lines
 * pointing at four invoices must run one query, not twenty — the screen this exists
 * for is the one most likely to be long.
 *
 * An unregistered type degrades to a generic label rather than blanking the line: a
 * passport entry with an unlabelled cause is still evidence that something happened.
 *
 * Registered as a singleton in `App\Providers\AppServiceProvider`.
 */
final class DocumentRegistry
{
    /** @var array<string, Closure(list<int|string>): array<int|string, DocumentReference>> */
    /*
    | ADR 0012 audit: no tenant in the key, and correctly so — resolvers are registered by
    | service providers at boot and keyed by model class. Shop data is fetched by the
    | resolver when it runs, under RLS, and never held here.
    */
    private array $resolvers = [];

    /**
     * @param  Closure(list<int|string>): array<int|string, DocumentReference>  $resolver
     */
    public function register(string $type, Closure $resolver): void
    {
        $this->resolvers[$type] = $resolver;
    }

    /**
     * Labels for many records of one type, keyed by id.
     *
     * Ids with no matching record are simply absent — a deleted document is not an
     * error, it is a line whose cause no longer has a name.
     *
     * @param  list<int|string>  $ids
     * @return array<int|string, DocumentReference>
     */
    public function describeMany(string $type, array $ids): array
    {
        $ids = array_values(array_unique($ids));

        if ($ids === []) {
            return [];
        }

        $resolver = $this->resolvers[$type] ?? null;

        if ($resolver === null) {
            return array_combine(
                $ids,
                array_map(static fn (): DocumentReference => new DocumentReference('سند مرتبط'), $ids)
            );
        }

        return $resolver($ids);
    }

    public function describe(?string $type, int|string|null $id): ?DocumentReference
    {
        if ($type === null || $id === null) {
            return null;
        }

        return $this->describeMany($type, [$id])[$id] ?? null;
    }

    public function knows(string $type): bool
    {
        return array_key_exists($type, $this->resolvers);
    }
}
