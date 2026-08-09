<?php

declare(strict_types=1);

namespace App\Modules\CRM\Policies;

use App\Modules\Identity\Models\User;

/**
 * Authorization for parties — customers, suppliers and همکارها.
 *
 * Checks are on `crm.*` permissions, never on role names, so a shop that has
 * customised its roles gets the behaviour it configured.
 *
 * `viewBalance` is separate from `view` on purpose: the counter needs to look a
 * customer up by phone number all day, and knowing what they owe is a different
 * question from knowing who they are. Salesperson has the first and not the second
 * by default.
 */
final class PartyPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can('crm.view');
    }

    public function view(User $actor): bool
    {
        return $actor->can('crm.view');
    }

    public function create(User $actor): bool
    {
        return $actor->can('crm.create');
    }

    public function update(User $actor): bool
    {
        return $actor->can('crm.update');
    }

    public function viewBalance(User $actor): bool
    {
        return $actor->can('crm.view_balance');
    }
}
