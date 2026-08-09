<?php

declare(strict_types=1);

namespace App\Modules\CRM\Listeners;

use App\Modules\CRM\Models\Account;
use App\Modules\Platform\Events\TenantProvisioned;

/**
 * Give a new shop a cash drawer.
 *
 * Every sale needs somewhere to post the money side, so the account has to exist before
 * the first transaction rather than after someone discovers a settings screen. One cash
 * account is what a single-branch shop needs and all most of them will ever use.
 */
final class CreateDefaultAccount
{
    public function handle(TenantProvisioned $event): void
    {
        Account::query()->create([
            'tenant_id' => $event->tenant->getKey(),
            'name' => 'صندوق',
            'type' => Account::TYPE_CASH,
            'is_default' => true,
            'is_active' => true,
        ]);
    }
}
