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
 * ## What this does and does not overwrite
 *
 * Prices and limits are **business data**. Gate 2 settled that they stay editable in the
 * Filament panel, and a seeder that rewrote them on every deploy would silently revert
 * whatever the owner had just changed — the kind of bug that surfaces weeks later as
 * "why is the price wrong again".
 *
 * So the split is:
 *
 * - **Seeded once, on create.** Price, the monthly limit matrix, visibility and Persian
 *   copy. `PlanCatalogue` is the starting point for these, not the ongoing source of
 *   truth. After the row exists, Filament owns it.
 * - **Kept in sync on every run.** Only what the *code* determines: which module codes
 *   exist at all, whether a module is core, and nav position. No amount of Filament
 *   editing can invent a module the application cannot serve.
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

            // Structural: the code decides these, always. No amount of panel editing can
            // invent a module the application cannot serve.
            $module->name_fa = $definition['name_fa'];
            $module->is_core = $definition['is_core'];
            $module->position = $position;

            // Operational, and ours: `is_enabled` is a platform kill-switch, not something
            // a plan buys (Gate 6, item 8). Seeded on, then left to the panel — so
            // switching a module off for everybody survives the next deploy.
            if (! $module->exists) {
                $module->is_enabled = true;
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
                // No trial: the free rung replaced it at Gate 6, so a shop evaluates the
                // product by using it rather than by a countdown.
                $plan->trial_days = 0;
                $plan->is_public = true;
            }

            $plan->save();

            if ($isNew) {
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
