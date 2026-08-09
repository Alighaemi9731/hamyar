<?php

declare(strict_types=1);

namespace App\Modules\Platform\Jobs;

use App\Modules\Platform\Events\SubscriptionRenewalDue;
use App\Modules\Platform\Models\Subscription;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Warn shops whose subscription renews soon, and those already past due.
 *
 * Runs daily. It only *emits events* — Messaging decides whether that becomes an SMS or
 * an email, which keeps the module boundary intact (golden rule 6) and means a broken
 * SMS provider cannot stop the billing schedule from running.
 *
 * Reads across every tenant, so the whole query runs inside `runAsPlatform()`. Sending
 * happens per-tenant inside `runFor()` so any listener that touches shop data — a
 * customer's mobile number, the shop's own name — sees the right tenant.
 */
final class SendRenewalReminders implements ShouldQueue
{
    use Queueable;

    /**
     * Days before renewal on which a reminder goes out.
     *
     * Three points, not one: a week out is enough time to top up a card, three days is
     * the nudge that actually gets acted on, and the day itself catches everyone who
     * ignored the first two. More than this is spam, and a shop that mutes us stops
     * seeing the messages that matter.
     *
     * @var list<int>
     */
    public const REMIND_DAYS_BEFORE = [7, 3, 1];

    public function handle(TenantContext $context): void
    {
        $now = CarbonImmutable::now();

        $due = $context->runAsPlatform(fn () => Subscription::query()
            ->with('tenant')
            ->whereIn('status', [Subscription::STATUS_ACTIVE, Subscription::STATUS_TRIALING, Subscription::STATUS_PAST_DUE])
            ->whereNotNull('current_period_end')
            // Widest window we could care about, so the database does the filtering
            // rather than hydrating every subscription on the platform.
            ->whereBetween('current_period_end', [
                $now->startOfDay(),
                $now->addDays(max(self::REMIND_DAYS_BEFORE))->endOfDay(),
            ])
            ->get());

        foreach ($due as $subscription) {
            $end = $subscription->current_period_end;

            if ($end === null) {
                continue;
            }

            // Whole days, floored, so a subscription ending in 6h50m counts as "today"
            // rather than rounding up to tomorrow and missing its last reminder.
            $daysLeft = (int) $now->startOfDay()->diffInDays($end->startOfDay(), absolute: false);

            if (! in_array($daysLeft, self::REMIND_DAYS_BEFORE, true)) {
                continue;
            }

            $tenant = $subscription->tenant;

            if ($tenant === null) {
                continue;
            }

            $context->runFor($tenant, function () use ($subscription, $daysLeft): void {
                event(new SubscriptionRenewalDue($subscription, $daysLeft));
            });
        }
    }
}
