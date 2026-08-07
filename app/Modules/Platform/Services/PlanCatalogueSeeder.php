<?php

declare(strict_types=1);

namespace App\Modules\Platform\Services;

use App\Modules\Platform\Models\Module;
use App\Modules\Platform\Models\Plan;
use App\Modules\Platform\Models\PlanLimit;
use App\Modules\Platform\Support\PlanCatalogue;
use Illuminate\Support\Facades\DB;

/**
 * Sync the database catalogue to {@see PlanCatalogue}.
 *
 * Idempotent and safe to re-run on every deploy: modules and plans are matched by
 * `code`, so shipping a new module is a code change plus a run of
 * `platform:sync-catalogue`, not a migration.
 *
 * Prices are updated in place. That is intentional and worth knowing: changing a price
 * here changes what NEW subscriptions cost. Existing subscriptions are unaffected
 * because their invoices snapshot the price at purchase — a shop's bill never changes
 * under it because we edited a seed file.
 */
final class PlanCatalogueSeeder
{
    public function sync(): void
    {
        DB::transaction(function (): void {
            $this->syncModules();
            $this->syncPlans();
        });
    }

    private function syncModules(): void
    {
        foreach (PlanCatalogue::modules() as $position => $definition) {
            Module::query()->updateOrCreate(
                ['code' => $definition['code']],
                [
                    'name_fa' => $definition['name_fa'],
                    'is_core' => $definition['is_core'],
                    'is_addonable' => $definition['is_addonable'],
                    'addon_price' => $definition['addon_toman'] === null
                        ? null
                        : PlanCatalogue::rial($definition['addon_toman']),
                    'position' => $position,
                ]
            );
        }
    }

    private function syncPlans(): void
    {
        foreach (PlanCatalogue::plans() as $position => $definition) {
            /** @var Plan $plan */
            $plan = Plan::query()->updateOrCreate(
                ['code' => $definition['code']],
                [
                    'name_fa' => $definition['name_fa'],
                    'tagline_fa' => $definition['tagline_fa'],
                    'interval' => 'month',
                    'price' => PlanCatalogue::rial($definition['price_toman']),
                    'trial_days' => 14,
                    'is_public' => true,
                    'position' => $position,
                ]
            );

            $moduleIds = Module::query()
                ->whereIn('code', $definition['modules'])
                ->pluck('id')
                ->all();

            $plan->modules()->sync($moduleIds);

            foreach ($definition['limits'] as $key => $value) {
                PlanLimit::query()->updateOrCreate(
                    ['plan_id' => $plan->getKey(), 'key' => $key],
                    ['value' => $value]
                );
            }

            // A limit removed from the catalogue must disappear from the database too,
            // otherwise a stale row keeps enforcing a quota nobody can see any more.
            PlanLimit::query()
                ->where('plan_id', $plan->getKey())
                ->whereNotIn('key', array_keys($definition['limits']))
                ->delete();
        }
    }
}
