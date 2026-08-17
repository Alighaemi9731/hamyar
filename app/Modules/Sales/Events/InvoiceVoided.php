<?php

declare(strict_types=1);

namespace App\Modules\Sales\Events;

use App\Modules\Sales\Models\SalesInvoice;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A numbered tax document was cancelled.
 *
 * The counterpart to {@see InvoiceFinalised}, and the cross-module boundary for undoing a
 * sale (ADR 0003). Moadian files a cancellation when the original was accepted; other
 * modules can listen without Sales knowing they exist.
 *
 * Dispatched **after** the void transaction commits, for the reason `InvoiceFinalised`
 * gives: a listener acting inside the transaction acts on a reversal that may still roll
 * back — and here that would mean telling a tax authority a document was cancelled when it
 * was not.
 */
final class InvoiceVoided
{
    use Dispatchable;

    public function __construct(
        public readonly SalesInvoice $invoice,
        public readonly string $reason,
    ) {}
}
