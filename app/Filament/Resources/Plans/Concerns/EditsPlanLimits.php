<?php

declare(strict_types=1);

namespace App\Filament\Resources\Plans\Concerns;

use App\Filament\Resources\Plans\Schemas\PlanForm;
use App\Modules\Platform\Models\Plan;
use App\Modules\Platform\Models\PlanLimit;
use App\Modules\Platform\Models\Subscription;
use App\Modules\Platform\Services\Quota\LimitResolver;
use App\Support\Quota\MetricRegistry;

/**
 * Moves plan limits between `plan_limits` rows and the form's flat fields.
 *
 * The form cannot bind to the relation directly: a Filament field name may not contain a
 * dot, because dots mean nested state — and every metric key has one by construction. So
 * `PlanForm::fieldFor()` flattens `sales.invoices` to `quota_sales__invoices`, and this
 * puts it back on the way in and out.
 *
 * ## Empty means unlimited, and it is written as a row
 *
 * A blank box stores a row with a null value rather than no row. The two behave
 * identically to `LimitResolver` — both are unlimited — but they mean different things to
 * a person: a null row says "we decided this plan has no cap here", a missing row says
 * "nobody has looked at this yet". `quota:audit` reports the second and not the first, and
 * that distinction only exists if the panel writes the row.
 *
 * ## Saving bumps every shop on the plan
 *
 * Limits are memoised per process and validated against `tenants.entitlement_version`. An
 * edit without a bump would take effect whenever each worker happened to restart next —
 * the kind of "it works, later" that cannot be debugged from a support ticket. One UPDATE
 * per tenant, on a screen a human just clicked Save on, is the right price for that.
 */
trait EditsPlanLimits
{
    /** @var array<string, int|null> */
    private array $quotaValues = [];

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $plan = $this->record;

        // Create pages fill an empty form: there is no record to read limits from, and
        // every field simply starts blank (= unlimited until the operator types).
        if (! $plan instanceof Plan) {
            return $data;
        }

        $values = $plan->limits->pluck('value', 'key');

        foreach (app(MetricRegistry::class)->all() as $metric) {
            $data[PlanForm::fieldFor($metric->key)] = $values->get($metric->key);
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        return $this->stripQuotaFields($data);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return $this->stripQuotaFields($data);
    }

    protected function afterSave(): void
    {
        $this->persistLimits();
    }

    protected function afterCreate(): void
    {
        $this->persistLimits();
    }

    /**
     * Lift the quota fields out of the payload — they are not columns on `plans` and
     * would fail to save.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function stripQuotaFields(array $data): array
    {
        $this->quotaValues = [];

        foreach (app(MetricRegistry::class)->all() as $metric) {
            $field = PlanForm::fieldFor($metric->key);

            if (! array_key_exists($field, $data)) {
                continue;
            }

            $raw = $data[$field];

            $this->quotaValues[$metric->key] = ($raw === null || $raw === '') ? null : (int) $raw;

            unset($data[$field]);
        }

        return $data;
    }

    private function persistLimits(): void
    {
        $plan = $this->record;

        if (! $plan instanceof Plan) {
            return;
        }

        /** @var int $planId */
        $planId = $plan->getKey();

        foreach ($this->quotaValues as $key => $value) {
            PlanLimit::query()->updateOrCreate(
                ['plan_id' => $planId, 'key' => $key],
                ['value' => $value],
            );
        }

        $resolver = app(LimitResolver::class);

        /** @var list<int> $tenantIds */
        $tenantIds = Subscription::query()
            ->where('plan_id', $planId)
            ->distinct()
            ->pluck('tenant_id')
            ->all();

        foreach ($tenantIds as $tenantId) {
            $resolver->bump($tenantId);
        }
    }
}
