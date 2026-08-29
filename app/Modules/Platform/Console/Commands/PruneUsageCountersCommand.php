<?php

declare(strict_types=1);

namespace App\Modules\Platform\Console\Commands;

use App\Modules\Platform\Models\UsageCounter;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Delete counter rows for periods nobody will look at again.
 *
 * ~24 rows per shop per month. At five thousand shops that is 1.4M rows a year — not
 * alarming, and precisely the size at which nobody notices it growing until an index does
 * not fit in memory. Scheduled from the day the table exists, because every unscheduled
 * sweep in this repo's history was discovered by its absence rather than its output.
 *
 * `usage_events` is deliberately NOT pruned: at most four rows per metric per shop per
 * period, and it is the only record of which limit sells upgrades. Losing a year of that
 * to save a few megabytes would be the worst trade in the product.
 *
 * The cutoff is a plain string comparison, which is safe only because every period key is
 * a fixed-width Gregorian date. A mixed-format key column — Jalali for months, Gregorian
 * for days — would make `'1405-06' < '2025-01-01'` true and delete every month row on the
 * first run. That is one of the reasons the key format is what it is.
 */
final class PruneUsageCountersCommand extends Command
{
    protected $signature = 'quota:prune {--days=400 : keep periods that began within this many days}';

    protected $description = 'Delete usage counters for long-past periods';

    public function handle(TenantContext $context): int
    {
        $days = max(31, (int) $this->option('days'));
        $cutoff = CarbonImmutable::now()->subDays($days)->toDateString();

        /** @var int $deleted */
        $deleted = $context->runAsPlatform(
            static function () use ($cutoff): int {
                // quota-scope-allow: the sweep is about periods, not shops, and deletes
                // across every tenant on purpose.
                /** @var int $rows */
                $rows = UsageCounter::query()->where('period_key', '<', $cutoff)->delete();

                return $rows;
            }
        );

        cache()->forever('quota.pruned_at', CarbonImmutable::now()->toIso8601String());

        $this->info("Pruned {$deleted} usage counter rows older than {$cutoff}.");

        return self::SUCCESS;
    }
}
