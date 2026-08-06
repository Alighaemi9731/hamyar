<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use App\Modules\Platform\Models\Tenant;

/**
 * Fired whenever the current tenant changes, including when it is cleared.
 *
 * Exists so subsystems that cache tenant-derived state can invalidate it at exactly
 * the right moment. spatie/laravel-permission is the motivating case: it resolves
 * roles against a "team id" it holds internally, and a stale one means a user's
 * permissions are evaluated against the wrong shop.
 */
final class TenantContextChanged
{
    public function __construct(
        public readonly ?Tenant $tenant,
    ) {}

    public function tenantId(): ?int
    {
        $key = $this->tenant?->getKey();

        return is_int($key) ? $key : null;
    }
}
