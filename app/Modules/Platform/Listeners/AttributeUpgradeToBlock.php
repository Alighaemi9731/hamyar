<?php

declare(strict_types=1);

namespace App\Modules\Platform\Listeners;

use App\Modules\Platform\Events\SubscriptionActivated;
use App\Modules\Platform\Services\Quota\UsageEvents;

/**
 * Record that a shop upgraded shortly after being stopped by a credit.
 *
 * This is the answer to the only question the pricing actually depends on: **which limit
 * sells upgrades, and which one merely annoys people.** A shop blocked on `sales.invoices`
 * that pays within the week is a limit doing its job; one blocked on `reporting.exports`
 * that never comes back is a limit costing us a customer, and nothing else in the system
 * can tell those two apart.
 *
 * Attribution rather than a join: `subscription_invoices` knows what was bought and
 * nothing anywhere knows *why*. The link has to be written at the moment it can still be
 * inferred, which is here.
 *
 * Seven days, and no attempt to be cleverer than that. A shop that hits a wall on Thursday
 * and upgrades on Monday is the same story; one that upgrades a month later has had other
 * reasons.
 */
final class AttributeUpgradeToBlock
{
    public function __construct(private readonly UsageEvents $events) {}

    public function handle(SubscriptionActivated $event): void
    {
        $this->events->upgradedAfterBlock(
            $event->subscription->tenant_id,
            $event->subscription->plan->code,
        );
    }
}
