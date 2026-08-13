<?php

declare(strict_types=1);

namespace App\Modules\Repairs\Console;

use App\Modules\Platform\Models\Tenant;
use App\Modules\Repairs\Services\AbandonedSweep;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;

/**
 * The scheduled nudge for uncollected devices.
 *
 * Runs per tenant inside that shop's context, so RLS confines every query and the
 * shop's own escalation ladder is the one that applies.
 *
 * Safe to run by hand, at any time, as many times as you like — which is the point of
 * the idempotency work in {@see AbandonedSweep}. Somebody WILL run this to see what it
 * does, and it must not text a hundred customers because they were curious.
 */
final class SweepAbandonedDevices extends Command
{
    protected $signature = 'repairs:sweep-abandoned {--tenant= : Limit to one shop by slug}';

    protected $description = 'Nudge customers about uncollected repairs, and flag رسوبی devices.';

    public function handle(TenantContext $context, AbandonedSweep $sweep): int
    {
        $tenants = Tenant::query()
            ->when($this->option('tenant'), fn ($query) => $query->where('slug', $this->option('tenant')))
            ->get();

        $totals = ['escalated' => 0, 'abandoned' => 0, 'considered' => 0];

        foreach ($tenants as $tenant) {
            /** @var array{escalated: int, abandoned: int, considered: int} $result */
            $result = $context->runFor($tenant, fn (): array => $sweep->run());

            foreach ($totals as $key => $value) {
                $totals[$key] = $value + $result[$key];
            }

            $this->line(sprintf(
                '  %-24s considered %3d · nudged %3d · رسوبی %3d',
                $tenant->slug,
                $result['considered'],
                $result['escalated'],
                $result['abandoned'],
            ));
        }

        $this->info(sprintf(
            'Swept %d shop(s): %d considered, %d nudged, %d marked رسوبی.',
            $tenants->count(),
            $totals['considered'],
            $totals['escalated'],
            $totals['abandoned'],
        ));

        return self::SUCCESS;
    }
}
