<?php

declare(strict_types=1);

namespace App\Modules\Platform\Events;

use App\Modules\Platform\Models\Subscription;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A subscription renews in `$daysLeft` days.
 *
 * Messaging listens and decides the channel. Emitted inside the tenant's context, so a
 * listener can read shop settings without arranging that itself.
 */
final class SubscriptionRenewalDue
{
    use Dispatchable;

    public function __construct(
        public readonly Subscription $subscription,
        public readonly int $daysLeft,
    ) {}
}
