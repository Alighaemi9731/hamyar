<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Platform\Models\Plan;
use App\Modules\Platform\Models\Subscription;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Platform\Services\PlanCatalogueSeeder;
use App\Modules\Platform\Services\TenantProvisioner;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\BulkVolumeSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Fill the database with a launch-sized platform: many shops, each with a real year.
 *
 * Roadmap 11.2. Launch is ~40–50 of the owner's own customers evaluating concurrently,
 * and almost everything that goes wrong at that size is invisible below it.
 * `BulkVolumeSeeder` already builds one convincing shop; this builds the *platform*, and
 * the difference is the point of the exercise:
 *
 * - **A query's cost stops being about one shop.** 11c found `activity_log` scanning the
 *   whole table to answer a question about one tenant, because the RLS predicate used an
 *   operator no index could serve. On a single-tenant database that reads as fast. The
 *   defect only has a shape when there are forty-nine other shops for the scan to wade
 *   through ([ADR 0002](../../../docs/adr/0002-single-db-tenancy-rls.md), amendment 3).
 * - **The planner starts choosing differently.** A tenant predicate that selects 100% of
 *   a table is not the same predicate as one selecting 2%, and Postgres will pick a
 *   different plan for the identical SQL.
 * - **Isolation gets something to fail at.** With three shops a leak might never
 *   surface; with fifty it will, and the worst place to find out is in front of a
 *   customer.
 *
 * ## This is a development tool and says so
 *
 * It refuses to run in production without `--force`, and even then it only ever *adds*
 * shops — it never truncates. The names are transparently fake (`بار سنجی ۱۲`) so nobody
 * mistakes a load fixture for a customer, and every shop it creates carries the
 * `load-test-` slug prefix so they can be found and removed as a set.
 */
final class SeedPlatformVolumeCommand extends Command
{
    /**
     * Slugs start with this so the fixture can always be told from real data, and
     * removed by `--fresh` without a list of ids anybody has to keep.
     */
    private const SLUG_PREFIX = 'load-test-';

    protected $signature = 'platform:seed-volume
        {--tenants=50 : How many shops to create}
        {--invoices= : Invoices per shop; defaults to BulkVolumeSeeder::INVOICES}
        {--fresh : Remove shops from a previous run of this command first}
        {--force : Allow the command to run in production}';

    protected $description = 'Seed a launch-sized platform: N shops, each with a year of trading (roadmap 11.2)';

    public function handle(TenantProvisioner $provisioner, PlanCatalogueSeeder $plans, TenantContext $context): int
    {
        if (app()->isProduction() && $this->option('force') !== true) {
            $this->components->error('Refusing to seed load-test data into production. Pass --force if that is genuinely what you want.');

            return self::FAILURE;
        }

        $tenants = max(1, (int) $this->option('tenants'));
        $invoices = $this->option('invoices') !== null
            ? max(1, (int) $this->option('invoices'))
            : BulkVolumeSeeder::INVOICES;

        if ($this->option('fresh') === true) {
            $this->removePreviousRun();
        }

        $plans->sync();

        /*
        | The unlimited rung, not `pro`, and that is a correctness requirement rather than
        | generosity.
        |
        | This command writes thousands of invoices per shop in one go, and since ADR 0018
        | every one of them spends a `sales.invoices` credit. On `pro` the seeder would
        | stop dead a few hundred rows in with «سقف ماهانه شما پر شده است» — which is the
        | product working exactly as designed, and a load test that never gets its data.
        */
        /** @var Plan $plan */
        $plan = Plan::query()->where('code', 'enterprise')->firstOrFail();

        $seeder = new BulkVolumeSeeder;
        $started = microtime(true);
        $totals = [];

        $this->components->info(sprintf('Seeding %d shops × %s invoices each.', $tenants, number_format($invoices)));

        $bar = $this->output->createProgressBar($tenants);
        $bar->start();

        for ($i = 1; $i <= $tenants; $i++) {
            try {
                $tenant = $this->provisionShop($provisioner, $i);

                $this->subscribe($context, $tenant, $plan);

                foreach ($seeder->fill($tenant, $invoices) as $table => $count) {
                    $totals[$table] = ($totals[$table] ?? 0) + $count;
                }
            } catch (Throwable $exception) {
                // One shop failing must not cost the forty-nine already seeded: this
                // runs for minutes, and restarting from zero to re-hit the same error
                // is how a tool like this stops being used.
                $bar->clear();
                $this->components->warn("Shop {$i} failed: {$exception->getMessage()}");
                $bar->display();
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->report($totals, microtime(true) - $started);

        return self::SUCCESS;
    }

    private function provisionShop(TenantProvisioner $provisioner, int $index): Tenant
    {
        $slug = self::SLUG_PREFIX.$index;

        return $provisioner->provision([
            'name' => 'بار سنجی '.$index,
            'subdomain' => $slug,
            'owner_name' => 'مالک '.$index,
            // Unique per shop and obviously synthetic. A real-looking number in a load
            // fixture is one somebody eventually sends an SMS to.
            'owner_mobile' => '0900'.str_pad((string) $index, 7, '0', STR_PAD_LEFT),
            'owner_email' => "owner{$index}@load-test.invalid",
            'password' => 'password',
        ]);
    }

    /**
     * Put the shop on the unlimited plan so the seeder is not metered mid-run.
     *
     * Not about reaching modules any more — since DECISION GATE 6 every module is open to
     * every shop. It is about quantity: see the note at the call site.
     *
     * `subscriptions` is RLS-protected and platform-owned, so selling a plan is a
     * platform act — the same wrapper `TenantProvisioner` uses. If this ever works
     * without it, the policy has been weakened (ADR 0002 amendment 1).
     */
    private function subscribe(TenantContext $context, Tenant $tenant, Plan $plan): void
    {
        $context->runAsPlatform(fn (): Subscription => Subscription::query()->create([
            'tenant_id' => $tenant->getKey(),
            'plan_id' => $plan->getKey(),
            'status' => Subscription::STATUS_ACTIVE,
            'current_period_start' => now()->subDays(10),
            'current_period_end' => now()->addDays(20),
        ]));
    }

    /**
     * Delete the shops a previous run created.
     *
     * By slug prefix rather than by "everything", so a database that also holds the demo
     * tenant and hand-made scratch shops keeps them. The tenant cascade takes the rest —
     * every tenant table's foreign key is `ON DELETE CASCADE`.
     */
    private function removePreviousRun(): void
    {
        $previous = Tenant::query()->where('slug', 'like', self::SLUG_PREFIX.'%')->pluck('id');

        if ($previous->isEmpty()) {
            return;
        }

        $this->components->info("Removing {$previous->count()} shops from a previous run.");

        // Chunked: fifty tenants' cascade is millions of rows, and one statement holding
        // all of it is a lock held for the duration and a transaction log to match.
        $previous->chunk(5)->each(function ($ids): void {
            DB::table('tenants')->whereIn('id', $ids)->delete();
        });
    }

    /**
     * @param  array<string, int>  $totals
     */
    private function report(array $totals, float $seconds): void
    {
        ksort($totals);

        $this->components->twoColumnDetail('<fg=green;options=bold>rows written</>', '');

        foreach ($totals as $table => $count) {
            $this->components->twoColumnDetail($table, number_format($count));
        }

        $this->components->twoColumnDetail(
            '<options=bold>elapsed</>',
            sprintf('%.1fs', $seconds),
        );

        $this->newLine();

        // The reason the fixture exists, said out loud: it is for EXPLAIN, and a plan
        // read against stale statistics is a plan for a database nobody has.
        $this->components->info('Statistics are analysed per step by the seeder. Measure with EXPLAIN (ANALYZE, BUFFERS) as the app role, with app.tenant_id set — a superuser bypasses RLS and will show you a plan the application never gets.');
    }
}
