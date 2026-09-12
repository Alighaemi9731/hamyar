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
    /**
     * One slice colour per rung, cycled rather than indexed.
     *
     * It was a bare three-item array, which is the same bug as a hardcoded plan count:
     * the catalogue became four rungs on 2026-09-12 and Chart.js drew the fourth slice
     * with no fill at all — an invisible wedge on the one chart that exists to show how
     * the ladder is selling. The modulo means the next rung is grey-on-grey at worst,
     * never absent.
     */
    private const SLICE_COLOURS = ['#0066cc', '#0f7b3f', '#8a5a00', '#6a3fa0'];

    protected ?string $heading = 'توزیع پلن‌ها';

    protected function getType(): string
    {
        return 'doughnut';
    }

    protected function getData(): array
    {
        $plans = Plan::query()->orderBy('position')->get()->values();

        $counts = $plans->map(fn (Plan $plan): int => Subscription::query()
            ->where('plan_id', $plan->getKey())
            ->whereIn('status', [Subscription::STATUS_ACTIVE, Subscription::STATUS_TRIALING])
            ->count());

        $colours = $plans->map(
            fn (Plan $plan, int $index): string => self::SLICE_COLOURS[$index % count(self::SLICE_COLOURS)]
        );

        return [
            'datasets' => [[
                'label' => 'اشتراک‌ها',
                'data' => $counts->values()->all(),
                'backgroundColor' => $colours->values()->all(),
            ]],
            'labels' => $plans->pluck('name_fa')->values()->all(),
        ];
    }
}
