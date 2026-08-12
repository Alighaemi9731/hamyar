<?php

declare(strict_types=1);

namespace App\Modules\Repairs\Policies;

use App\Modules\Identity\Models\User;
use App\Modules\Repairs\Models\RepairTicket;

/**
 * Authorization for the bench.
 *
 * `revealPasscode` is the one that matters, and it is deliberately its own permission
 * rather than a corollary of `repairs.update`. A technician who may move a ticket
 * through the workflow does not thereby need the code that unlocks somebody's phone —
 * plenty of repairs never require it, and the ones that do are a decision the shop
 * makes about specific people (Gate 1).
 */
final class RepairTicketPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can('repairs.view');
    }

    public function view(User $actor, RepairTicket $ticket): bool
    {
        return $actor->can('repairs.view');
    }

    public function create(User $actor): bool
    {
        return $actor->can('repairs.create');
    }

    public function update(User $actor, RepairTicket $ticket): bool
    {
        return $actor->can('repairs.update');
    }

    public function assign(User $actor, RepairTicket $ticket): bool
    {
        return $actor->can('repairs.assign');
    }

    public function approve(User $actor, RepairTicket $ticket): bool
    {
        return $actor->can('repairs.approve');
    }

    public function deliver(User $actor, RepairTicket $ticket): bool
    {
        return $actor->can('repairs.deliver');
    }

    /**
     * Reading the customer's unlock code.
     *
     * Separate, narrow, and audited every single time — see
     * {@see \App\Modules\Repairs\Http\Controllers\PasscodeController}. A leaked unlock
     * code is a stolen phone, so who may ask is a question the shop answers explicitly
     * rather than one that falls out of somebody's job title.
     */
    public function revealPasscode(User $actor, RepairTicket $ticket): bool
    {
        return $actor->can('repairs.reveal_passcode');
    }
}
