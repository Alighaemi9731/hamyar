<?php

declare(strict_types=1);

namespace App\Modules\Identity\Policies;

use App\Modules\Identity\Models\User;

/**
 * The audit trail is readable by whoever holds `activity.view` — by default the
 * Owner and Manager. It is never writable through the application: there is no
 * create, update or delete ability here on purpose.
 */
final class ActivityPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can('activity.view');
    }
}
