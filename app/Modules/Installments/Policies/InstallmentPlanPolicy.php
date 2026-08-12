<?php

declare(strict_types=1);

namespace App\Modules\Installments\Policies;

use App\Modules\Identity\Models\User;
use App\Modules\Installments\Models\InstallmentPlan;

/**
 * Who may write and read an instalment contract.
 *
 * Writing one is a sales act — the person at the counter agreeing terms — so it rides on
 * `sales.create` rather than needing its own permission nobody would remember to grant.
 * Collection, late fees and early settlement are Phase 7.4 and will want their own.
 */
final class InstallmentPlanPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can('installments.view');
    }

    public function view(User $actor, InstallmentPlan $plan): bool
    {
        return $actor->can('installments.view');
    }

    public function create(User $actor): bool
    {
        return $actor->can('sales.create');
    }
}
