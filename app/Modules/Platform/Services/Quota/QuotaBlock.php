<?php

declare(strict_types=1);

namespace App\Modules\Platform\Services\Quota;

use App\Modules\Platform\Models\Plan;
use App\Modules\Platform\Models\Subscription;
use App\Modules\Platform\Services\ProrationCalculator;
use App\Modules\Platform\Services\SubscriptionResolver;
use App\Support\Digits;
use App\Support\Jalali;
use App\Support\Money;
use App\Support\Quota\MetricRegistry;
use App\Support\Quota\QuotaVerdict;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * What a blocked shopkeeper is shown, and the one click out of it.
 *
 * The screen this feeds is the whole commercial argument of the plan model: a person is
 * mid-task with a customer in front of them, and the product has just said no. Two
 * sentences and a button decide whether that is an upgrade or a support ticket.
 *
 * So the copy names three things and nothing else: **what ran out**, **what the next plan
 * gives**, and **when the credit refills**. The price on the button comes from
 * `ProrationCalculator` — the same arithmetic that writes the invoice (ADR 0006) — because
 * a figure quoted here that disagrees with what the gateway charges is worse than no
 * figure at all.
 *
 * When the shop is already on the top rung, or the user cannot buy, the button is replaced
 * rather than disabled: a dead button reads as a bug, and «از مدیر فروشگاه بخواهید» is
 * information the operator can act on.
 */
final class QuotaBlock
{
    public function __construct(
        private readonly MetricRegistry $registry,
        private readonly SubscriptionResolver $subscriptions,
        private readonly ProrationCalculator $proration,
    ) {}

    /**
     * @return array{
     *     metric: string,
     *     label: string,
     *     message: string,
     *     used: int,
     *     limit: int|null,
     *     requested: int,
     *     resets_at: string|null,
     *     next_plan: array{code: string, name: string, limit: int|null, price: array{value: int, formatted: string}, due: array{value: int, formatted: string}}|null,
     *     can_upgrade: bool,
     * }
     */
    public function for(QuotaVerdict $verdict, ?Authenticatable $user = null): array
    {
        $metric = $this->registry->get($verdict->metric);
        $nextPlan = $this->nextPlan($verdict);
        $canUpgrade = $user !== null && method_exists($user, 'can') && $user->can('billing.manage');

        return [
            'metric' => $verdict->metric,
            'label' => $metric->labelFa,
            'message' => $this->message($verdict, $nextPlan),
            'used' => $verdict->used,
            'limit' => $verdict->limit,
            'requested' => $verdict->requested,
            'resets_at' => $verdict->resetsAt?->toIso8601String(),
            'next_plan' => $nextPlan === null ? null : [
                'code' => $nextPlan->code,
                'name' => $nextPlan->name_fa,
                'limit' => $nextPlan->limit($verdict->metric),
                'price' => Money::toArray($nextPlan->price),
                'due' => Money::toArray($this->amountDue($nextPlan)),
            ],
            'can_upgrade' => $canUpgrade,
        ];
    }

    /**
     * One sentence, in the order a person needs it: what stopped, what would not, when
     * this one comes back.
     */
    private function message(QuotaVerdict $verdict, ?Plan $nextPlan): string
    {
        $metric = $this->registry->get($verdict->metric);
        $limit = Digits::toPersian(number_format((int) $verdict->limit));
        $planName = $this->currentPlanName();

        // A bulk refusal is a different sentence: "you asked for forty and have twelve
        // left" is actionable, "your credit is full" is not — the operator is holding a
        // spreadsheet and needs to know how much of it will fit.
        /*
        | A standing capacity has no month, and saying it does was a promise we could not
        | keep.
        |
        | Every sentence here used to be phrased monthly regardless of window, so a shop
        | blocked on seats read «سهمیهٔ ۲ کاربر *این ماه* … تمام شد. پلن نامحدود *ماهی* ۲۵
        | کاربر دارد.» — telling somebody to wait for a refill that never comes, on the one
        | metric where waiting is exactly the wrong advice. `resets_at` was already null for
        | these, so the card said "this month" and then declined to name the date; the copy
        | and the payload disagreed, and the copy was the half a person reads.
        |
        | `Window::labelFa()` and `PeriodClock::label()` have always distinguished the two
        | («ظرفیت» versus «در ماه»); this is the sentence catching up with them.
        */
        $counted = $metric->window->isCounted();

        if ($verdict->requested > 1) {
            $remaining = Digits::toPersian((string) ($verdict->remaining() ?? 0));
            $requested = Digits::toPersian((string) $verdict->requested);

            $sentence = $counted
                ? "این عملیات {$requested} {$metric->unitFa} می‌خواهد و سهمیهٔ باقی‌ماندهٔ شما {$remaining} است."
                : "این عملیات {$requested} {$metric->unitFa} می‌خواهد و ظرفیت آزاد شما {$remaining} است.";
        } else {
            $sentence = $counted
                ? "سهمیهٔ {$limit} {$metric->unitFa} این ماه در پلن {$planName} تمام شد."
                : "ظرفیت {$limit} {$metric->unitFa} پلن {$planName} تکمیل است.";
        }

        if ($nextPlan instanceof Plan) {
            $nextLimit = $nextPlan->limit($verdict->metric);
            $nextText = $nextLimit === null
                ? 'نامحدود'
                : Digits::toPersian(number_format($nextLimit));

            $sentence .= $counted
                ? " پلن {$nextPlan->name_fa} ماهی {$nextText} {$metric->unitFa} دارد."
                : " پلن {$nextPlan->name_fa} ظرفیت {$nextText} {$metric->unitFa} دارد.";
        }

        if (! $counted) {
            // The actionable half, and the reason this branch exists: a capacity is freed
            // by removing something, not by waiting. Without this the shop is left with a
            // refusal and no move it can make today.
            $sentence .= " با آزاد کردن یکی از {$metric->unitFa}‌های موجود هم جا باز می‌شود.";
        }

        if ($verdict->resetsAt !== null) {
            // A date, not a countdown: a month is too long for «فردا» to be the honest
            // answer, and «۹ روز دیگر» is a number nobody can plan around.
            $sentence .= ' سهمیهٔ پلن فعلی '.Jalali::format($verdict->resetsAt, 'j F').' تازه می‌شود.';
        }

        return $sentence;
    }

    private function nextPlan(QuotaVerdict $verdict): ?Plan
    {
        if ($verdict->nextPlanCode === null) {
            return null;
        }

        return Plan::query()->with('limits')->where('code', $verdict->nextPlanCode)->first();
    }

    /**
     * What upgrading costs today — from the calculator that writes the invoice, never
     * recomputed here (ADR 0006).
     */
    private function amountDue(Plan $plan): int
    {
        $subscription = $this->subscriptions->current();

        if (! $subscription instanceof Subscription) {
            return $plan->price;
        }

        return $this->proration->preview($subscription, $plan)['amount_due'];
    }

    private function currentPlanName(): string
    {
        return $this->subscriptions->current()?->plan->name_fa ?? 'فعلی';
    }
}
