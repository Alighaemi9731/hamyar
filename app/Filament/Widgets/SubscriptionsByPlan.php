<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Modules\Platform\Models\Plan;
use App\Modules\Platform\Models\Subscription;
use Filament\Widgets\ChartWidget;

/**
 * Where the shops actually are.
 *
 * Counts live subscriptions per plan. The shape of this chart is the fastest read on
 * whether the pricing ladder works: everyone bunched on the cheapest plan means the
 * middle tier is not earning its price gap.
 */
final class SubscriptionsByPlan extends ChartWidget
{
    protected ?string $heading = 'توزیع پلن‌ها';

    protected function getType(): string
    {
        return 'doughnut';
    }

    protected function getData(): array
    {
        $plans = Plan::query()->orderBy('position')->get();

        $counts = $plans->map(fn (Plan $plan): int => Subscription::query()
            ->where('plan_id', $plan->getKey())
            ->whereIn('status', [Subscription::STATUS_ACTIVE, Subscription::STATUS_TRIALING])
            ->count());

        return [
            'datasets' => [[
                'label' => 'اشتراک‌ها',
                'data' => $counts->values()->all(),
                'backgroundColor' => ['#0066cc', '#0f7b3f', '#8a5a00'],
            ]],
            'labels' => $plans->pluck('name_fa')->values()->all(),
        ];
    }
}
