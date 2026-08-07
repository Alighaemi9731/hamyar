<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use App\Modules\Platform\Models\Tenant;
use Closure;
use Throwable;

/**
 * Adds `--tenant=` to a console command.
 *
 * Console has no hostname to resolve, so a maintenance command starts with no tenant
 * and RLS denies it every tenant table. That is the right default — a command that
 * forgot to pick a tenant should return nothing, not everything — but it means any
 * command touching tenant data has to say which shop, or ask for all of them.
 *
 * Usage:
 *
 *     protected $signature = 'stock:recount {--tenant=*}';
 *
 *     public function handle(): int
 *     {
 *         return $this->eachTenant(function (Tenant $tenant): void {
 *             // runs with the context pinned to $tenant
 *         });
 *     }
 *
 * `--tenant=` accepts a slug or an id and may be repeated. Omitting it entirely runs
 * across every usable tenant, which is what a nightly maintenance job wants.
 */
trait InteractsWithTenants
{
    /**
     * Run a callback once per selected tenant, with the context pinned each time.
     *
     * @param  Closure(Tenant): void  $callback
     * @return int a command exit code
     */
    protected function eachTenant(Closure $callback): int
    {
        $context = app(TenantContext::class);

        $tenants = $this->selectedTenants();

        if ($tenants === []) {
            $this->components->error('No matching tenant found.');

            return self::FAILURE;
        }

        $failed = 0;

        foreach ($tenants as $tenant) {
            try {
                $context->runFor($tenant, fn () => $callback($tenant));
            } catch (Throwable $exception) {
                // One bad shop must not abort a nightly run across thousands. Report
                // it, keep going, and fail the command at the end so CI/cron notices.
                $failed++;

                $this->components->error(
                    "Tenant [{$tenant->slug}] failed: ".$exception->getMessage()
                );
            }
        }

        $context->forget();

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return list<Tenant>
     */
    protected function selectedTenants(): array
    {
        /** @var list<string> $requested */
        $requested = (array) $this->option('tenant');

        $requested = array_values(array_filter($requested, static fn (mixed $v): bool => is_string($v) && $v !== ''));

        $query = Tenant::query()->orderBy('id');

        if ($requested !== []) {
            $query->where(function ($builder) use ($requested): void {
                $builder->whereIn('slug', $requested);

                $ids = array_values(array_filter($requested, static fn (string $v): bool => ctype_digit($v)));

                if ($ids !== []) {
                    $builder->orWhereIn('id', $ids);
                }
            });
        } else {
            // Unattended runs skip suspended and archived shops: writing into a
            // suspended tenant is almost never what a maintenance command means.
            $query->whereIn('status', [Tenant::STATUS_TRIALING, Tenant::STATUS_ACTIVE]);
        }

        /** @var list<Tenant> */
        return $query->get()->all();
    }
}
