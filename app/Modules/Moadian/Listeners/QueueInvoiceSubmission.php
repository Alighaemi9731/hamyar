<?php

declare(strict_types=1);

namespace App\Modules\Moadian\Listeners;

use App\Modules\Moadian\Jobs\SubmitInvoiceJob;
use App\Modules\Moadian\Services\SubmitInvoice;
use App\Modules\Sales\Events\InvoiceFinalised;

/**
 * A sale became real, so the authority should hear about it.
 *
 * ## It does nothing at all for a shop that has not opted in
 *
 * `SubmitInvoice::enqueue()` returns null when either switch is off, and no row is written.
 * That matters more than it looks: `MOADIAN_ENABLED` is false for every plan at launch
 * ([ADR 0011](../../../../docs/adr/0011-moadian-adapter-without-a-provider.md)), so this
 * listener runs on every finalisation in every shop and must be a no-op in all of them —
 * no pending rows accumulating for a feature nobody bought, no errors surfaced, nothing.
 *
 * ## The queue is the point
 *
 * Dispatching a job rather than submitting inline is what keeps a government API's bad
 * afternoon out of the till. The spec makes it an acceptance criterion: a failed submission
 * must never block invoice finalisation.
 */
final class QueueInvoiceSubmission
{
    public function __construct(private readonly SubmitInvoice $submitter) {}

    public function handle(InvoiceFinalised $event): void
    {
        $submission = $this->submitter->enqueue($event->invoice);

        if ($submission === null) {
            return;
        }

        SubmitInvoiceJob::dispatch(idOfModel($submission));
    }
}
