<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Policies;

use App\Modules\CRM\Models\Account;
use App\Modules\Identity\Models\User;

/**
 * Who may look at the shop's money, and who may move it.
 *
 * The split that matters is between *seeing* a balance and *moving* it. A salesperson
 * needs to know the till has change in it; only somebody trusted with the shop's money
 * should be able to send 200,000,000 to a bank account.
 *
 * Reconciliation sits with `treasury.transfer` rather than with viewing, because ticking
 * an entry is an assertion the shop later relies on — "we checked this against the bank" —
 * and it is not a thing to let anybody with read access do.
 */
final class AccountPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can('treasury.view');
    }

    public function view(User $actor, Account $account): bool
    {
        return $actor->can('treasury.view');
    }

    public function create(User $actor): bool
    {
        return $actor->can('treasury.transfer');
    }

    public function update(User $actor, Account $account): bool
    {
        return $actor->can('treasury.transfer');
    }
}
