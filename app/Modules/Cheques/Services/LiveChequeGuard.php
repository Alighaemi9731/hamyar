<?php

declare(strict_types=1);

namespace App\Modules\Cheques\Services;

use App\Modules\Cheques\Enums\ChequeDirection;
use App\Modules\Cheques\Enums\ChequeStatus;
use App\Modules\Cheques\Models\Cheque;
use App\Modules\Sales\Contracts\InvoiceSettlementGuard;
use App\Modules\Sales\Models\SalesInvoice;

/**
 * Cheques' answer to "may this invoice be voided?".
 *
 * A cheque is in the way whenever it is still doing something: sitting in the drawer, at a
 * bank, endorsed to a supplier, or bounced and being chased. A cleared, returned, written
 * off or cancelled cheque is not — it has already been dealt with, and the void can
 * proceed.
 */
final class LiveChequeGuard implements InvoiceSettlementGuard
{
    public function blockingReason(SalesInvoice $invoice): ?string
    {
        $live = Cheque::query()
            ->where('direction', ChequeDirection::Received->value)
            ->where('reference_type', SalesInvoice::class)
            ->where('reference_id', $invoice->getKey())
            ->whereIn('status', ChequeStatus::outstandingForExposure())
            ->first();

        if (! $live instanceof Cheque) {
            return null;
        }

        // Names the cheque and the action, because "cannot void" with no next step is how
        // an operator ends up deleting rows in a database client.
        return "چک {$live->serial} بانک {$live->bank_name} هنوز باز است — نخست آن را مسترد یا تعیین تکلیف کنید، سپس فاکتور را ابطال کنید.";
    }
}
