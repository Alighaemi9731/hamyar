<?php

declare(strict_types=1);

namespace App\Modules\CRM\Policies;

use App\Modules\CRM\Models\Party;
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

    public function view(User $actor, Party $party): bool
    {
        return $actor->can('crm.view');
    }

    public function create(User $actor): bool
    {
        return $actor->can('crm.create');
    }

    public function update(User $actor, Party $party): bool
    {
        return $actor->can('crm.update');
    }

    public function viewBalance(User $actor): bool
    {
        return $actor->can('crm.view_balance');
    }

    /**
     * Adjusting someone's points by hand.
     *
     * Separate from `update` for the same reason `inventory.view_cost` and
     * `repairs.reveal_passcode` are separate: editing a customer's phone number and
     * granting them something worth money are different acts of trust, and
     * Salesperson holds `crm.update` by default.
     */
    public function manageLoyalty(User $actor): bool
    {
        return $actor->can('crm.manage_loyalty');
    }

    /**
     * Bulk import.
     *
     * Its own permission rather than `create`: one form adds one customer someone is
     * looking at, the other writes five hundred rows nobody has read. Shops hand the
     * first to the counter and keep the second.
     */
    public function import(User $actor): bool
    {
        return $actor->can('crm.import');
    }
}
