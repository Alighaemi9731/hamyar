<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Drivers;

use App\Modules\Messaging\Contracts\SmsDriver;
use App\Modules\Messaging\Support\SmsPayload;
use App\Modules\Messaging\Support\SmsResult;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Kavenegar — the gateway most Iranian shops already have an account with.
 *
 * ## The `verify/lookup` endpoint, not `sms/send`
 *
 * Pattern sends go through `verify/lookup`, which takes a template name and up to ten
 * positional tokens. It is the only path that reaches numbers registered on the national
 * do-not-disturb list, which most people are — a shop whose «دستگاه شما آماده است» is
 * silently dropped by the carrier will conclude the software is broken, and be right to.
 *
 * The API's tokens are `token`, `token2`, `token3`… — note there is no `token1`. Getting
 * that wrong shifts every value one place, so the customer's name arrives where the amount
 * should be. The mapping happens once, here, and `KavenegarDriverTest` pins it.
 *
 * ## Kavenegar tokens may not contain spaces
 *
 * A documented restriction that surprises everybody: a token with a space is rejected by
 * the API, not truncated. Persian text arrives full of them — «حسن رضایی» is one token with
 * one space — so spaces become ZWNJ-adjacent underscores, which render acceptably in the
 * delivered message. Ugly, and less ugly than a message that never arrives.
 *
 * ## A rejection is returned, never thrown
 *
 * The caller has already deducted credit and must refund it. An exception through a queued
 * job would unwind past the refund and leave the wallet short.
 */
final class KavenegarDriver implements SmsDriver
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $baseUrl = 'https://api.kavenegar.com/v1',
        private readonly int $timeout = 15,
    ) {}

    public function send(SmsPayload $payload): SmsResult
    {
        try {
            $response = Http::timeout($this->timeout)
                ->asForm()
                ->get("{$this->baseUrl}/{$this->apiKey}/verify/lookup.json", [
                    // Kavenegar wants the local form without a plus.
                    'receptor' => $this->receptor($payload->to),
                    'template' => $payload->templateId,
                    ...$this->tokens($payload->tokens),
                ]);
        } catch (ConnectionException $exception) {
            // The gateway is unreachable. An outcome, not a bug: the caller refunds.
            return SmsResult::rejected('اتصال به سامانه پیامک برقرار نشد.');
        }

        /** @var array<string, mixed> $body */
        $body = is_array($response->json()) ? $response->json() : [];

        /** @var array<string, mixed> $return */
        $return = is_array($body['return'] ?? null) ? $body['return'] : [];

        $status = is_numeric($return['status'] ?? null) ? (int) $return['status'] : 0;

        if ($status !== 200) {
            $message = is_string($return['message'] ?? null)
                ? $return['message']
                : 'پاسخ نامعتبر از سامانه پیامک';

            // Logged without the API key or the recipient: a support ticket needs the
            // reason, not somebody's phone number.
            Log::warning('Kavenegar rejected a message', ['status' => $status, 'message' => $message]);

            return SmsResult::rejected($message);
        }

        /** @var list<mixed> $entries */
        $entries = is_array($body['entries'] ?? null) ? array_values($body['entries']) : [];

        /** @var array<string, mixed> $entry */
        $entry = is_array($entries[0] ?? null) ? $entries[0] : [];

        $reference = $entry['messageid'] ?? null;

        return SmsResult::accepted(
            reference: is_scalar($reference) ? (string) $reference : 'unknown',
            // Kavenegar's pattern endpoint bills one part per send regardless of length;
            // the multi-segment case belongs to free-text sends, which this driver does
            // not do.
            segments: 1,
        );
    }

    public function name(): string
    {
        return 'kavenegar';
    }

    /**
     * `+989121234567` → `09121234567`.
     */
    private function receptor(string $canonical): string
    {
        return str_starts_with($canonical, '+98') ? '0'.substr($canonical, 3) : $canonical;
    }

    /**
     * Positional tokens, in Kavenegar's naming: `token`, `token2`, `token3`, …
     *
     * There is no `token1`, which is the detail that shifts every value by one if it is
     * assumed. Ten is the API's maximum; anything beyond is dropped rather than silently
     * corrupting the message, and the template manager refuses to save a pattern needing
     * more.
     *
     * @param  list<string>  $tokens
     * @return array<string, string>
     */
    private function tokens(array $tokens): array
    {
        $mapped = [];

        foreach (array_slice($tokens, 0, 10) as $index => $token) {
            $key = $index === 0 ? 'token' : 'token'.($index + 1);
            $mapped[$key] = $this->sanitise($token);
        }

        return $mapped;
    }

    /**
     * Kavenegar rejects a token containing a space — outright, not truncated.
     */
    private function sanitise(string $token): string
    {
        return trim(preg_replace('/\s+/u', '_', $token) ?? $token);
    }
}
