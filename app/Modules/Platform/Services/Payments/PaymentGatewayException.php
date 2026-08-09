<?php

declare(strict_types=1);

namespace App\Modules\Platform\Services\Payments;

use RuntimeException;

/**
 * The gateway could not be reached, or refused to start a payment.
 *
 * Distinct from a failed verification: this means we never got as far as sending the
 * customer anywhere, so no money can possibly have moved.
 */
final class PaymentGatewayException extends RuntimeException {}
