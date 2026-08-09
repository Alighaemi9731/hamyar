<?php

declare(strict_types=1);

namespace App\Modules\Platform\Services\Payments;

use App\Modules\Platform\Models\PaymentAttempt;
use Illuminate\Http\Client\Factory as Http;
use Throwable;

/**
 * Zarinpal, talked to over its REST API directly.
 *
 * `shetabit/multipay` is installed and is the right tool for juggling several PSPs, but
 * its drivers redirect and `die()` on their own, which makes them impossible to test and
 * impossible to wrap in our own attempt bookkeeping. Zarinpal's API is two endpoints, so
 * this speaks to them directly and keeps the redirect decision in our controller. When a
 * second PSP is added, that is when multipay earns its place — behind
 * {@see PaymentGateway}, which is why the interface exists.
 *
 * Amounts: Zarinpal's v4 API takes **rial**, which is what we store, so no conversion
 * happens here. That is worth stating because Zarinpal's older v3 API took toman, and
 * the difference is a factor of ten in the customer's favour or ours.
 */
final class ZarinpalGateway implements PaymentGateway
{
    /**
     * @param  bool  $sandbox  sandbox has its own host and accepts any card
     */
    public function __construct(
        private readonly Http $http,
        private readonly string $merchantId,
        private readonly bool $sandbox = true,
        private readonly int $timeoutSeconds = 20,
    ) {}

    public function name(): string
    {
        return 'zarinpal';
    }

    public function initiate(PaymentAttempt $attempt, string $callbackUrl): GatewayRedirect
    {
        try {
            $response = $this->http
                ->timeout($this->timeoutSeconds)
                ->acceptJson()
                ->post($this->endpoint('pg/v4/payment/request.json'), [
                    'merchant_id' => $this->merchantId,
                    'amount' => $attempt->amount,
                    'callback_url' => $callbackUrl,
                    'description' => "پرداخت صورتحساب {$attempt->invoice->number}",
                    'currency' => 'IRR',
                ]);
        } catch (Throwable $exception) {
            throw new PaymentGatewayException(
                'Zarinpal is unreachable: '.$exception->getMessage(), previous: $exception
            );
        }

        /** @var array{data?: array{code?: int, authority?: string}, errors?: mixed} $body */
        $body = $response->json() ?? [];

        $code = $body['data']['code'] ?? null;
        $authority = $body['data']['authority'] ?? null;

        // 100 = created. Anything else means no payment exists to send anyone to.
        if ($code !== 100 || ! is_string($authority) || $authority === '') {
            throw new PaymentGatewayException(
                'Zarinpal refused the payment request: '.json_encode($body['errors'] ?? $body, JSON_UNESCAPED_UNICODE)
            );
        }

        return new GatewayRedirect(
            authority: $authority,
            url: $this->endpoint("pg/StartPay/{$authority}"),
        );
    }

    public function verify(PaymentAttempt $attempt, array $callback): GatewayVerification
    {
        // Zarinpal signals an abandoned payment in the callback itself; asking it to
        // verify one is a wasted round trip that returns a confusing error code.
        if (($callback['Status'] ?? $callback['status'] ?? null) !== 'OK') {
            return GatewayVerification::failed('پرداخت توسط کاربر لغو شد.', $callback);
        }

        try {
            $response = $this->http
                ->timeout($this->timeoutSeconds)
                ->acceptJson()
                ->post($this->endpoint('pg/v4/payment/verify.json'), [
                    'merchant_id' => $this->merchantId,
                    'amount' => $attempt->amount,
                    'authority' => $attempt->authority,
                ]);
        } catch (Throwable $exception) {
            // Unknown, not failed. The caller leaves the attempt pending so a retry or
            // the reconciliation job can settle it — marking it failed here would strand
            // a payment the customer really made.
            throw new PaymentGatewayException(
                'Zarinpal verification did not complete: '.$exception->getMessage(), previous: $exception
            );
        }

        /** @var array{data?: array{code?: int, ref_id?: int|string}, errors?: mixed} $body */
        $body = $response->json() ?? [];

        $code = $body['data']['code'] ?? null;
        $reference = $body['data']['ref_id'] ?? null;

        // 100 = verified now. 101 = already verified — a replay, and still a success:
        // the money moved, we are simply being told a second time.
        if ($code === 100 || $code === 101) {
            return new GatewayVerification(
                paid: true,
                reference: $reference === null ? null : (string) $reference,
                amount: $attempt->amount,
                payload: $body,
            );
        }

        return GatewayVerification::failed(
            'تأیید پرداخت ناموفق بود (کد '.((string) ($code ?? 'نامشخص')).').',
            $body
        );
    }

    private function endpoint(string $path): string
    {
        $host = $this->sandbox ? 'https://sandbox.zarinpal.com' : 'https://payment.zarinpal.com';

        return "{$host}/{$path}";
    }
}
