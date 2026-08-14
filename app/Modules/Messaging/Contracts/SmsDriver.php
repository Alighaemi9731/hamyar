<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Contracts;

use App\Modules\Messaging\Support\SmsPayload;
use App\Modules\Messaging\Support\SmsResult;

/**
 * One way of getting a text message to a phone.
 *
 * ## Pattern sends, not free text
 *
 * Iranian gateways distinguish a *pattern* (الگو) — a template pre-approved by the
 * regulator, filled with ordered tokens — from bulk free text. Transactional messages must
 * go as patterns: they are cheaper, they are delivered to numbers on the national
 * do-not-disturb list, and free-text sends to those numbers are silently dropped by the
 * carrier. A shop whose «دستگاه شما آماده است» is silently dropped concludes the software
 * is broken.
 *
 * So the payload carries a template id and an ordered token list, and the driver's job is
 * to put them on the wire in whatever shape its gateway wants. **Token ORDER is part of the
 * contract**: Kavenegar's pattern API takes positional tokens, so a driver that reorders
 * them sends the customer's name where the amount belongs.
 *
 * ## Drivers never decide whether to send
 *
 * No opt-out check, no credit check, no idempotency. A driver is a transport. Every policy
 * decision lives above it, in one place, so it cannot be true in one driver and false in
 * another — and so the fake driver in tests exercises the same decisions the real one would.
 */
interface SmsDriver
{
    /**
     * Put one message on the wire.
     *
     * Implementations must not throw for a gateway rejection — that is an outcome, not an
     * exception, and the caller needs it to refund credit. Throw only for a genuine
     * programming error.
     */
    public function send(SmsPayload $payload): SmsResult;

    /** For logs, message rows and the driver picker. */
    public function name(): string;
}
