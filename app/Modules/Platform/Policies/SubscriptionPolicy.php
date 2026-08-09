<?php

declare(strict_types=1);

namespace App\Modules\Platform\Policies;

use App\Modules\Identity\Models\User;
use App\Modules\Platform\Models\PlatformUser;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Who may see and change what a shop pays.
 *
 * Two abilities rather than one, because they are genuinely different risks: reading the
 * invoice history is routine bookkeeping, while `manage` spends the owner's money and can
 * change what the whole shop is allowed to do. `PermissionCatalogue` gives Manager
 * everything except `billing.*` for exactly that reason.
 *
 * This policy is consulted by TWO kinds of actor, which is why it takes `Authenticatable`
 * rather than `User`:
 *
 * - A **shop user** on the tenant billing screen, checked against `billing.*`.
 * - A **PlatformUser** in the super-admin panel, which reads subscriptions across every
 *   shop. Being in the panel at all already required an active staff account on a
 *   separate guard and a separate table, so there is no second permission to check;
 *   inventing one here would imply a granularity the panel does not actually have.
 *
 * Anything else — an unauthenticated request, some future guard — is denied. The default
 * arm is a deny, not a fallthrough to `true`.
 *
 * There is no `delete`. An invoice is a financial record; it is cancelled, never removed.
 */
final class SubscriptionPolicy
{
    public function viewAny(Authenticatable $actor): bool
    {
        return match (true) {
            $actor instanceof PlatformUser => $actor->is_active,
            $actor instanceof User => $actor->can('billing.view'),
            default => false,
        };
    }

    public function view(Authenticatable $actor): bool
    {
        return $this->viewAny($actor);
    }

    public function manage(Authenticatable $actor): bool
    {
        return match (true) {
            $actor instanceof PlatformUser => $actor->is_active,
            $actor instanceof User => $actor->can('billing.manage'),
            default => false,
        };
    }
}
