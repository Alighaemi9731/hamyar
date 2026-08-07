<?php

declare(strict_types=1);

namespace App\Modules\Identity\Models;

use App\Support\Tenancy\TenantContext;
use Spatie\Activitylog\Models\Activity as BaseActivity;

/**
 * Tenant-aware audit record.
 *
 * spatie's model knows nothing about tenancy, so every entry it wrote arrived with a
 * null `tenant_id`. Under the RLS policy on `activity_log` that is not a silent
 * mis-attribution — it is a rejected INSERT, because a row with no tenant fails the
 * policy's WITH CHECK while a tenant context is active. The isolation layer caught it
 * before any data was written, which is exactly the behaviour we wanted from it.
 *
 * So the tenant is stamped here, at the one place every activity row passes through.
 *
 * `tenant_id` stays nullable on purpose: central actions (a platform admin suspending
 * a shop, an impersonation starting) genuinely belong to no tenant, and the null-
 * tolerant policy keeps those visible only in the central context.
 */
final class Activity extends BaseActivity
{
    protected static function booted(): void
    {
        self::creating(function (self $activity): void {
            if ($activity->getAttribute('tenant_id') === null) {
                $activity->setAttribute('tenant_id', app(TenantContext::class)->id());
            }
        });
    }
}
