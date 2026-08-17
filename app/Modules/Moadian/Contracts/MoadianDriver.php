<?php

declare(strict_types=1);

namespace App\Modules\Moadian\Contracts;

use App\Modules\Moadian\Support\MoadianPayload;
use App\Modules\Moadian\Support\SubmissionResult;
use App\Modules\Moadian\Support\SubmissionStatus;
use RuntimeException;

/**
 * One way of getting an e-invoice to سامانه مودیان.
 *
 * ## There is exactly one implementation, and it is a fake — deliberately
 *
 * [ADR 0011](../../../../docs/adr/0011-moadian-adapter-without-a-provider.md), the Gate 4
 * ruling: no real intermediary provider ships for launch. The customers this launches to
 * are mostly on presumptive taxation and will not file electronically, and choosing a
 * provider before one has asked means buying an integration that the first real request is
 * likely to contradict.
 *
 * So this interface is designed against the **specification and a fake**, never against one
 * vendor's API. That is the difference between a boundary and a vendor's client wearing an
 * interface: when the real driver is built, everything above this line — the queue, the
 * inbox, the mapping, the idempotent resend — is already decided and tested.
 *
 * ## Drivers are transports. They decide nothing.
 *
 * No feature-flag check, no idempotency, no retry policy, no deciding whether an invoice is
 * eligible. All of that lives above, in one place, so it cannot be true in one driver and
 * false in another — the same rule `SmsDriver` states, for the same reason.
 *
 * ## Rejection is a return value; transport failure is an exception
 *
 * A rejection means the authority received the document and said no: it carries a code and
 * a Persian message, it belongs in the error inbox, and retrying it unchanged will fail
 * identically. A transport failure means the request never arrived, and that is the only
 * case worth the queue's backoff. Implementations MUST NOT throw for a rejection.
 */
interface MoadianDriver
{
    /**
     * Submit a document.
     *
     * @throws RuntimeException only for transport failure — the request did not arrive.
     */
    public function send(MoadianPayload $payload): SubmissionResult;

    /**
     * Ask what the authority currently thinks of a document already submitted.
     */
    public function status(string $reference): SubmissionStatus;

    /**
     * Withdraw a submitted document — what a void becomes once the original was accepted.
     */
    public function cancel(string $reference, string $reason): SubmissionResult;

    /** For logs, the submission rows and the settings screen. */
    public function name(): string;
}
