<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Listeners;

use App\Modules\Messaging\Enums\AutomationKey;
use App\Modules\Messaging\Services\Automations;
use App\Modules\Sales\Events\InvoiceFinalised;
use App\Support\Money;

/**
 * A sale was finalised, so send the customer their invoice number.
 *
 * Hangs off `Sales\Events\InvoiceFinalised` — dispatched by Phase 5's `FinaliseInvoice`
 * after commit, so nothing here texts about an invoice that rolled back.
 *
 * A walk-in with no party gets nothing, which `Automations` handles by finding no number.
 * Most sales in a phone shop are exactly that, so this automation is quieter than it looks.
 */
final class SendInvoiceIssuedSms
{
    public function __construct(private readonly Automations $automations) {}

    public function handle(InvoiceFinalised $event): void
    {
        $invoice = $event->invoice;

        $this->automations->fire(
            AutomationKey::InvoiceIssued,
            $invoice->party,
            [
                'name' => $invoice->party?->name,
                'invoice_number' => $invoice->number,
                // Rendered as the shop displays money, so the customer reads the same
                // figure that is printed on the paper in their hand.
                'amount' => Money::format($invoice->total),
                'shop' => config()->string('app.name'),
            ],
            // An invoice is finalised once. The key guards a retried listener, not a
            // legitimate second event.
            idempotencyKey: "invoice-issued:{$invoice->id}",
            reference: $invoice,
            branchId: $invoice->branch_id,
        );
    }
}
