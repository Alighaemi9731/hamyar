<?php

declare(strict_types=1);

namespace App\Modules\Hamta\Listeners;

use App\Modules\Hamta\Services\HamtaRegistry;
use App\Modules\Inventory\Events\UnitAcquired;

/**
 * A used handset arrived — bought over the counter, taken in trade, or received on a
 * purchase invoice — and the registry still has somebody else's name against that IMEI.
 *
 * One listener for all three routes in, because they are the same obligation. `UnitAcquired`
 * is dispatched wherever a `ProductUnit` is created (Inventory owns the event; Purchasing
 * and Sales dispatch it), so a fourth acquisition path added later is covered without
 * anybody remembering this module exists.
 */
final class FlagAcquiredUsedDevice
{
    public function __construct(private readonly HamtaRegistry $registry) {}

    public function handle(UnitAcquired $event): void
    {
        $this->registry->markPending($event->unit, 'acquired');
    }
}
