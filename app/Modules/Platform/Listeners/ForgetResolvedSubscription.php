<?php

declare(strict_types=1);

namespace App\Modules\Platform\Listeners;

use App\Modules\Platform\Events\SubscriptionActivated;
use App\Modules\Platform\Services\SubscriptionResolver;

/**
 * Drop the memoised subscription when a shop's access changes.
 *
 * {@see SubscriptionActivated} has said in its own docblock since Phase 2 that it exists
 * as "the signal for anything that must react to a shop regaining access, such as
 * clearing the cached feature set" — and nothing listened. `SubscriptionResolver` is a
 * singleton memoising one subscription per tenant id, so within the request that settles
 * a payment, and within any long-lived queue worker that has already served this shop,
 * every later `grants()` and `limit()` answered from the plan the shop had *before* it
 * paid. The upgrade was real in the database and invisible to the process that made it.
 *
 * Synchronous on purpose: the whole point is that the rest of *this* request sees the new
 * plan, and a queued listener would clear a memo in a different process.
 */
final class ForgetResolvedSubscription
{
    public function __construct(private readonly SubscriptionResolver $resolver) {}

    public function handle(SubscriptionActivated $event): void
    {
        unset($event);

        $this->resolver->forget();
    }
}
