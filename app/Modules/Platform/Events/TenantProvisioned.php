<?php

declare(strict_types=1);

namespace App\Modules\Platform\Events;

use App\Modules\Platform\Models\Tenant;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A new shop exists: tenant row, domain, roles, owner and trial are all in place.
 *
 * The hook other modules use to create their own starting data — Inventory makes the
 * first branch and warehouse, Treasury will make the first cash account. Platform must
 * not reach into those modules itself (golden rule 6), and equally must not be the place
 * that knows what a warehouse is.
 *
 * Dispatched **inside** the provisioning transaction and inside the tenant's context, so
 * listeners can write tenant-scoped rows directly and any failure rolls back the entire
 * signup. That is deliberate: a shop that exists but has no warehouse is worse than a
 * signup that failed and can be retried.
 */
final class TenantProvisioned
{
    use Dispatchable;

    public function __construct(public readonly Tenant $tenant) {}
}
