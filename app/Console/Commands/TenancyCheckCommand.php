<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use ReflectionClass;
use SplFileInfo;

/**
 * Fails the build when a tenant table or model is missing part of its isolation.
 *
 * Golden rule 1 asks for four things on every tenant table: a `tenant_id` column, a
 * composite index starting with it, the `BelongsToTenant` trait on the model, and an
 * RLS policy. Three of those are easy to forget in a hurry and none of them fails
 * visibly at the time — the code works fine until it is the thing that leaks a shop's
 * data. So this runs in CI.
 *
 * It checks the database as it actually is, not the migration source, which means it
 * also catches a policy dropped by hand or a table created outside a migration.
 */
final class TenancyCheckCommand extends Command
{
    protected $signature = 'tenancy:check';

    protected $description = 'Verify every tenant table has RLS and every tenant model uses BelongsToTenant';

    /**
     * Tables that legitimately have no `tenant_id`.
     *
     * `sessions` is the interesting one: it carries a nullable `tenant_id` for the
     * management screen, but must stay readable before login (when there is no tenant)
     * or nobody could ever authenticate. It is protected by the unguessable session id.
     *
     * @var list<string>
     */
    private const EXEMPT_TABLES = [
        'migrations',
        'cache',
        'cache_locks',
        'jobs',
        'job_batches',
        'failed_jobs',
        'tenants',
        'domains',
        'platform_users',
        'permissions',
        'role_has_permissions',
        'sessions',
    ];

    public function handle(): int
    {
        $problems = [
            ...$this->checkTablesHaveRls(),
            ...$this->checkModelsUseTrait(),
            ...$this->checkCompositeIndexes(),
        ];

        if ($problems === []) {
            $this->components->info('Tenancy check passed: every tenant table has RLS and every tenant model is scoped.');

            return self::SUCCESS;
        }

        $this->components->error('Tenancy check FAILED — golden rule 1.');

        foreach ($problems as $problem) {
            $this->components->twoColumnDetail($problem['subject'], "<fg=red>{$problem['issue']}</>");
        }

        $this->newLine();
        $this->line('  Every tenant table needs: tenant_id + composite index + BelongsToTenant + enableRls().');

        return self::FAILURE;
    }

    /**
     * A table with a `tenant_id` column but no policy is the dangerous case: the
     * Eloquent scope hides the problem in day-to-day use and a raw query exposes it.
     *
     * @return list<array{subject: string, issue: string}>
     */
    private function checkTablesHaveRls(): array
    {
        $problems = [];

        foreach ($this->tenantTables() as $table) {
            /** @var list<object{relrowsecurity: bool, relforcerowsecurity: bool}> $rows */
            $rows = DB::select(
                'select relrowsecurity, relforcerowsecurity from pg_class where relname = ? and relkind = \'r\'',
                [$table]
            );

            $flags = $rows[0] ?? null;

            if ($flags === null) {
                continue;
            }

            if (! $flags->relrowsecurity) {
                $problems[] = ['subject' => "table {$table}", 'issue' => 'row level security is not enabled'];

                continue;
            }

            if (! $flags->relforcerowsecurity) {
                // Without FORCE the owner bypasses the policy — and our application
                // role owns the tables it migrates, so the policy would protect nobody.
                $problems[] = ['subject' => "table {$table}", 'issue' => 'FORCE ROW LEVEL SECURITY is missing (the owner bypasses the policy)'];
            }

            /** @var list<object{count: int}> $policies */
            $policies = DB::select('select count(*) as count from pg_policies where tablename = ?', [$table]);

            if (($policies[0]->count ?? 0) === 0) {
                $problems[] = ['subject' => "table {$table}", 'issue' => 'RLS is enabled but no policy exists (denies everything)'];
            }
        }

        return $problems;
    }

    /**
     * @return list<array{subject: string, issue: string}>
     */
    private function checkCompositeIndexes(): array
    {
        $problems = [];

        foreach ($this->tenantTables() as $table) {
            /** @var list<object{indexdef: string}> $indexes */
            $indexes = DB::select('select indexdef from pg_indexes where tablename = ?', [$table]);

            $hasTenantFirst = false;

            foreach ($indexes as $index) {
                // Matches "(tenant_id)" and "(tenant_id, status)" but not
                // "(user_id, tenant_id)" — a trailing tenant_id does not help the
                // planner for the queries the global scope generates.
                if (preg_match('/\(\s*tenant_id\b/', $index->indexdef) === 1) {
                    $hasTenantFirst = true;

                    break;
                }
            }

            if (! $hasTenantFirst) {
                $problems[] = [
                    'subject' => "table {$table}",
                    'issue' => 'no index leads with tenant_id — every scoped query will seq-scan',
                ];
            }
        }

        return $problems;
    }

    /**
     * @return list<array{subject: string, issue: string}>
     */
    private function checkModelsUseTrait(): array
    {
        $problems = [];
        $tenantTables = $this->tenantTables();

        foreach ($this->moduleModels() as $class) {
            $reflection = new ReflectionClass($class);

            if ($reflection->isAbstract()) {
                continue;
            }

            /** @var Model $model */
            $model = $reflection->newInstanceWithoutConstructor();
            $table = $model->getTable();

            if (! in_array($table, $tenantTables, true)) {
                continue;
            }

            if (! in_array(BelongsToTenant::class, $this->traitsOf($class), true)) {
                $problems[] = [
                    'subject' => $class,
                    'issue' => "backs the tenant table [{$table}] but does not use BelongsToTenant",
                ];
            }
        }

        return $problems;
    }

    /**
     * Tables carrying a non-nullable `tenant_id` — the definition of "tenant-owned".
     *
     * @return list<string>
     */
    private function tenantTables(): array
    {
        /** @var list<object{table_name: string}> $rows */
        $rows = DB::select(
            "select table_name from information_schema.columns
             where table_schema = 'public' and column_name = 'tenant_id' and is_nullable = 'NO'
             order by table_name"
        );

        return array_values(array_filter(
            array_map(static fn (object $row): string => $row->table_name, $rows),
            static fn (string $table): bool => ! in_array($table, self::EXEMPT_TABLES, true),
        ));
    }

    /**
     * @return list<class-string<Model>>
     */
    private function moduleModels(): array
    {
        $paths = glob(base_path('app/Modules/*/Models/*.php')) ?: [];

        $models = [];

        foreach ($paths as $path) {
            $file = new SplFileInfo($path);
            $relative = Str::after($path, base_path().'/app/');
            $class = 'App\\'.str_replace('/', '\\', Str::beforeLast($relative, '.php'));

            if (! class_exists($class) || ! is_subclass_of($class, Model::class)) {
                continue;
            }

            unset($file);

            /** @var class-string<Model> $class */
            $models[] = $class;
        }

        return $models;
    }

    /**
     * Traits used by a class and by all of its parents.
     *
     * @param  class-string  $class
     * @return list<string>
     */
    private function traitsOf(string $class): array
    {
        $traits = [];

        for ($current = $class; $current !== false; $current = get_parent_class($current)) {
            $traits = [...$traits, ...array_values(class_uses($current) ?: [])];
        }

        return array_values(array_unique($traits));
    }
}
