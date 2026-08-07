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

        try {
            return $context->runFor($tenant, fn (): mixed => $next($job));
        } finally {
            // runFor() already restores the previous context, but a worker should
            // never be left holding a tenant between jobs.
            $context->forget();
        }
    }
}
