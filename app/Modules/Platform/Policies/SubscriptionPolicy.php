<?php

declare(strict_types=1);

namespace App\Modules\Platform\Policies;

use App\Modules\Identity\Models\User;

/**
 * Who may see and change what the shop pays.
 *
 * Two abilities rather than one, because they are genuinely different risks: reading the
 * invoice history is routine bookkeeping, while `manage` spends the owner's money and
 * can change what the whole shop is allowed to do. `PermissionCatalogue` gives Manager
 * everything except `billing.*` for exactly that reason.
 *
 * There is no `delete`. An invoice is a financial record; it is cancelled, never removed.
 */
final class SubscriptionPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can('billing.view');
    }

    public function manage(User $actor): bool
    {
        return $actor->can('billing.manage');
    }
}
