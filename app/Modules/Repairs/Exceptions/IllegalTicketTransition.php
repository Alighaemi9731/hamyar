<?php

declare(strict_types=1);

namespace App\Modules\Repairs\Exceptions;

use App\Modules\Repairs\Enums\TicketStatus;
use App\Modules\Repairs\Models\RepairTicket;
use RuntimeException;

/**
 * A move the bench does not allow.
 *
 * The message is Persian and names both ends, because this surfaces on a Kanban board
 * where somebody has just dragged a card and needs to know why it sprang back.
 */
final class IllegalTicketTransition extends RuntimeException
{
    public static function between(RepairTicket $ticket, TicketStatus $from, TicketStatus $to): self
    {
        return new self(
            "تیکت {$ticket->code} از «{$from->labelFa()}» به «{$to->labelFa()}» منتقل نمی‌شود."
        );
    }
}
