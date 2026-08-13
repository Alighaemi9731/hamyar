<?php

declare(strict_types=1);

namespace App\Modules\Cheques\Policies;

use App\Modules\Cheques\Models\Cheque;
use App\Modules\Identity\Models\User;

/**
 * Seeing the cheque book is not the same as moving paper through it.
 *
 * `cheques.manage` covers every transition, including the destructive ones — a write-off
 * abandons a claim on somebody's money, and an endorsement hands an asset to a third
 * party. Both are decisions with a person's name attached, which is what the event trail
 * records.
 */
final class ChequePolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can('cheques.view');
    }

    public function view(User $actor, Cheque $cheque): bool
    {
        return $actor->can('cheques.view');
    }

    public function create(User $actor): bool
    {
        return $actor->can('cheques.manage');
    }

    public function update(User $actor, Cheque $cheque): bool
    {
        return $actor->can('cheques.manage');
    }
}
