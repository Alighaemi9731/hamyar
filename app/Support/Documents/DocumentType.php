<?php

declare(strict_types=1);

namespace App\Support\Documents;

/**
 * Well-known registry keys for records referenced by a plain foreign key.
 *
 * Polymorphic references carry their own class name and need nothing here. These are
 * for the other case: `product_units.acquired_from_party_id` is a bare bigint, so the
 * screen reading it knows it wants a *party* but must not know which class that is.
 *
 * Both sides — the module that registers the resolver and the screen that asks — point
 * at this constant, which is what makes the key a contract rather than two matching
 * string literals that drift apart.
 */
final class DocumentType
{
    /** A customer, supplier or همکار. Owned by CRM. */
    public const PARTY = 'party';
}
