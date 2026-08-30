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
     * Tenant tables owned by the Platform module.
     *
     * These still REQUIRE RLS — the check below enforces it — but are exempt from the
     * `BelongsToTenant` requirement. The Eloquent scope would fight the cross-tenant
     * reads the platform legitimately performs for MRR and churn; their policies use
     * the `app.platform` escape hatch instead, which only
     * `TenantContext::runAsPlatform()` can set.
     *
     * Adding a table here is a deliberate act. It buys an exemption from the Eloquent
     * scope only, never from RLS.
     *
     * @var list<string>
     */
    private const PLATFORM_OWNED_TABLES = [
        'subscriptions',
        'subscription_invoices',
        'subscription_addons',
        'payment_attempts',

        // Phase 12 (ADR 0018). The panel's usage page and the blocked-shops widget read
        // these across every shop; the Eloquent scope would return nothing inside
        // runAsPlatform(), so they carry RLS and an explicit `where tenant_id` on every
        // query instead — enforced by `bin/check-quota-scoping`.
        'usage_counters',
        'tenant_limit_overrides',
        'usage_events',
    ];

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

    /**
     * Unique indexes that are global **on purpose**, each with the reason.
     *
     * Two shapes qualify, and nothing else should be added without one:
     *
     * · a **credential** — a token whose whole job is to be unguessable and to resolve
     *   without a tenant context, because the holder has no session (see ADR 0002's
     *   platform escape and `PriceListAccess::resolve()`);
     * · a **public identifier** — something that appears in a URL or is issued by a third
     *   party, where two shops claiming the same value is precisely what must be refused.
     *
     * @var array<string, string>
     */
    private const GLOBALLY_UNIQUE_BY_DESIGN = [
        'price_list_links_lookup_unique' => 'Bearer credential: resolved before any tenant is known, by design.',
        // ADR 0017: with one address for every shop, these two are what tells a customer's
        // request which shop it belongs to. They were per-tenant while the hostname did
        // that job.
        'users_mobile_unique' => 'One number, one account, one shop (ADR 0017) — the login form has no hostname left to disambiguate with.',
        'repair_tickets_tracking_token_unique' => 'Bearer credential: the QR on a repair receipt, scanned by a customer with no session (ADR 0017).',
        'repair_tickets_approval_token_unique' => 'Bearer credential: a quote-approval link, opened by a customer with no session (ADR 0017).',
        'invitations_token_hash_unique' => 'Bearer credential: an invite is opened by somebody with no session yet.',
        'payment_attempts_authority_unique' => 'Issued by Zarinpal, not by us — global uniqueness is the gateway\'s.',
        'storefront_settings_slug_unique' => 'Public path segment: two shops claiming /shop/mobile-iran must collide.',
    ];

    public function handle(): int
    {
        $problems = [
            ...$this->checkTablesHaveRls(),
            ...$this->checkModelsUseTrait(),
            ...$this->checkCompositeIndexes(),
            ...$this->checkUniqueIndexesAreScoped(),
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
     * A UNIQUE index on a tenant table must include `tenant_id`.
     *
     * ## Why this is a tenancy rule and not a schema preference
     *
     * A unique index without the tenant is a **constraint one shop can impose on another**.
     * The first shop to register `customer@gmail.com`, or SKU `IP15-256`, or invoice number
     * `1405-0001`, takes that value away from all 49 others — and the failure surfaces at
     * their counter as a validation error about a record they cannot see, which is
     * unexplainable and unfixable by them.
     *
     * It is also an **existence oracle**: a 23505 on insert confirms that *somebody* holds
     * the value, which is the enumeration the isolation suite's 404-not-403 rule exists to
     * prevent, arriving through the schema instead of through a route.
     *
     * With three pilot shops a collision might never happen. With fifty evaluators loading
     * their real catalogues on the same afternoon, it is the first thing that breaks — and
     * `tenancy:check` is where somebody is already looking when they think about isolation.
     *
     * Primary keys are exempt (`id` alone is meant to be globally unique), and so are
     * indexes on tables that carry no `tenant_id`.
     *
     * @return list<array{subject: string, issue: string}>
     */
    private function checkUniqueIndexesAreScoped(): array
    {
        $problems = [];

        /** @var list<object{tbl: string, idx: string, cols: string}> $rows */
        $rows = DB::select(
            "select t.relname as tbl,
                    i.relname as idx,
                    array_to_string(array_agg(a.attname order by k.ord), ', ') as cols
             from pg_class t
             join pg_index ix on t.oid = ix.indrelid
             join pg_class i on i.oid = ix.indexrelid
             join unnest(ix.indkey) with ordinality k(attnum, ord) on true
             join pg_attribute a on a.attrelid = t.oid and a.attnum = k.attnum
             join pg_namespace n on n.oid = t.relnamespace
             where n.nspname = 'public'
               and ix.indisunique = true
               and t.relkind = 'r'
             group by t.relname, i.relname
             order by t.relname, i.relname"
        );

        $tenantTables = $this->tenantTables();
        $scopingColumns = $this->columnsScopedByForeignKey($tenantTables);

        foreach ($rows as $row) {
            if (! in_array($row->tbl, $tenantTables, true)) {
                continue;
            }

            $columns = array_map('trim', explode(',', $row->cols));

            // The primary key. Globally unique by design.
            if ($columns === ['id']) {
                continue;
            }

            if (in_array('tenant_id', $columns, true)) {
                continue;
            }

            // Transitively scoped: the index leads with a foreign key to a row that is
            // itself tenant-owned, so two shops cannot collide on it.
            if (array_intersect($columns, $scopingColumns[$row->tbl] ?? []) !== []) {
                continue;
            }

            if (isset(self::GLOBALLY_UNIQUE_BY_DESIGN[$row->idx])) {
                continue;
            }

            $problems[] = [
                'subject' => "index {$row->idx}",
                'issue' => "unique on ({$row->cols}) without tenant_id — one shop can take a value from every other",
            ];
        }

        return $problems;
    }

    /**
     * Columns on each table that are foreign keys into a tenant-scoped table.
     *
     * A unique index on `(branch_id, user_id)` needs no `tenant_id`: a `branch_id` belongs
     * to exactly one shop, so the pair cannot collide across shops. Without this the check
     * reports ten findings on a schema with none, and a gate that cries wolf is a gate
     * somebody comments out.
     *
     * @param  list<string>  $tenantTables
     * @return array<string, list<string>>
     */
    private function columnsScopedByForeignKey(array $tenantTables): array
    {
        /** @var list<object{tbl: string, col: string, ref: string}> $rows */
        $rows = DB::select(
            "select c.relname as tbl, a.attname as col, f.relname as ref
             from pg_constraint con
             join pg_class c on c.oid = con.conrelid
             join pg_class f on f.oid = con.confrelid
             join unnest(con.conkey) with ordinality k(attnum, ord) on true
             join pg_attribute a on a.attrelid = c.oid and a.attnum = k.attnum
             join pg_namespace n on n.oid = c.relnamespace
             where con.contype = 'f' and n.nspname = 'public'"
        );

        $scoped = [];

        foreach ($rows as $row) {
            // The referenced table must itself be tenant-scoped for the FK to scope us.
            if (! in_array($row->ref, $tenantTables, true)) {
                continue;
            }

            $scoped[$row->tbl][] = $row->col;
        }

        return $scoped;
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

            // RLS is still enforced for these above; only the scope is exempt.
            if (in_array($table, self::PLATFORM_OWNED_TABLES, true)) {
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
