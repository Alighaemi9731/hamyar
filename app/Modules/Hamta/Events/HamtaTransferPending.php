<?php

declare(strict_types=1);

namespace App\Modules\Hamta\Events;

use App\Modules\Inventory\Models\ProductUnit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A used device changed hands and owes a registry transfer.
 *
 * Dispatched on the way IN (the shop bought it) and on the way OUT (the shop sold it) —
 * they are the same obligation pointing at different people, and a listener that cares
 * about only one of them can read `$unit->status`.
 */
final class HamtaTransferPending
{
    use Dispatchable;

    public function __construct(
        public readonly ProductUnit $unit,
        /** `acquired` or `sold` — which side of the counter created the obligation. */
        public readonly string $reason,
    ) {}
}
