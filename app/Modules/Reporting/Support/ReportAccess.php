<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Support;

use App\Modules\Identity\Models\User;

/**
 * Who may see what, asked in one place by both the index and the screens.
 *
 * ## Why these are not simply permission names
 *
 * Almost every one of them is a **pair**, because two permissions say the same thing from
 * two directions: an operational one held by the person who does the job, and
 * `reporting.view_financial`, the back-office one held by the accountant. Margin is the
 * original case — `sales.view_profit` is the counter's version and `reporting.view_financial`
 * the back office's, and a shop can grant either.
 *
 * Asking for one of them in the dashboard and the other in the report viewer is how the
 * same person ends up seeing a figure on one screen and not the other, which reads as a bug
 * in whichever screen they saw second. So each question is asked here, once, and every
 * caller asks here — including `ReportCatalogue`, so **a row that is listed can always be
 * opened**. A listed report that 403s when clicked is worse than no row.
 *
 * ## The default is no
 *
 * A user with neither permission — and an absent user, which is what a misrouted public
 * page looks like — gets `false` from every method. These figures are the most
 * commercially sensitive in the building and the layer that decides fails closed.
 */
final class ReportAccess
{
    /**
     * Profit and margin.
     */
    public static function showsMargin(?User $user): bool
    {
        return self::any($user, ['reporting.view_financial', 'sales.view_profit']);
    }

    /**
     * What the shop paid — stock valuation, dead stock, parts cost.
     *
     * `inventory.view_cost` is the permission a Warehousekeeper holds precisely so they can
     * price what they count; asking only for the back-office one would hand the stocktake to
     * the accountant and leave the person doing it guessing.
     */
    public static function showsCost(?User $user): bool
    {
        return self::any($user, ['reporting.view_financial', 'inventory.view_cost']);
    }

    /**
     * Who owes the shop and who the shop owes.
     *
     * `crm.view_balance` is the counter's version: a Cashier chasing a debt while the
     * customer is standing there needs the aging, and they hold it by default.
     */
    public static function showsBalances(?User $user): bool
    {
        return self::any($user, ['reporting.view_financial', 'crm.view_balance']);
    }

    public static function showsCheques(?User $user): bool
    {
        return self::any($user, ['reporting.view_financial', 'cheques.view']);
    }

    public static function showsInstallments(?User $user): bool
    {
        return self::any($user, ['reporting.view_financial', 'installments.view']);
    }

    /**
     * VAT. The one question with no operational counterpart.
     *
     * A tax summary is filed, not worked from, and nobody at the counter has a use for it —
     * so this stays a single back-office permission rather than becoming a pair for the sake
     * of symmetry.
     */
    public static function showsTax(?User $user): bool
    {
        return self::any($user, ['reporting.view_financial']);
    }

    /**
     * SMS usage and what it cost.
     */
    public static function showsMessaging(?User $user): bool
    {
        return self::any($user, ['reporting.view_financial', 'messaging.view']);
    }

    /**
     * The named gate a catalogue row carries, resolved.
     *
     * `ReportCatalogue` stores a gate key rather than a closure so the catalogue stays a
     * plain data structure, and this is the single place that turns one into an answer —
     * the point being that adding a report cannot accidentally introduce a *seventh* way of
     * asking the same question.
     *
     * An unrecognised key denies. A typo in a catalogue row should hide the row, not open it.
     */
    public static function allows(?User $user, ?string $gate): bool
    {
        return match ($gate) {
            null => true,
            'margin' => self::showsMargin($user),
            'cost' => self::showsCost($user),
            'balances' => self::showsBalances($user),
            'cheques' => self::showsCheques($user),
            'installments' => self::showsInstallments($user),
            'tax' => self::showsTax($user),
            'messaging' => self::showsMessaging($user),
            default => false,
        };
    }

    /**
     * @param  list<string>  $permissions
     */
    private static function any(?User $user, array $permissions): bool
    {
        if (! $user instanceof User) {
            return false;
        }

        foreach ($permissions as $permission) {
            if ($user->can($permission)) {
                return true;
            }
        }

        return false;
    }
}
