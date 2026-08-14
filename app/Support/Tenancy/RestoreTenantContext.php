<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use App\Modules\Platform\Models\Tenant;
use Closure;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Job middleware that enters the job's tenant before `handle()` and leaves it after.
 *
 * Paired with {@see TenantAware}. Kept as a separate class because Laravel serialises
 * job middleware, and a closure would not survive the queue.
 */
final class RestoreTenantContext
{
    public function handle(object $job, Closure $next): mixed
    {
        $tenantId = property_exists($job, 'tenantId') ? $job->tenantId : null;

        $context = app(TenantContext::class);

        if (! is_int($tenantId)) {
            // A tenant-aware job with no tenant is a dispatch-site bug. Failing here
            // is far kinder than running it: RLS would return nothing and the job
            // would "succeed" having silently done nothing at all.
            Log::error('Tenant-aware job dispatched without a tenant.', ['job' => $job::class]);

            throw new RuntimeException(
                'Job ['.$job::class.'] uses TenantAware but carries no tenant id. '
                .'It was probably dispatched from a context with no tenant.'
            );
        }

        // withoutTenancy() is not enough here: the tenant may have been suspended or
        // deleted between dispatch and execution, and reading it back is how we find
        // out rather than writing into a dead shop.
        $tenant = $context->runWithoutTenant(
            fn (): ?Tenant => Tenant::query()->find($tenantId)
        );

        if (! $tenant instanceof Tenant) {
            Log::warning('Skipping job for a tenant that no longer exists.', [
                'job' => $job::class,
                'tenant_id' => $tenantId,
            ]);

            return null;
        }

        /*
        | Whether anybody was already in a tenant when this job started.
        |
        | On a worker: nobody. The job is the only thing running, and the context must be
        | cleared afterwards so the NEXT job — possibly another shop's — cannot inherit it.
        |
        | On the `sync` driver: somebody. The job runs inline, inside the caller's context,
        | because a listener dispatched it mid-request. Clearing unconditionally then wipes
        | the CALLER's tenant and the next line of their code fails with "no tenant is set"
        | — which is what happened the first time a repair transition fired an SMS listener
        | during a seeded scenario, and it read as a tenancy bug in the seeder.
        |
        | `runFor()` restores the previous context either way; this only decides whether to
        | wipe what it restored.
        */
        $enteredFromNoTenant = $context->id() === null;

        try {
            return $context->runFor($tenant, fn (): mixed => $next($job));
        } finally {
            if ($enteredFromNoTenant) {
                $context->forget();
            }
        }
    }
}
