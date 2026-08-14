<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Drivers;

use App\Modules\Messaging\Contracts\SmsDriver;
use App\Modules\Messaging\Support\SmsPayload;
use App\Modules\Messaging\Support\SmsResult;
use PHPUnit\Framework\Assert;

/**
 * The driver the whole test suite sends through, and the one the DoD is walked against.
 *
 * ## It records the payload, not "a message was sent"
 *
 * A fake that only counts sends proves the code called *something*. The bugs that actually
 * reach a customer are in the payload: the wrong pattern id, tokens in the wrong order, a
 * number that never got normalised. So every assertion here is about exact content, and
 * `assertSent()` compares the whole payload rather than a substring.
 *
 * Token order is asserted as a list, deliberately. Iranian pattern APIs are positional, and
 * the failure mode — the customer's name where the amount belongs — is invisible to any
 * test that checks membership instead of sequence.
 *
 * ## Failure is scriptable, because the refund path needs exercising
 *
 * `failNext()` makes the gateway reject the next send. The credit wallet must give the
 * money back when that happens, and a refund path with no test is a wallet that quietly
 * drains on every carrier outage.
 */
final class FakeSmsDriver implements SmsDriver
{
    /** @var list<SmsPayload> */
    private array $sent = [];

    private ?string $failWith = null;

    private int $segments = 1;

    public function send(SmsPayload $payload): SmsResult
    {
        $this->sent[] = $payload;

        if ($this->failWith !== null) {
            $error = $this->failWith;
            $this->failWith = null;

            return SmsResult::rejected($error);
        }

        return SmsResult::accepted('fake-'.count($this->sent), $this->segments);
    }

    public function name(): string
    {
        return 'fake';
    }

    /** The next send is rejected by the gateway. */
    public function failNext(string $error = 'gateway unavailable'): void
    {
        $this->failWith = $error;
    }

    /** Pretend the gateway counted this many parts — a long Persian body is several. */
    public function countSegments(int $segments): void
    {
        $this->segments = max(1, $segments);
    }

    /**
     * @return list<SmsPayload>
     */
    public function sent(): array
    {
        return $this->sent;
    }

    public function reset(): void
    {
        $this->sent = [];
        $this->failWith = null;
        $this->segments = 1;
    }

    /**
     * Assert a message went out with exactly this content.
     *
     * @param  list<string>  $tokens  in template order — sequence is asserted, not membership
     */
    public function assertSent(string $to, string $templateId, array $tokens): void
    {
        foreach ($this->sent as $payload) {
            if ($payload->to === $to && $payload->templateId === $templateId && $payload->tokens === $tokens) {
                // Counts toward the test's assertion tally, so a test whose only claim is
                // "this went out" is not reported as risky.
                Assert::assertSame($templateId, $payload->templateId);

                return;
            }
        }

        Assert::fail(sprintf(
            "No SMS matching to=%s template=%s tokens=[%s].\nSent:\n%s",
            $to,
            $templateId,
            implode(', ', $tokens),
            $this->sent === []
                ? '  (nothing)'
                : implode("\n", array_map(
                    fn (SmsPayload $p): string => sprintf('  to=%s template=%s tokens=[%s]', $p->to, $p->templateId, implode(', ', $p->tokens)),
                    $this->sent,
                )),
        ));
    }

    public function assertNothingSentTo(string $to): void
    {
        $recipients = array_map(fn (SmsPayload $p): string => $p->to, $this->sent);

        Assert::assertNotContains($to, $recipients, "An SMS was sent to {$to}, which must not have happened.");
    }

    public function assertSentCount(int $expected): void
    {
        Assert::assertCount($expected, $this->sent);
    }
}
