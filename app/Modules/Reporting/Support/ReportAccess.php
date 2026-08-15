<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Support;

use App\Modules\Identity\Models\User;

/**
 * The one answer to "may this person see what the shop paid?".
 *
 * ## Why it is not simply a permission name
 *
 * Two permissions say it, from two directions. `sales.view_profit` is the counter's
 * version — the margin on an invoice, denied to a Salesperson by default (Gate 1).
 * `reporting.view_financial` is the back-office version — the margin on a report,
 * granted to an Accountant. A shop can grant either.
 *
 * Asking for one of them in the dashboard and the other in the report viewer is how the
 * same person ends up seeing margin on one screen and not the other, which reads as a
 * bug in whichever screen they saw second. So both are asked, in one place, and every
 * caller asks here.
 *
 * ## The default is no
 *
 * A user with neither permission — and an absent user, which is what a misrouted public
 * page looks like — gets `false`. Margin is the most commercially sensitive figure in
 * the building and the layer that decides it fails closed.
 */
final class ReportAccess
{
    public static function showsMargin(?User $user): bool
    {
        return $user instanceof User
            && ($user->can('reporting.view_financial') || $user->can('sales.view_profit'));
    }
}
