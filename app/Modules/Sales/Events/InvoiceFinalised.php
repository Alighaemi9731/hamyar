<?php

declare(strict_types=1);

namespace App\Modules\Sales\Events;

use App\Modules\Sales\Models\SalesInvoice;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A sale became real: numbered, stock moved, money accounted for.
 *
 * The cross-module boundary (ADR 0003). Messaging sends the thank-you SMS, Installments
 * builds a schedule when one was requested, CRM accrues loyalty points, Reporting
 * counts the day — none of which Sales knows about.
 *
 * Dispatched **after** the finalisation transaction commits, never inside it: a listener
 * that sends an SMS from inside the transaction texts the customer about a sale that
 * may still roll back.
 */
final class InvoiceFinalised
{
    use Dispatchable;

    public function __construct(public readonly SalesInvoice $invoice) {}
}
