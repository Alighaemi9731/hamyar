<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Policies;

use App\Modules\Identity\Models\User;

/**
 * Authorization for serialized units — the physical handsets.
 *
 * `viewCost` is the one that matters commercially. Gate 1 confirmed the boundary:
 * a Salesperson sees the device and its history but not what the shop paid for it,
 * with a per-shop override for owners who want their staff to see margins. That is a
 * permission (`inventory.view_cost`), not a role check, so the override is a setting
 * rather than a code change.
 */
final class ProductUnitPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can('inventory.view');
    }

    public function view(User $actor): bool
    {
        return $actor->can('inventory.view');
    }

    public function viewCost(User $actor): bool
    {
        return $actor->can('inventory.view_cost');
    }

    /**
     * Moving stock between warehouses. Separate from `adjust`: a transfer conserves
     * total stock, an adjustment creates or destroys it, and shops trust those two
     * acts to very different people.
     */
    public function transfer(User $actor): bool
    {
        return $actor->can('inventory.transfer');
    }

    /**
     * Counting, and writing the difference the count found.
     */
    public function adjust(User $actor): bool
    {
        return $actor->can('inventory.adjust');
    }
}
