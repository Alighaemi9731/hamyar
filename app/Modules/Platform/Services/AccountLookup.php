<?php

declare(strict_types=1);

namespace App\Modules\Platform\Services;

use App\Modules\Identity\Models\User;
use App\Modules\Platform\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
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
     * A hash of a value nothing can match, computed once per process.
     *
     * Not a hardcoded constant. The first version was a literal bcrypt string and
     * `HASH_DRIVER` is argon2id, so `Hash::check()` threw "This password does not use
     * the Argon2id algorithm" — a 500 on the login page, from the branch that exists to
     * make a missing account indistinguishable from a wrong password.
     *
     * Built through `Hash::make()` so it always matches the configured driver, and
     * memoised so the cost is paid once rather than on every failed attempt.
     */
    private static ?string $dummy = null;

    public function __construct(private readonly TenantContext $context) {}

    /**
     * The account for these credentials, or null.
     *
     * Null for "no such number" and for "wrong password" alike — the caller must not be
     * able to tell them apart, because telling them apart lets anybody enumerate which
     * numbers have accounts.
     */
    public function forCredentials(string $mobile, string $password): ?User
    {
        if ($mobile === '' || $password === '') {
            return null;
        }

        /*
        | `runAsPlatform()`, not just `withoutTenancy()`.
        |
        | Tenancy has two layers (ADR 0002) and they fail differently. `withoutTenancy()`
        | removes the Eloquent global scope; the Postgres policy is still there, and with
        | no `app.tenant_id` set it denies every row. Without this the query returns
        | nothing for a number that plainly exists — so every correct password is reported
        | wrong, silently, with nothing in any log to explain it.
        |
        | The escape is narrow by construction: it reads one row, verifies a password
        | against it, and the flag is cleared in a `finally` by TenantContext.
        */
        $found = $this->context->runAsPlatform(
            static fn (): ?Model => User::withoutTenancy()->where('mobile', $mobile)->first(),
        );

        $user = $found instanceof User ? $found : null;

        if (! $user instanceof User) {
            // Spend comparable time on a missing account as on a wrong password.
            // Returning early here makes "no such number" measurably faster, which turns
            // this method into an oracle for which numbers have accounts.
            self::$dummy ??= Hash::make('no-account-matches-this-value');
            Hash::check($password, self::$dummy);

            return null;
        }

        return Hash::check($password, (string) $user->password) ? $user : null;
    }

    /**
     * The shop a mobile number belongs to, or null.
     *
     * ## Why password reset needs this
     *
     * Reset is the other flow that runs before any tenant is known. It used to be
     * reached on the shop's own hostname, so `PasswordResetService` could simply query
     * `users` and trust the pinned context; with one address there is nothing pinned,
     * RLS denies every row, and the form would answer "if this number is registered we
     * have sent you a link" to everybody, forever, having done nothing.
     *
     * So the number resolves the shop and the controller enters it with
     * `TenantContext::runFor()`. The token is still minted, stored and redeemed inside
     * exactly one shop's context — what changed is only where that context comes from.
     *
     * ## No password is checked here, deliberately
     *
     * `forCredentials()` above verifies one before it returns anything, because it hands
     * back an account to sign in as. This hands back a *shop*, to no one: the caller must
     * answer identically whether or not a number resolves (PasswordResetController does),
     * so learning the shop reveals nothing to the visitor.
     *
     * ## Mobile only, not email
     *
     * `users.mobile` is globally unique (ADR 0017) and `users.email` is not — the same
     * address may legitimately exist at two shops, so it cannot name one. Password reset
     * by email was already unreachable in practice, since the login form takes a mobile
     * number and nothing else.
     */
    public function tenantForMobile(string $mobile): ?Tenant
    {
        if ($mobile === '') {
            return null;
        }

        /*
        | `runAsPlatform()`, not just `withoutTenancy()` — the same two-layer trap as in
        | forCredentials(). Layer 2 (the Eloquent scope) comes off with withoutTenancy();
        | layer 1 (the Postgres policy) still denies every row with no `app.tenant_id`
        | set, and the failure is silent: every number looks unregistered.
        */
        $found = $this->context->runAsPlatform(
            static fn (): ?Model => User::withoutTenancy()->where('mobile', $mobile)->first(),
        );

        return $found instanceof User ? $found->tenant : null;
    }
}
