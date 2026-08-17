<?php

declare(strict_types=1);

namespace App\Modules\Moadian\Listeners;

use App\Modules\Moadian\Jobs\SubmitInvoiceJob;
use App\Modules\Moadian\Models\MoadianInvoice;
use App\Modules\Moadian\Services\SubmitInvoice;
use App\Modules\Sales\Events\InvoiceVoided;

/**
 * A numbered tax document was voided, so its cancellation has to be filed too.
 *
 * ## Only if the original was actually accepted
 *
 * Cancelling a document the authority never received would be filing a correction to
 * nothing. An invoice voided before its submission was accepted simply has its pending
 * submission left alone — there is nothing out there to withdraw.
 */
final class QueueInvoiceCancellation
{
    public function __construct(private readonly SubmitInvoice $submitter) {}

    public function handle(InvoiceVoided $event): void
    {
        $original = MoadianInvoice::query()
            ->where('sales_invoice_id', $event->invoice->getKey())
            ->where('type', MoadianInvoice::TYPE_MAIN)
            ->first();

        if (! $original instanceof MoadianInvoice || $original->status !== MoadianInvoice::STATUS_ACCEPTED) {
            return;
        }

        $submission = $this->submitter->enqueue($event->invoice, MoadianInvoice::TYPE_CANCEL);

        if ($submission === null) {
            return;
        }

        SubmitInvoiceJob::dispatch(idOfModel($submission));
    }
}
