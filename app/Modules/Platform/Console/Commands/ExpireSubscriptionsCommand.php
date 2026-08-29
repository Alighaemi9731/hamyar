<?php

declare(strict_types=1);

namespace App\Modules\Platform\Console\Commands;

use App\Modules\Platform\Models\Subscription;
use App\Modules\Platform\Services\Quota\LimitResolver;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Move subscriptions through the states nothing has ever written.
 *
 * `past_due`, `grace_ends_at` and `canceled` have been modelled since Phase 2 and no code
 * has ever set any of them. Two things followed, both invisible until somebody looked:
 *
 * - **There was no grace period.** `Subscription::isUsable()` reads `grace_ends_at`, and a
 *   column nobody writes is a feature nobody has. An `active` row simply stopped being
 *   usable the second its period ended, which is the opposite of what the docblock
 *   promises about Iranian gateways having transient outages.
 * - **MRR counted lapsed shops for ever.** `RevenueOverview` sums `plans.price` over
 *   `status = active`, and nothing ever left `active`. Every shop that ever paid was still
 *   revenue, months after it stopped paying.
 *
 * Phase 12 makes this urgent rather than untidy: quotas resolve from "the usable
 * subscription, else the fallback plan", so a shop that stopped paying keeps its paid
 * limits until something writes the state change.
 *
 * `@platform-wide` — it walks every shop deliberately and enters none of them; the writes
 * are on platform-owned tables and go through `runAsPlatform()`.
 */
final class ExpireSubscriptionsCommand extends Command
{
    protected $signature = 'subscriptions:expire {--days=3 : days of grace after a period ends}';

    protected $description = 'Move ended subscriptions to past_due, then to canceled after grace';

    public function handle(TenantContext $context, LimitResolver $limits): int
    {
        $now = CarbonImmutable::now();
        $graceDays = max(0, (int) $this->option('days'));

        $expired = $context->runAsPlatform(fn (): int => $this->toPastDue($now, $graceDays));
        $canceled = $context->runAsPlatform(fn (): int => $this->toCanceled($now));

        // The version bump is the point of doing this in one place: a shop whose state
        // changed here has different limits from this moment, and a worker holding a memo
        // would keep answering with the old ones.
        foreach ([...$this->touched] as $tenantId) {
            $limits->bump($tenantId);
        }

        $this->info("Expired {$expired}, canceled {$canceled}.");

        return self::SUCCESS;
    }

    /** @var list<int> */
    private array $touched = [];

    /**
     * A paid period that has ended enters grace rather than stopping dead.
     */
    private function toPastDue(CarbonImmutable $now, int $graceDays): int
    {
        /** @var list<Subscription> $due */
        $due = Subscription::query()
            ->where('status', Subscription::STATUS_ACTIVE)
            ->whereNotNull('current_period_end')
            ->where('current_period_end', '<=', $now)
            ->get()
            ->all();

        foreach ($due as $subscription) {
            // A free plan has no period to end. Guarding here rather than in the query
            // keeps the check next to the reason: a zero-price subscription is not late,
            // it is simply free, and marking it past_due would ask a shop to pay nothing.
            if ($subscription->plan->price === 0) {
                continue;
            }

            $subscription->update([
                'status' => Subscription::STATUS_PAST_DUE,
                'grace_ends_at' => $now->addDays($graceDays),
            ]);

            $this->touched[] = $subscription->tenant_id;
        }

        return count($this->touched);
    }

    /**
     * Grace ran out, or a trial-era row finally ended. The shop is not locked out — it
     * falls to the fallback plan's limits and keeps working (DECISION GATE 6, item 4).
     */
    private function toCanceled(CarbonImmutable $now): int
    {
        /** @var list<Subscription> $lapsed */
        $lapsed = Subscription::query()
            ->whereIn('status', [Subscription::STATUS_PAST_DUE, Subscription::STATUS_TRIALING])
            ->where(function ($query) use ($now): void {
                $query->where('grace_ends_at', '<=', $now)
                    ->orWhere(function ($inner) use ($now): void {
                        $inner->where('status', Subscription::STATUS_TRIALING)
                            ->whereNotNull('trial_ends_at')
                            ->where('trial_ends_at', '<=', $now);
                    });
            })
            ->get()
            ->all();

        foreach ($lapsed as $subscription) {
            $subscription->update([
                'status' => Subscription::STATUS_CANCELED,
                'canceled_at' => $subscription->canceled_at ?? $now,
            ]);

            $this->touched[] = $subscription->tenant_id;
        }

        return count($lapsed);
    }
}
