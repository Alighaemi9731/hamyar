<?php

declare(strict_types=1);

namespace App\Modules\Sales\Contracts;

use App\Modules\Sales\Models\SalesInvoice;

/**
 * Is there settlement paper against this invoice that must be dealt with first?
 *
 * ## The problem this exists to stop
 *
 * `VoidInvoice` reverses the batches it recognises — those referencing a `SalesInvoice`.
 * A cheque posts its own batch against a `Cheque`, so voiding an invoice that a cheque
 * settled used to credit the customer the full amount and leave the cheque asset standing:
 * the shop owing a customer whose paper was still in its drawer.
 *
 * The fix is a refusal rather than a wider reversal, deliberately. Unwinding the cheque
 * automatically would silently undo an endorsement, or a deposit already sitting at a
 * bank, and the shop would find out from the supplier. The paper is a physical object
 * somebody has to hand back, and the operator is told to do that first — exactly as
 * `VoidInvoice` already refuses an invoice with returns against it.
 *
 * Declared by Sales so Sales does not depend on Cheques; Cheques binds it.
 */
interface InvoiceSettlementGuard
{
    /**
     * A Persian sentence naming what is in the way, or null when nothing is.
     */
    public function blockingReason(SalesInvoice $invoice): ?string;
}
