<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The two public repair tokens become globally unique.
 *
 * [ADR 0017](../../../../../docs/adr/0017-single-host-app.md). Both are handed to a
 * customer in a URL:
 *
 * · `tracking_token` — the QR printed on the intake receipt, `/t/{token}`
 * · `approval_token` — the link that asks them to approve a quote, `/a/{token}`
 *
 * They were unique per tenant because the shop's own hostname supplied the tenant. With
 * one address the token has to identify the ticket by itself, so it has to be unique by
 * itself.
 *
 * ## This is a recognised category, not a loophole
 *
 * `TenancyCheckCommand::GLOBALLY_UNIQUE_BY_DESIGN` already holds
 * `price_list_links_lookup_unique` and `invitations_token_hash_unique` with the reason —
 * *"a credential whose whole job is to be unguessable and to resolve before any tenant
 * is known"*. These two are exactly that shape and are added to the same list; the check
 * fails the build if a global unique index appears without an entry, which is what stops
 * this being a way to smuggle one in.
 *
 * ## Timing is the whole reason this is cheap
 *
 * Nothing has launched, so no customer is holding a receipt whose QR points at a
 * hostname that is about to stop existing. After launch this change would have needed
 * the old hostnames kept alive purely to redirect paper already in people's pockets.
 *
 * Both indexes stay partial (`where ... is not null`): a ticket with no approval request
 * has no approval token, and any number of rows may share NULL.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 48 and 64 random characters respectively — a collision across the whole table
        // is not a practical concern, and a failure here would be loud rather than silent.
        /*
        | The two are dropped with different DDL, and Postgres is emphatic about why.
        |
        | `tracking_token`'s index was created by Laravel's `$table->unique()`, which
        | emits a table CONSTRAINT — and a constraint owns its index, so `drop index`
        | fails with "cannot drop index ... because constraint ... requires it".
        | `approval_token`'s was raw `create unique index`, so it is only an index.
        |
        | Same-looking uniqueness, two different objects, two different statements.
        */
        DB::statement('alter table repair_tickets drop constraint if exists repair_tickets_tenant_id_tracking_token_unique');
        DB::statement('drop index if exists repair_tickets_tenant_id_tracking_token_unique');
        DB::statement('drop index if exists repair_tickets_approval_token_unique');

        DB::statement(
            'create unique index repair_tickets_tracking_token_unique
             on repair_tickets (tracking_token)
             where tracking_token is not null'
        );

        DB::statement(
            'create unique index repair_tickets_approval_token_unique
             on repair_tickets (approval_token)
             where approval_token is not null'
        );
    }

    public function down(): void
    {
        DB::statement('drop index if exists repair_tickets_tracking_token_unique');
        DB::statement('drop index if exists repair_tickets_approval_token_unique');

        // Restored as a constraint, matching what `$table->unique()` originally made.
        DB::statement(
            'alter table repair_tickets
             add constraint repair_tickets_tenant_id_tracking_token_unique
             unique (tenant_id, tracking_token)'
        );

        DB::statement(
            'create unique index repair_tickets_approval_token_unique
             on repair_tickets (tenant_id, approval_token)
             where approval_token is not null'
        );
    }
};
