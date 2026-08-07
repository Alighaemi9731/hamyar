<?php

declare(strict_types=1);

namespace App\Modules\Identity\Policies;

use App\Modules\Identity\Models\User;

/**
 * Authorization for staff management.
 *
 * Checks are on `module.action` permissions, never on role names. A shop that has
 * customised what its Manager may do gets the behaviour it configured, which is the
 * whole reason roles are per-tenant.
 *
 * Cross-tenant access is not checked here and does not need to be: a user from
 * another shop is invisible to the query in the first place (RLS + the global scope),
 * so the policy never sees one.
 */
final class UserPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can('users.view');
    }

    public function view(User $actor, User $target): bool
    {
        return $actor->can('users.view') || $actor->is($target);
    }

    public function invite(User $actor): bool
    {
        return $actor->can('users.invite');
    }

    public function update(User $actor, User $target): bool
    {
        return $actor->can('users.update') || $actor->is($target);
    }

    public function assignRoles(User $actor, User $target): bool
    {
        // Deliberately not self-assignable: a Manager must not be able to promote
        // themselves to Owner, which is otherwise the shortest path to full control.
        return $actor->can('users.assign_roles') && ! $actor->is($target);
    }

    public function deactivate(User $actor, User $target): bool
    {
        return $actor->can('users.deactivate') && ! $actor->is($target);
    }
}
