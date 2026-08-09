<?php

declare(strict_types=1);

namespace App\Modules\Platform\Services;

use App\Modules\Platform\Models\Module;
use App\Modules\Platform\Models\Plan;
use App\Modules\Platform\Models\PlanLimit;
use App\Modules\Platform\Support\PlanCatalogue;
use App\Modules\Platform\Support\TrialPolicy;
use Illuminate\Support\Facades\DB;

/**
 * Sync the database catalogue to {@see PlanCatalogue}.
 *
 * Idempotent and safe to re-run on every deploy: modules and plans are matched by
 * `code`, so shipping a new module is a code change plus a run of
 * `platform:sync-catalogue`, not a migration.
 *
 * ## What this does and does not overwrite
 *
 * Prices, limits, trial length and plan/module membership are **business data**. Gate 2
 * settled that they stay editable in the Filament panel, and a seeder that rewrote them
 * on every deploy would silently revert whatever the owner had just changed — the kind
 * of bug that surfaces weeks later as "why is the price wrong again".
 *
 * So the split is:
 *
 * - **Seeded once, on create.** Price, add-on price, limits, trial days, visibility,
 *   Persian copy, and which modules a plan includes. `PlanCatalogue` is the starting
 *   point for these, not the ongoing source of truth. After the row exists, Filament
 *   owns it.
 * - **Kept in sync on every run.** Only what the *code* determines: which module codes
 *   exist at all, whether a module is core or sellable as an add-on, and nav position.
 *   No amount of Filament editing can invent a module the application cannot serve.
 *
 * The consequence to know: changing a price in `PlanCatalogue` no longer changes any
 * existing environment. It sets the price for a fresh install. To change a live price,
 * edit it in the panel — which is the point.
 *
 * Existing subscriptions are unaffected either way, because invoices snapshot the price
 * at purchase; a shop's bill never moves under it.
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
            $module = Module::query()->firstOrNew(['code' => $definition['code']]);

            // Structural: the code decides these, always.
            $module->name_fa = $definition['name_fa'];
            $module->is_core = $definition['is_core'];
            $module->is_addonable = $definition['is_addonable'];
            $module->position = $position;

            // Business: seed the opening price, then leave it to the panel.
            if (! $module->exists) {
                $module->addon_price = $definition['addon_toman'] === null
                    ? null
                    : PlanCatalogue::rial($definition['addon_toman']);
            }

            $module->save();
        }
    }

    private function syncPlans(): void
    {
        foreach (PlanCatalogue::plans() as $position => $definition) {
            $plan = Plan::query()->firstOrNew(['code' => $definition['code']]);
            $isNew = ! $plan->exists;

            $plan->interval = 'month';
            $plan->position = $position;

            if ($isNew) {
                $plan->name_fa = $definition['name_fa'];
                $plan->tagline_fa = $definition['tagline_fa'];
                $plan->price = PlanCatalogue::rial($definition['price_toman']);
                $plan->trial_days = TrialPolicy::DAYS;
                $plan->is_public = true;
            }

            $plan->save();

            // Membership is business data: an owner may run a promotion that moves
            // تعمیرات into Basic. Re-syncing would undo it on the next deploy.
            if ($isNew) {
                $plan->modules()->sync(
                    Module::query()->whereIn('code', $definition['modules'])->pluck('id')->all()
                );

                foreach ($definition['limits'] as $key => $value) {
                    PlanLimit::query()->create([
                        'plan_id' => $plan->getKey(),
                        'key' => $key,
                        'value' => $value,
                    ]);
                }

                continue;
            }

            // On an existing plan, only add limit keys the code has newly introduced.
            // A key already present keeps whatever the panel set it to, and a key the
            // catalogue drops is left alone rather than deleted — removing a live quota
            // row silently un-caps every shop on the plan.
            foreach ($definition['limits'] as $key => $value) {
                PlanLimit::query()->firstOrCreate(
                    ['plan_id' => $plan->getKey(), 'key' => $key],
                    ['value' => $value]
                );
            }
        }
    }
}
