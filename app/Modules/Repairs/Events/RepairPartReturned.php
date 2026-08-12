<?php

declare(strict_types=1);

namespace App\Modules\Repairs\Events;

use App\Modules\Repairs\Models\TicketPart;
use Illuminate\Foundation\Events\Dispatchable;

/** A held part went back to being sellable; nothing ever moved. */
final class RepairPartReturned
{
    use Dispatchable;

    public function __construct(public readonly TicketPart $part) {}
}
