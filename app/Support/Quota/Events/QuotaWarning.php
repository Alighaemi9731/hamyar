<?php

declare(strict_types=1);

namespace App\Support\Quota\Events;

use App\Support\Quota\QuotaVerdict;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A shop just crossed the warning line on one of its credits.
 *
 * Dispatched after commit, once per metric per period — a shop that crosses 80 % and then
 * does ninety more things must not be told ninety times. The uniqueness is enforced by an
 * index on `usage_events`, not by remembering in PHP, because two workers can cross the
 * line in the same second.
 *
 * Lives in the shared kernel so Messaging can listen without importing Platform.
 */
final class QuotaWarning
{
    use Dispatchable;

    public function __construct(
        public readonly int $tenantId,
        public readonly QuotaVerdict $verdict,
    ) {}
}
