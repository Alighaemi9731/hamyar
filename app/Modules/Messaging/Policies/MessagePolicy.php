<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Policies;

use App\Modules\Identity\Models\User;
use App\Modules\Messaging\Models\Message;

/**
 * Reading the message log is not the same as spending the wallet.
 *
 * A salesperson may need to check whether a customer was told their device is ready.
 * Sending a campaign to four thousand people, or topping up credit, is an owner's decision.
 */
final class MessagePolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can('messaging.view');
    }

    public function view(User $actor, Message $message): bool
    {
        return $actor->can('messaging.view');
    }

    public function send(User $actor): bool
    {
        return $actor->can('messaging.send');
    }
}
