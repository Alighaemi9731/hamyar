<?php

declare(strict_types=1);

namespace App\Modules\Platform\Services\Payments;

/**
 * What the gateway said when asked whether the money moved.
 */
final readonly class GatewayVerification
{
    /**
     * @param  bool  $paid  true only when the gateway confirms settled funds. A timeout,
     *                      an unparseable response or any doubt must be false — treating
     *                      "unknown" as paid gives the product away.
     * @param  string|null  $reference  the gateway receipt number (Zarinpal's RefID),
     *                                  shown on the receipt and used for reconciliation
     * @param  int|null  $amount  the amount the gateway says was paid, in rial, when it
     *                            reports one; the caller checks it against the invoice
     * @param  array<string, mixed>  $payload  raw response, stored for support
     */
    public function __construct(
        public bool $paid,
        public ?string $reference = null,
        public ?int $amount = null,
        public ?string $error = null,
        public array $payload = [],
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function failed(string $error, array $payload = []): self
    {
        return new self(paid: false, error: $error, payload: $payload);
    }
}
