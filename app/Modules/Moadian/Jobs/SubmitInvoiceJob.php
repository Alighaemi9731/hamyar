<?php

declare(strict_types=1);

namespace App\Modules\Moadian\Jobs;

use App\Modules\Moadian\Models\MoadianInvoice;
use App\Modules\Moadian\Services\SubmitInvoice;
use App\Support\Tenancy\TenantAware;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * One e-invoice, submitted off the request thread.
 *
 * ## A failed submission must never block a sale
 *
 * The spec's acceptance line, and the reason this is queued at all: the authority's service
 * is genuinely unreliable, and a shop finalising an invoice at the counter cannot wait on
 * it — nor should the sale fail because a government API is having an afternoon. The
 * invoice is numbered, the money is accounted for, and the tax submission catches up.
 *
 * ## Backoff, and only for the failure worth retrying
 *
 * A **rejection** returns normally from `SubmitInvoice::send()` and lands in the error
 * inbox; retrying it would resend an identical document to get an identical no. Only a
 * **transport failure** throws, and that is what these attempts are for.
 *
 * Six minutes of spread across three tries. Longer than SMS, because nobody is standing at
 * a counter waiting for this and the service's outages are measured in minutes rather than
 * seconds.
 *
 * ## The tenant travels with the job
 *
 * {@see TenantAware}, for the reason `SendSmsJob` sets out at length: a worker processing
 * tenant A then tenant B with A's connection variable still set would read A's settings and
 * A's credentials under RLS. Here that would mean filing one shop's invoice under another
 * shop's economic code — and unlike a mis-sent SMS, that is a document a tax authority keeps.
 */
final class SubmitInvoiceJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;
    use TenantAware;

    public int $tries = 3;

    public function __construct(public readonly int $submissionId)
    {
        /*
        | Explicit, and it has to be. Laravel auto-runs `initialize*` trait hooks for
        | Eloquent models only — a job is a plain class, so nothing calls this for us.
        | Leaving it out does not fail quietly: `RestoreTenantContext` throws rather than
        | run the job with no tenant, because RLS would return nothing and the job would
        | "succeed" having silently filed nothing at all.
        */
        $this->initializeTenantAware();

        $this->onQueue(config()->string('moadian.queue', 'moadian'));
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function handle(SubmitInvoice $submitter): void
    {
        $submission = MoadianInvoice::query()->find($this->submissionId);

        if (! $submission instanceof MoadianInvoice) {
            // Deleted between dispatch and handling. Nothing to do, and nothing wrong.
            return;
        }

        $submitter->send($submission);
    }
}
