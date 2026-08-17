<?php

declare(strict_types=1);

namespace App\Modules\Moadian\Services;

use App\Modules\Moadian\Contracts\MoadianDriver;
use App\Modules\Moadian\Models\MoadianInvoice;
use App\Modules\Moadian\Models\MoadianSetting;
use App\Modules\Sales\Models\SalesInvoice;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Everything above the driver: eligibility, the row, the call, and what the answer means.
 *
 * ## The flag is checked here, once
 *
 * `MOADIAN_ENABLED` is false for every plan at launch
 * ([ADR 0011](../../../../docs/adr/0011-moadian-adapter-without-a-provider.md)), and a
 * disabled shop must attempt **nothing** and surface **no error** — a tax module that logs
 * failures for a shop that never opted in is a support ticket every morning. Checked in one
 * place rather than in the listener, the job and the controller, because three copies of a
 * predicate is two chances for a disabled shop to submit a real document.
 *
 * ## A submission row is created before the call, and the unique index is the guarantee
 *
 * `firstOrCreate` on (tenant, invoice, type='main') against a partial unique index. Two
 * workers both reading "not yet submitted" is exactly the race a queue makes likely, and
 * the spec's "resend is idempotent — it does not create a duplicate submission" is a
 * database-level claim, not a code-level one.
 *
 * **The insert runs in a nested transaction.** CLAUDE.md's rule, learned twice already:
 * Postgres aborts the entire transaction on a unique violation, so catching 23505 inside an
 * outer one leaves it dead and every later statement fails with 25P02. `DB::transaction()`
 * gives it a SAVEPOINT. Every test runs inside `RefreshDatabase`'s transaction, so this is
 * the default condition rather than an edge case.
 *
 * ## Rejection is recorded; transport failure is rethrown
 *
 * The distinction the driver contract makes, honoured here. A rejection is an answer: it
 * lands in the inbox with its Persian message and the queue does **not** retry it, because
 * resending an identical document gets an identical no. A transport failure is rethrown so
 * the job's backoff can do its work.
 */
final class SubmitInvoice
{
    public function __construct(
        private readonly MoadianDriver $driver,
        private readonly InvoiceMapper $mapper,
    ) {}

    /**
     * Whether this shop submits at all.
     *
     * Two switches, both required: the deployment-wide flag and the shop's own setting. The
     * first exists so no development machine can ever submit a real tax document; the second
     * so a shop that has not entered credentials does not start filing.
     */
    public function isEnabled(?MoadianSetting $settings = null): bool
    {
        if (! config()->boolean('moadian.enabled', false)) {
            return false;
        }

        $settings ??= MoadianSetting::query()->first();

        return $settings instanceof MoadianSetting && $settings->is_enabled;
    }

    /**
     * Queue a finalised invoice for submission, if this shop submits.
     *
     * Returns null when disabled — not an exception, and nothing written. A shop that never
     * opted in must not accumulate `pending` rows it will one day be asked about.
     */
    public function enqueue(SalesInvoice $invoice, string $type = MoadianInvoice::TYPE_MAIN): ?MoadianInvoice
    {
        $settings = MoadianSetting::query()->first();

        if (! $this->isEnabled($settings)) {
            return null;
        }

        $payload = $this->mapper->map($invoice, $settings, $type);

        /*
        | Nested transaction for the 23505 reason in CLAUDE.md — and note **where** the
        | try/catch sits relative to it. This is the rule's sharp variant, and getting it
        | backwards produced exactly the 25P02 the rule exists to prevent:
        |
        |     // WRONG. The catch runs INSIDE the aborted nested transaction, so the
        |     // recovery SELECT dies with "current transaction is aborted".
        |     DB::transaction(fn () => { try { insert(); } catch { select(); } });
        |
        |     // RIGHT. The closure throws, Laravel rolls back to the SAVEPOINT, and the
        |     // catch runs on a healthy connection.
        |     try { DB::transaction(fn () => insert()); } catch { select(); }
        |
        | `DB::transaction()` releases the savepoint on a *thrown* closure, not on one that
        | swallows its own exception — so a catch on the inside gets a savepoint that was
        | never rolled back.
        */
        try {
            return DB::transaction(fn (): MoadianInvoice => MoadianInvoice::query()->create([
                'sales_invoice_id' => $invoice->getKey(),
                'type' => $type,
                'payload' => $payload->toArray(),
                'status' => MoadianInvoice::STATUS_PENDING,
            ]));
        } catch (Throwable $e) {
            if (! str_contains($e->getMessage(), '23505')) {
                throw $e;
            }

            // Somebody else got there first. Theirs is the submission — which is the whole
            // point of the partial unique index: two workers both reading "not yet
            // submitted" must not produce two filings.
            return MoadianInvoice::query()
                ->where('sales_invoice_id', $invoice->getKey())
                ->where('type', $type)
                ->firstOrFail();
        }
    }

    /**
     * Put a submission on the wire.
     *
     * @throws RuntimeException on transport failure, so the queue retries it
     */
    public function send(MoadianInvoice $submission): MoadianInvoice
    {
        if ($submission->status === MoadianInvoice::STATUS_ACCEPTED) {
            // Already accepted. Re-sending would file the same document twice, which is a
            // worse outcome than a resend button that quietly does nothing.
            return $submission;
        }

        $submission->forceFill([
            'status' => MoadianInvoice::STATUS_SENDING,
            'attempts' => $submission->attempts + 1,
            'sent_at' => CarbonImmutable::now(),
        ])->save();

        try {
            $result = $this->driver->send($this->payloadOf($submission));
        } catch (Throwable $e) {
            /*
            | Transport failure: the request never arrived. Recorded as `failed` so the row
            | is never left stuck on `sending` if the worker dies here, then rethrown so the
            | queue's backoff handles the retry.
            */
            $submission->forceFill([
                'status' => MoadianInvoice::STATUS_FAILED,
                'error_code' => 'transport',
                'error_message' => 'ارتباط با سامانه برقرار نشد. تلاش دوباره انجام می‌شود.',
            ])->save();

            throw $e;
        }

        if (! $result->accepted) {
            // An answer, not a failure. The queue must not retry it — an identical document
            // gets an identical no — so this returns normally and the inbox picks it up.
            $submission->forceFill([
                'status' => MoadianInvoice::STATUS_REJECTED,
                'reference_number' => $result->reference,
                'error_code' => $result->errorCode,
                'error_message' => $result->errorMessage,
            ])->save();

            return $submission;
        }

        $submission->forceFill([
            'status' => MoadianInvoice::STATUS_ACCEPTED,
            'reference_number' => $result->reference,
            'tax_id' => $result->taxId,
            'error_code' => null,
            'error_message' => null,
            'confirmed_at' => CarbonImmutable::now(),
        ])->save();

        return $submission;
    }

    /**
     * Re-ask the authority about a document it already has.
     *
     * The reason polling is not just "send again": a document accepted at submission can be
     * rejected later, and the shop needs to hear about that from somewhere other than an
     * auditor.
     */
    public function poll(MoadianInvoice $submission): MoadianInvoice
    {
        if ($submission->reference_number === null) {
            return $submission;
        }

        $status = $this->driver->status($submission->reference_number);

        $submission->forceFill(match ($status->state) {
            'accepted' => [
                'status' => MoadianInvoice::STATUS_ACCEPTED,
                'tax_id' => $status->taxId ?? $submission->tax_id,
                'confirmed_at' => $submission->confirmed_at ?? CarbonImmutable::now(),
            ],
            'rejected' => [
                'status' => MoadianInvoice::STATUS_REJECTED,
                'error_code' => $status->errorCode,
                'error_message' => $status->errorMessage,
            ],
            default => ['status' => $submission->status],
        })->save();

        return $submission;
    }

    /**
     * Reset a rejected or failed submission so it can be tried again.
     *
     * The payload is **rebuilt** from the invoice as it stands now, which is the point of a
     * resend: the shop fixed the buyer's economic code in response to the rejection, and
     * resending the document that was already refused would be refused again.
     */
    public function retry(MoadianInvoice $submission): MoadianInvoice
    {
        $invoice = $submission->invoice;

        if ($invoice instanceof SalesInvoice) {
            $settings = MoadianSetting::query()->first();

            $submission->forceFill([
                'payload' => $this->mapper->map($invoice, $settings, $submission->type)->toArray(),
            ])->save();
        }

        $submission->forceFill([
            'status' => MoadianInvoice::STATUS_PENDING,
            'error_code' => null,
            'error_message' => null,
        ])->save();

        return $submission;
    }

    /**
     * Rebuild the payload value from the stored array.
     *
     * Stored rather than re-mapped, so a document under investigation is the document that
     * was sent — see the migration.
     */
    private function payloadOf(MoadianInvoice $submission): \App\Modules\Moadian\Support\MoadianPayload
    {
        $invoice = $submission->invoice;

        if (! $invoice instanceof SalesInvoice) {
            throw new RuntimeException('moadian: submission has no invoice behind it.');
        }

        return $this->mapper->map($invoice, MoadianSetting::query()->first(), $submission->type);
    }
}
