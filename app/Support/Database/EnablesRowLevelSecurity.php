<?php

declare(strict_types=1);

namespace App\Support\Database;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Migration helper for the database half of tenant isolation
 * ([ADR 0002](../../../docs/adr/0002-single-db-tenancy-rls.md)).
 *
 * Every tenant table calls `$this->enableRls('table')` in the same migration that
 * creates it. `php artisan tenancy:check` fails the build if one forgets.
 *
 * Three details here are load-bearing:
 *
 * 1. **`FORCE ROW LEVEL SECURITY`.** Postgres exempts a table's owner from its own
 *    policies unless FORCE is set. Our application role owns the tables it migrates,
 *    so without FORCE the policy would apply to nobody at all.
 *
 * 2. **`current_setting('app.tenant_id', true)`.** The second argument is
 *    `missing_ok`. Without it, reading an unset GUC raises an error, and every query
 *    made before the tenant is resolved would blow up instead of returning nothing.
 *    With it we get NULL, and `tenant_id = NULL` is never true — so the failure mode
 *    is "no rows", not "all rows". The isolation layer fails **closed**.
 *
 * 3. **`WITH CHECK` as well as `USING`.** `USING` filters what you can read; without
 *    `WITH CHECK` a tenant could still INSERT or UPDATE a row carrying someone else's
 *    `tenant_id`. Writes are the direction that corrupts data permanently.
 */
trait EnablesRowLevelSecurity
{
    /**
     * Turn on RLS for a tenant-owned table.
     *
     * @param  string  $column  the tenancy discriminator; only change it for a table
     *                          that genuinely scopes by something else
     */
    protected function enableRls(
        string $table,
        string $column = 'tenant_id',
        bool $allowNullTenant = false,
        bool $allowPlatform = false,
    ): void {
        $this->guardIdentifier($table);
        $this->guardIdentifier($column);

        $policy = $this->policyName($table);

        // NULLIF turns the empty-string GUC ("set but blank") into NULL as well, so
        // both "never set" and "explicitly cleared" deny access identically.
        $current = "NULLIF(current_setting('app.tenant_id', true), '')::bigint";

        // A table holding BOTH tenant and central rows needs NULL = NULL to be true:
        // in a tenant context you see only that tenant's rows, and with no context you
        // see only the central ones. Plain `=` alone would make every central row
        // invisible to everybody.
        //
        // Spelled as an OR rather than the `IS NOT DISTINCT FROM` that says the same
        // thing in one operator, because **`IS NOT DISTINCT FROM` is not btree
        // indexable**. A policy using it silently disables every index on the table it
        // guards — the predicate is applied to every query, so every query becomes a
        // sequential scan no matter what the query itself asks for or what indexes
        // exist. Measured on `activity_log` at 1.8M rows (fifty shops, one year):
        // 55.8ms scanning the whole platform, against 0.155ms with this form, and the
        // gap widens with every shop added because the scan is of the table rather
        // than of one tenant's slice of it.
        //
        // The `= {$current}` branch is an ordinary indexable equality; the second
        // branch matches central rows only when there is no context at all, and is a
        // constant-false test the planner discards the rest of the time.
        $predicate = $allowNullTenant
            ? "({$column} = {$current} OR ({$column} IS NULL AND {$current} IS NULL))"
            : "{$column} = {$current}";

        // Platform-owned tables (subscriptions, billing) must stay invisible to a
        // tenant while remaining readable by the Platform module, which legitimately
        // reports across every shop for MRR and churn. A blanket exemption would give
        // that up everywhere; this makes it an explicit, opt-in session flag that only
        // TenantContext::runAsPlatform() sets — so every cross-tenant read is a
        // deliberate, greppable act rather than an accident.
        if ($allowPlatform) {
            $predicate = "({$predicate} OR current_setting('app.platform', true) = '1')";
        }

        DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
        DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");

        DB::statement("DROP POLICY IF EXISTS {$policy} ON {$table}");
        DB::statement(
            "CREATE POLICY {$policy} ON {$table} USING ({$predicate}) WITH CHECK ({$predicate})"
        );
    }

    /**
     * Reverse of enableRls(), for a migration's down().
     */
    protected function disableRls(string $table): void
    {
        $this->guardIdentifier($table);

        $policy = $this->policyName($table);

        DB::statement("DROP POLICY IF EXISTS {$policy} ON {$table}");
        DB::statement("ALTER TABLE {$table} NO FORCE ROW LEVEL SECURITY");
        DB::statement("ALTER TABLE {$table} DISABLE ROW LEVEL SECURITY");
    }

    protected function policyName(string $table): string
    {
        return "{$table}_tenant_isolation";
    }

    /**
     * These identifiers are interpolated into DDL, which cannot be parameterised.
     * They come from migration source rather than user input, but a typo that happens
     * to contain a quote should fail loudly here rather than produce strange SQL.
     */
    private function guardIdentifier(string $identifier): void
    {
        if (preg_match('/^[a-z_][a-z0-9_]*$/', $identifier) !== 1) {
            throw new InvalidArgumentException(
                "Refusing to build DDL from the identifier [{$identifier}]: expected lowercase snake_case."
            );
        }
    }
}
