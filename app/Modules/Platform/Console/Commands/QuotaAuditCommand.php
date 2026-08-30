<?php

declare(strict_types=1);

namespace App\Modules\Platform\Console\Commands;

use App\Modules\Platform\Models\Plan;
use App\Support\Quota\MetricRegistry;
use Illuminate\Console\Command;

/**
 * What is metered but not priced, and what is priced but not metered.
 *
 * Two silences this product cannot afford, and neither shows up as an error:
 *
 * - **A metric with no `plan_limits` row** is unlimited on that plan. That leniency is
 *   deliberate — it means a module can ship a metered action without a data migration —
 *   but it also means a forgotten row is a quota that never fires, and the only symptom is
 *   revenue that never arrives. This lists them.
 * - **A `plan_limits` row for a key nobody registered** is a number the panel shows, the
 *   pricing page may advertise, and nothing on earth enforces. Usually the residue of a
 *   renamed metric.
 *
 * Also asserts the fallback plan exists, because `LimitResolver` throws without it and
 * the first thing to notice would otherwise be every write in the application failing.
 *
 * `@platform-wide` — it reads the central catalogue and enters no tenant.
 */
final class QuotaAuditCommand extends Command
{
    protected $signature = 'quota:audit';

    protected $description = 'Report metrics with no limit row, limit rows with no metric, and a missing fallback plan';

    public function handle(MetricRegistry $registry): int
    {
        $keys = $registry->keys();
        $problems = 0;

        $fallback = config()->string('hamyar.quota.fallback_plan');

        if (! Plan::query()->where('code', $fallback)->exists()) {
            $this->error("Fallback plan [{$fallback}] does not exist. Every shop without a usable subscription depends on it.");
            $problems++;
        }

        /** @var list<Plan> $plans */
        $plans = Plan::query()->with('limits')->orderBy('position')->get()->all();

        foreach ($plans as $plan) {
            /** @var list<string> $priced */
            $priced = $plan->limits->pluck('key')->all();

            /** @var list<string> $unpriced */
            $unpriced = array_values(array_diff($keys, $priced));
            /** @var list<string> $unknown */
            $unknown = array_values(array_diff($priced, $keys));

            if ($unpriced !== []) {
                $this->warn("Plan [{$plan->code}] has no limit for: ".implode(', ', $unpriced));
                $this->line('  → unlimited on this plan until somebody types a number.');
                $problems++;
            }

            if ($unknown !== []) {
                $this->warn("Plan [{$plan->code}] prices keys no module registers: ".implode(', ', $unknown));
                $this->line('  → shown in the panel, enforced by nothing.');
                $problems++;
            }
        }

        if ($problems === 0) {
            $this->info(sprintf('Quota audit: %d metrics, every plan priced, fallback [%s] present.', count($keys), $fallback));

            return self::SUCCESS;
        }

        // A non-zero exit so this can be a scheduled check rather than a thing somebody
        // remembers to run.
        return self::FAILURE;
    }
}
