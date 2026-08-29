<?php

declare(strict_types=1);

namespace App\Support\Quota\Events;

use App\Support\Quota\QuotaVerdict;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A shop was actually stopped.
 *
 * The most commercially interesting event in the product: it is the moment a plan stops
 * being enough, and the metric that fires it most often is the one that sells upgrades.
 *
 * Dispatched from the exception renderer, **after** the refused transaction has fully
 * unwound — never `afterCommit` from inside it, because Laravel discards those callbacks
 * on rollback and the whole funnel signal would quietly not exist.
 */
final class LimitReached
{
    use Dispatchable;

    public function __construct(
        public readonly int $tenantId,
        public readonly QuotaVerdict $verdict,
        public readonly ?int $userId = null,
    ) {}
}
