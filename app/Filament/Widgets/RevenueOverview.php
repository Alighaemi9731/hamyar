<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Modules\Platform\Models\Subscription;
use App\Modules\Platform\Models\SubscriptionInvoice;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * The four numbers that say whether the business is working.
 *
 * MRR is computed from what shops are actually subscribed to right now, not from summed
 * invoices: invoices include prorated upgrades and part-periods, so summing them answers
 * "what did we collect" rather than "what recurs". Both are useful; this widget is about
 * the second, and conflating them is the classic SaaS reporting error.
 *
 * Trials are excluded — they are worth zero until they convert, and counting them
 * flatters MRR by exactly the amount you most want to be honest about.
 */
final class RevenueOverview extends StatsOverviewWidget
{
    protected ?string $heading = 'وضعیت درآمد';

    protected function getStats(): array
    {
        $now = CarbonImmutable::now();

        $mrr = (int) Subscription::query()
            ->join('plans', 'plans.id', '=', 'subscriptions.plan_id')
            ->where('subscriptions.status', Subscription::STATUS_ACTIVE)
            ->where(function ($query) use ($now): void {
                $query->whereNull('subscriptions.current_period_end')
                    ->orWhere('subscriptions.current_period_end', '>', $now);
            })
            ->sum('plans.price');

        $activeShops = Subscription::query()
            ->where('status', Subscription::STATUS_ACTIVE)
            ->distinct()
            ->count('tenant_id');

        $trialing = Subscription::query()
            ->where('status', Subscription::STATUS_TRIALING)
            ->where('trial_ends_at', '>', $now)
            ->count();

        // Money actually collected this month, which is what the bank will agree with.
        $collected = (int) SubscriptionInvoice::query()
            ->where('status', SubscriptionInvoice::STATUS_PAID)
            ->where('paid_at', '>=', $now->startOfMonth())
            ->sum('total');

        $pastDue = Subscription::query()
            ->where('status', Subscription::STATUS_PAST_DUE)
            ->count();

        return [
            Stat::make('درآمد ماهانه تکرارشونده', Money::formatWithUnit($mrr))
                ->description("{$activeShops} فروشگاه فعال")
                ->color('success'),

            Stat::make('دریافتی این ماه', Money::formatWithUnit($collected))
                ->description('بر اساس صورتحساب‌های پرداخت‌شده'),

            Stat::make('دوره آزمایشی', (string) $trialing)
                ->description('در انتظار تبدیل')
                ->color('info'),

            Stat::make('پرداخت معوق', (string) $pastDue)
                ->description($pastDue > 0 ? 'نیازمند پیگیری' : 'موردی نیست')
                ->color($pastDue > 0 ? 'warning' : 'gray'),
        ];
    }
}
