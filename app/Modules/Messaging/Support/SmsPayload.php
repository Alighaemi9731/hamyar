<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Support;

/**
 * Everything a driver needs to send one message, and nothing it needs to decide.
 *
 * `tokens` is an ORDERED list, not a map. Iranian pattern APIs are positional — token 1
 * goes wherever `%token1` appears in the approved template — so the order here is the
 * order on the wire, and a driver that sorts or re-keys it sends a customer's name where
 * the amount belongs.
 */
final readonly class SmsPayload
{
    /**
     * @param  string  $to  canonical +98 form; normalised before it reaches a driver
     * @param  list<string>  $tokens  positional, in template order
     */
    public function __construct(
        public string $to,
        public string $templateId,
        public array $tokens,
        public ?string $body = null,
        public ?int $messageId = null,
    ) {}
}
