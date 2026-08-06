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
    protected function enableRls(string $table, string $column = 'tenant_id', bool $allowNullTenant = false): void
    {
        $this->guardIdentifier($table);
        $this->guardIdentifier($column);

        $policy = $this->policyName($table);

        // NULLIF turns the empty-string GUC ("set but blank") into NULL as well, so
        // both "never set" and "explicitly cleared" deny access identically.
        $current = "NULLIF(current_setting('app.tenant_id', true), '')::bigint";

        // `IS NOT DISTINCT FROM` treats NULL = NULL as true, which is what a table
        // holding BOTH tenant and central rows needs: in a tenant context you see only
        // that tenant's rows, and with no context you see only the central ones.
        // Plain `=` would make every central row invisible to everybody.
        $predicate = $allowNullTenant
            ? "{$column} IS NOT DISTINCT FROM {$current}"
            : "{$column} = {$current}";

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
