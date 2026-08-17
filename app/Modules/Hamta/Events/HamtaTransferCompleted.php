<?php

declare(strict_types=1);

namespace App\Modules\Hamta\Events;

use App\Modules\Inventory\Models\ProductUnit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Somebody recorded that a transfer completed.
 *
 * **Recorded, not verified.** There is no API to verify against, so a listener must not
 * treat this as confirmation from همتا — it is confirmation from a member of staff.
 */
final class HamtaTransferCompleted
{
    use Dispatchable;

    public function __construct(
        public readonly ProductUnit $unit,
        public readonly ?string $activationId,
    ) {}
}
