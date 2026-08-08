<?php

declare(strict_types=1);

namespace App\Modules\Platform\Services\Payments;

use App\Modules\Platform\Models\PaymentAttempt;

/**
 * An in-memory gateway for tests and local development.
 *
 * Bound in place of {@see ZarinpalGateway} whenever `services.payments.driver` is
 * `fake`, which is the default in the test environment. The point is that every billing
 * test — invoice creation, idempotent verification, credit application — runs against
 * the same `BillingService` code path the real gateway uses, with only the two network
 * calls swapped out. A test suite that mocked `BillingService` itself would prove
 * nothing about the thing that actually handles money.
 */
final class FakeGateway implements PaymentGateway
{
    /** @var list<array{authority: string, amount: int, callback: string}> */
    public array $initiated = [];

    private bool $shouldSucceed = true;

    private ?string $failWith = null;

    private int $sequence = 0;

    public function name(): string
    {
        return 'fake';
    }

    /**
     * Make every subsequent verification report an unpaid transaction.
     */
    public function willFail(string $error = 'پرداخت ناموفق بود.'): self
    {
        $this->shouldSucceed = false;
        $this->failWith = $error;

        return $this;
    }

    public function willSucceed(): self
    {
        $this->shouldSucceed = true;
        $this->failWith = null;

        return $this;
    }

    public function initiate(PaymentAttempt $attempt, string $callbackUrl): GatewayRedirect
    {
        $authority = sprintf('FAKE%036d', ++$this->sequence);

        $this->initiated[] = [
            'authority' => $authority,
            'amount' => $attempt->amount,
            'callback' => $callbackUrl,
        ];

        return new GatewayRedirect(
            authority: $authority,
            url: "https://fake-gateway.test/pay/{$authority}",
        );
    }

    public function verify(PaymentAttempt $attempt, array $callback): GatewayVerification
    {
        if (($callback['Status'] ?? $callback['status'] ?? 'OK') !== 'OK') {
            return GatewayVerification::failed('پرداخت توسط کاربر لغو شد.', $callback);
        }

        if (! $this->shouldSucceed) {
            return GatewayVerification::failed($this->failWith ?? 'پرداخت ناموفق بود.', $callback);
        }

        return new GatewayVerification(
            paid: true,
            // Deterministic, so a test can assert the receipt shows the right number.
            reference: 'REF-'.$attempt->authority,
            amount: $attempt->amount,
            payload: ['fake' => true],
        );
    }
}
