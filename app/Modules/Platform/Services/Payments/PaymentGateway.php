<?php

declare(strict_types=1);

namespace App\Modules\Platform\Services\Payments;

use App\Modules\Platform\Models\PaymentAttempt;

/**
 * The two things a payment gateway has to do.
 *
 * Kept this narrow deliberately. Zarinpal, Zibal, IDPay and Pay.ir all differ in their
 * payload shapes and error codes but agree on the shape of the interaction: send an
 * amount, get a handle plus somewhere to send the customer; later, present the handle
 * and be told whether money actually moved. Everything gateway-specific stays behind
 * these two methods, so switching processors — which Iranian merchants do, when a PSP's
 * uptime goes bad — is a new implementation and a config change.
 *
 * Implementations must not write to the database. Persisting the attempt, deciding
 * whether a callback is a replay, and applying the payment are the caller's job
 * ({@see \App\Modules\Platform\Services\BillingService}), because those are the parts
 * that must behave identically no matter who processes the card.
 */
interface PaymentGateway
{
    /**
     * Register a payment and get somewhere to send the customer.
     *
     * @param  string  $callbackUrl  absolute URL; built from `config('app.domain')`,
     *                               never a literal (golden rule 1b)
     *
     * @throws PaymentGatewayException when the gateway refuses or is unreachable
     */
    public function initiate(PaymentAttempt $attempt, string $callbackUrl): GatewayRedirect;

    /**
     * Ask the gateway whether this attempt was actually paid.
     *
     * Must be safe to call more than once for the same attempt: Iranian gateways
     * re-issue callbacks, and shops refresh the return page. Where the gateway itself
     * distinguishes "already verified" from "verified now", that maps to a successful
     * result carrying the original reference, not an error.
     *
     * @param  array<string, mixed>  $callback  query/POST parameters as received
     */
    public function verify(PaymentAttempt $attempt, array $callback): GatewayVerification;

    public function name(): string;
}
