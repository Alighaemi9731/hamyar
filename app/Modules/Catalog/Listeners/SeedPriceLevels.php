<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Listeners;

use App\Modules\Catalog\Models\PriceLevel;
use App\Modules\Platform\Events\TenantProvisioned;

/**
 * Give a new shop the three price levels Iranian phone retail actually runs on.
 *
 * مصرف‌کننده / همکار / همکار ویژه are not an enterprise feature here — reseller pricing
 * is an everyday concept in this market, so the levels exist from the first day rather
 * than waiting for someone to discover a settings screen.
 */
final class SeedPriceLevels
{
    public function handle(TenantProvisioned $event): void
    {
        foreach (PriceLevel::defaults() as $level) {
            PriceLevel::query()->create([
                'tenant_id' => $event->tenant->getKey(),
                ...$level,
            ]);
        }
    }
}
