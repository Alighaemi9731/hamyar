<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Listeners;

use App\Modules\Inventory\Models\Branch;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Platform\Events\TenantProvisioned;

/**
 * Give a new shop one branch and one warehouse.
 *
 * Most Iranian phone shops are a single storefront and will never add a second, so these
 * concepts have to exist without ever being configured. Every later feature — stock
 * movements, invoice numbering, transfers — can then assume a branch and a warehouse
 * exist rather than defending against their absence.
 *
 * Synchronous, not queued: it runs inside the provisioning transaction, so a failure
 * rolls back the signup instead of leaving a shop that cannot receive stock.
 */
final class CreateDefaultLocation
{
    public function handle(TenantProvisioned $event): void
    {
        $tenantId = $event->tenant->getKey();

        $branch = Branch::query()->create([
            'tenant_id' => $tenantId,
            'name' => $event->tenant->name,
            // Latin and short, because it is embedded in document numbers; the shop's
            // Persian name would render awkwardly inside an invoice code.
            'code' => 'MAIN',
            'is_default' => true,
            'is_active' => true,
        ]);

        Warehouse::query()->create([
            'tenant_id' => $tenantId,
            'branch_id' => $branch->getKey(),
            'name' => 'انبار اصلی',
            // A single-branch shop's one warehouse IS the shop floor. A repair bench is
            // something they add later, deliberately.
            'is_sellable' => true,
            'is_default' => true,
            'is_active' => true,
        ]);
    }
}
