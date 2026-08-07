<?php

declare(strict_types=1);

namespace App\Modules\Platform\Console\Commands;

use App\Modules\Platform\Models\Tenant;
use App\Modules\Platform\Services\TenantProvisioner;
use App\Support\Tenancy\InteractsWithTenants;
use Illuminate\Console\Command;

/**
 * Bring every tenant's roles in line with the permission catalogue.
 *
 * The first real user of `--tenant=`, and the reason it exists: shipping a new
 * `module.action` adds a row to the central `permissions` table, but each tenant's
 * *roles* are its own, so the system roles need re-syncing per shop.
 *
 *   php artisan tenancy:sync-permissions              # every usable tenant
 *   php artisan tenancy:sync-permissions --tenant=demo
 *   php artisan tenancy:sync-permissions --tenant=3 --tenant=acme
 *
 * Only system roles are touched. A role a shop created itself is left alone —
 * overwriting a customer's own configuration during a routine deploy would be a
 * spectacular way to lose their trust.
 */
final class TenantSyncPermissionsCommand extends Command
{
    use InteractsWithTenants;

    protected $signature = 'tenancy:sync-permissions {--tenant=* : Tenant slug or id; repeatable. Omit for all usable tenants}';

    protected $description = 'Re-sync the seeded system roles against the permission catalogue';

    public function handle(TenantProvisioner $provisioner): int
    {
        return $this->eachTenant(function (Tenant $tenant) use ($provisioner): void {
            $provisioner->seedRoles($tenant);

            $this->components->twoColumnDetail($tenant->slug, '<fg=green>synced</>');
        });
    }
}
