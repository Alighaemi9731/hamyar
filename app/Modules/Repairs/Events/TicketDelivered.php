<?php

declare(strict_types=1);

namespace App\Modules\Repairs\Events;

use App\Modules\Repairs\Models\RepairTicket;
use App\Modules\Sales\Models\SalesInvoice;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * The device went home, and the bill was settled.
 *
 * Carries the invoice as well as the ticket: Phase 8 texts the customer a thank-you with
 * the warranty period, and the CRM timeline wants both the repair and the sale on one
 * line rather than two entries a second apart.
 */
final class TicketDelivered
{
    use Dispatchable;

    public function __construct(
        public readonly RepairTicket $ticket,
        public readonly SalesInvoice $invoice,
    ) {}
}
