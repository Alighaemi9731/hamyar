<?php

declare(strict_types=1);

namespace App\Modules\Platform\Events;

use App\Modules\Platform\Models\Subscription;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A subscription became usable — first payment, renewal, or recovery from past_due.
 *
 * The signal for anything that must react to a shop regaining access, such as clearing
 * the cached feature set.
 */
final class SubscriptionActivated
{
    use Dispatchable;

    public function __construct(public readonly Subscription $subscription) {}
}
