<?php

declare(strict_types=1);

namespace App\Modules\CRM\Listeners;

use App\Modules\CRM\Models\Account;
use App\Modules\Platform\Events\TenantProvisioned;

/**
 * Give a new shop a cash drawer, an inventory account and a sales account.
 *
 * Every sale needs somewhere to post the money side and every purchase needs somewhere
 * to post the value side, so both have to exist before the first transaction rather than
 * after someone discovers a settings screen.
 *
 * Three accounts, not a chart of accounts. Phase 7 (Treasury) brings the real thing;
 * these exist so Phases 3.5 and 5 have somewhere to post.
 */
final class CreateDefaultAccount
{
    public function handle(TenantProvisioned $event): void
    {
        $tenantId = $event->tenant->getKey();

        Account::query()->create([
            'tenant_id' => $tenantId,
            'name' => 'صندوق',
            'type' => Account::TYPE_CASH,
            'is_default' => true,
            'is_active' => true,
        ]);

        // Receiving stock has to debit something. Without this the purchase entry would
        // net against the supplier and record nothing at all.
        Account::query()->create([
            'tenant_id' => $tenantId,
            'name' => 'ارزش موجودی انبار',
            'type' => Account::TYPE_INVENTORY,
            'is_default' => false,
            'is_active' => true,
        ]);

        // The credit side of every sale. Without it the ledger's balancing rule rejects
        // the entry, so a shop could not sell anything on its first day.
        Account::query()->create([
            'tenant_id' => $tenantId,
            'name' => 'درآمد فروش',
            'type' => Account::TYPE_SALES,
            'is_default' => false,
            'is_active' => true,
        ]);
    }
}
