<?php

declare(strict_types=1);

namespace App\Modules\Moadian\Support;

/**
 * What came back from an attempt to submit.
 *
 * ## Three outcomes, and only one of them is an exception
 *
 * **Accepted** and **rejected** are both *answers*: the authority received the document and
 * said yes or no. A rejection carries a code and a Persian message and belongs in the error
 * inbox, not in a stack trace.
 *
 * **Transport failure** is the third, and it is the one worth retrying — the request never
 * arrived. A driver signals it by throwing, so the queue's backoff handles it; returning a
 * "failed" result instead would make every caller re-implement the difference between "they
 * said no" and "we could not ask", and one of them would get it wrong.
 */
final readonly class SubmissionResult
{
    private function __construct(
        public bool $accepted,
        public ?string $reference,
        public ?string $taxId,
        public ?string $errorCode,
        public ?string $errorMessage,
    ) {}

    public static function accepted(string $reference, ?string $taxId = null): self
    {
        return new self(true, $reference, $taxId, null, null);
    }

    /**
     * @param  string  $message  Persian, and written for a shop owner. The error inbox shows
     *                           it verbatim — a shop that cannot read the reason cannot fix
     *                           the invoice, and the spec calls silent failure the worst
     *                           possible outcome.
     */
    public static function rejected(string $code, string $message, ?string $reference = null): self
    {
        return new self(false, $reference, null, $code, $message);
    }
}
