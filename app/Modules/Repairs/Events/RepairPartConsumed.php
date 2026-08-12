<?php

declare(strict_types=1);

namespace App\Modules\Repairs\Events;

use App\Modules\Repairs\Models\TicketPart;
use Illuminate\Foundation\Events\Dispatchable;

/** A part was fitted to a device; stock left the shelf. */
final class RepairPartConsumed
{
    use Dispatchable;

    public function __construct(public readonly TicketPart $part) {}
}
