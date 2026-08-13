<?php

declare(strict_types=1);

namespace App\Modules\Sales\Contracts;

use App\Modules\Sales\Models\SalesInvoice;

/**
 * The answer in a deployment with no cheque module: nothing is in the way.
 */
final class NothingBlocksSettlement implements InvoiceSettlementGuard
{
    public function blockingReason(SalesInvoice $invoice): ?string
    {
        return null;
    }
}
