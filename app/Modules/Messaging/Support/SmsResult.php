<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Support;

/**
 * What the gateway said.
 *
 * A rejection is an outcome, not an exception: the caller has already deducted credit and
 * must refund it, and an exception thrown through a queued job would leave the wallet short
 * while the retry stack unwound. So drivers return this, always.
 */
final readonly class SmsResult
{
    private function __construct(
        public bool $accepted,
        public ?string $reference,
        public ?string $error,
        public int $segments,
    ) {}

    /**
     * @param  int  $segments  how many SMS parts the gateway counted — what the shop is charged for
     */
    public static function accepted(string $reference, int $segments = 1): self
    {
        return new self(true, $reference, null, max(1, $segments));
    }

    public static function rejected(string $error): self
    {
        return new self(false, null, $error, 0);
    }
}
