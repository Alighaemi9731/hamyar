<?php

declare(strict_types=1);

namespace App\Modules\Platform\Events;

use App\Modules\Platform\Models\SubscriptionInvoice;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Money arrived for an invoice.
 *
 * Cross-module listeners hang off this rather than off BillingService (golden rule 6):
 * Messaging sends the receipt SMS, Reporting updates MRR.
 */
final class SubscriptionInvoicePaid
{
    use Dispatchable;

    public function __construct(public readonly SubscriptionInvoice $invoice) {}
}
