<?php

declare(strict_types=1);

namespace App\Modules\Platform\Services;

use App\Modules\Identity\Models\User;
use Illuminate\Support\Facades\Hash;

/**
 * Finds the account behind a mobile number, across every shop.
 *
 * ## Why this exists, and why it is in Platform
 *
 * [ADR 0017](../../../../docs/adr/0017-single-host-app.md) put every shop on one
 * address. The tenant is therefore not known when somebody types their number in — it
 * is a *result* of authenticating, not an input to it. So the lookup has to see across
 * tenants, which means `withoutTenancy()`, which golden rule 1 permits only inside the
 * Platform module and only with the reason written down. This is the reason.
 *
 * `Auth::attempt()` cannot do this job any more: it goes through the tenant-scoped user
 * provider, and with no tenant pinned RLS returns zero rows for every credential. It
 * would not fail loudly — it would report every password as wrong.
 *
 * ## What keeps it safe
 *
 * · **The mobile is globally unique** (ADR 0017's migration), so one number identifies
 *   at most one account and there is no shop to disambiguate.
 * · **The password is verified here**, against the found account, before anything is
 *   written to the session. A caller cannot get a tenant pinned by supplying only a
 *   number.
 * · **`Hash::check` runs even when no account matched**, against a dummy hash. Returning
 *   early on "no such number" makes the response measurably faster for numbers that do
 *   not exist, which turns this into an oracle for which numbers do.
 */
final class AccountLookup
{
    /**
     * A bcrypt hash of a value nothing can match, used to spend the same time on a
     * missing account as on a wrong password.
     */
    private const DUMMY = '$2y$12$usesomesillystringfore.Q7Vc7LQZ.7BwCLDIQ0lZKPMhVQ5W6';

    /**
     * The account for these credentials, or null.
     *
     * Null for "no such number" and for "wrong password" alike — the caller must not be
     * able to tell them apart, because telling them apart lets anybody enumerate which
     * numbers have accounts.
     */
    public function forCredentials(string $mobile, string $password): ?User
    {
        /** @var User|null $user */
        $user = User::withoutTenancy()
            ->where('mobile', $mobile)
            ->first();

        if (! $user instanceof User) {
            Hash::check($password, self::DUMMY);

            return null;
        }

        return Hash::check($password, (string) $user->password) ? $user : null;
    }
}
