<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One mobile number, one account, one shop.
 *
 * [ADR 0017](../../../../../docs/adr/0017-single-host-app.md). Every shop signs in at
 * one address now, so the number typed into the login form has to identify exactly one
 * account — there is no hostname left to say which shop was meant.
 *
 * ## This reverses a deliberate decision, and the reason is worth keeping
 *
 * The original migration chose per-tenant uniqueness on purpose and said why:
 *
 * > *Unique PER TENANT, not globally: the same person may work at two shops, and two
 * > unrelated shops may each have an "info@" address. A global unique index here would
 * > leak the existence of other tenants' accounts through registration errors.*
 *
 * Both halves were true and both are now accepted costs. One person can no longer hold
 * accounts at two shops under one number, and "this number is already registered" tells
 * a stranger that the number has an account somewhere. The registration flow must
 * therefore never say *which* shop — that would turn a leak of existence into a leak of
 * membership.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->refuseIfDuplicatesExist();

        Schema::table('users', function (Blueprint $table): void {
            // The composite is implied by the global index: if `mobile` is unique across
            // the table it is unique within any tenant. Keeping both would leave a second
            // index to maintain on every write for no additional guarantee.
            $table->dropUnique(['tenant_id', 'mobile']);
        });

        /*
        | PARTIAL, on `deleted_at is null`, and this is the correction that matters.
        |
        | The first version used `$table->unique('mobile')`, which covers every row —
        | including soft-deleted ones. The guard above counts only live rows. So the two
        | disagreed about which rows exist: the guard reported the table clean and
        | Postgres refused the index with "Duplicate keys exist", during a deploy, from
        | a migration whose entire job was to fail helpfully BEFORE that happened.
        |
        | **A guard and the constraint it guards must agree on which rows count.**
        |
        | Partial is also the right rule on its own terms. A retired account holding a
        | phone number hostage forever is not a guarantee anybody wants: somebody who
        | leaves a shop and is deleted should not stop that number ever being used again.
        |
        | Nullable too, and Postgres treats NULLs as distinct, so any number of users may
        | still have no mobile — which staff invited by email do.
        */
        DB::statement(
            'create unique index users_mobile_unique
             on users (mobile)
             where mobile is not null and deleted_at is null'
        );
    }

    public function down(): void
    {
        DB::statement('drop index if exists users_mobile_unique');

        Schema::table('users', function (Blueprint $table): void {
            $table->unique(['tenant_id', 'mobile']);
        });
    }

    /**
     * Stop, and name the conflicts.
     *
     * A migration does not get to choose which of somebody's two accounts survives.
     * Picking the older, the newer, or the one with more data would each silently orphan
     * a real person's login — and it would happen during a deploy, with no one watching
     * the row it decided against.
     *
     * So this fails, loudly, with the numbers and the shops printed. Resolving them is a
     * human decision taken before the deploy, not during it.
     */
    private function refuseIfDuplicatesExist(): void
    {
        /** @var list<object{mobile: string, shops: int, tenants: string}> $clashes */
        $clashes = DB::select(<<<'SQL'
            SELECT mobile,
                   count(*)                                        AS shops,
                   string_agg(tenant_id::text, ', ' ORDER BY tenant_id) AS tenants
            FROM users
            WHERE mobile IS NOT NULL AND deleted_at IS NULL
            GROUP BY mobile
            HAVING count(*) > 1
            ORDER BY count(*) DESC
        SQL);

        if ($clashes === []) {
            return;
        }

        $lines = array_map(
            static fn (object $row): string => "  {$row->mobile} — in tenants {$row->tenants}",
            $clashes,
        );

        throw new RuntimeException(
            'Cannot make users.mobile globally unique: '.count($clashes)." number(s) are registered in more than one shop.\n\n".
            implode("\n", $lines)."\n\n".
            "ADR 0017 makes one number mean one account. Decide which account survives for each number above and change or remove the others, then run this migration again.\n".
            "This migration will not choose for you — the account it discarded would be somebody's only way in."
        );
    }
};
