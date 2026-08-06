<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use App\Modules\Platform\Models\Tenant;

/**
 * Makes a queued job run under the tenant that dispatched it.
 *
 * A job is serialised now and executed later, in a different process, on a worker that
 * has just finished a job for a different shop. Without this, `handle()` would run
 * with whatever context the worker happened to be left in — which is either "nothing"
 * (RLS denies everything, the job fails mysteriously) or, far worse, the *previous*
 * tenant's.
 *
 * Usage:
 *
 *     final class SendInvoiceSms implements ShouldQueue
 *     {
 *         use Queueable, TenantAware;
 *     }
 *
 * The tenant id is captured at construction — that is, at dispatch time, on the web
 * request that still has a context — and restored in `middleware()`, which Laravel
 * runs around `handle()`. The `finally` clears it again so the worker is left clean
 * for the next job whatever happens, including a failure.
 */
trait TenantAware
{
    public ?int $tenantId = null;

    /**
     * Capture the dispatching tenant. Called automatically by `initializeTenantAware`
     * when the trait is used on a job that also uses Laravel's Queueable.
     */
    public function initializeTenantAware(): void
    {
        $this->tenantId ??= app(TenantContext::class)->id();
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [new RestoreTenantContext];
    }
}
