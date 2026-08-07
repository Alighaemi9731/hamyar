<?php

declare(strict_types=1);

namespace App\Modules\Identity\Services;

use App\Modules\Identity\Models\User;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Per-tenant password reset.
 *
 * Not Laravel's built-in broker, for two reasons: the broker keys on email while our
 * staff log in with a mobile number, and it has no notion of a tenant — the same
 * number can legitimately exist at two shops, and a token issued for one must not
 * reset the other.
 *
 * Only the token HASH is stored. A database leak yields no working reset links, which
 * is the whole point of hashing a bearer credential.
 */
final class PasswordResetService
{
    /** Short on purpose: a reset link is a password. */
    public const TOKEN_TTL_MINUTES = 60;

    public function __construct(private readonly TenantContext $context) {}

    /**
     * Issue a token for an identifier, or return null when nobody matches.
     *
     * The caller must NOT reveal which happened — see the controller.
     */
    public function issue(string $identifier): ?string
    {
        $user = User::query()
            ->where('mobile', $identifier)
            ->orWhere('email', $identifier)
            ->first();

        if (! $user instanceof User || ! $user->is_active) {
            return null;
        }

        $token = Str::random(64);

        DB::table('password_reset_tokens')->updateOrInsert(
            [
                'tenant_id' => $this->context->idOrFail(),
                'identifier' => $identifier,
            ],
            [
                'token' => hash('sha256', $token),
                'created_at' => now(),
            ]
        );

        return $token;
    }

    /**
     * Complete a reset. Returns false for an unknown, expired or already-used token.
     */
    public function reset(string $identifier, string $token, string $password): bool
    {
        $tenantId = $this->context->idOrFail();

        $row = DB::table('password_reset_tokens')
            ->where('tenant_id', $tenantId)
            ->where('identifier', $identifier)
            ->first();

        if ($row === null) {
            return false;
        }

        // Constant-time: the token is a bearer credential and a timing oracle on it
        // is a real, demonstrated attack.
        if (! hash_equals((string) $row->token, hash('sha256', $token))) {
            return false;
        }

        // Carbon 3's diffInMinutes() is SIGNED: `now()->diffInMinutes($past)` returns
        // a NEGATIVE number, so the naive `> TTL` comparison never fired and tokens
        // never expired. Compare instants instead of diffing them.
        $createdAt = CarbonImmutable::parse((string) $row->created_at);

        if ($createdAt->addMinutes(self::TOKEN_TTL_MINUTES)->isPast()) {
            return false;
        }

        $user = User::query()
            ->where('mobile', $identifier)
            ->orWhere('email', $identifier)
            ->first();

        if (! $user instanceof User) {
            return false;
        }

        $user->forceFill([
            'password' => Hash::make($password),
            // Any stolen "remember me" cookie stops working at the same moment the
            // password changes; otherwise a reset does not actually evict an attacker.
            'remember_token' => Str::random(60),
        ])->save();

        // Single use.
        DB::table('password_reset_tokens')
            ->where('tenant_id', $tenantId)
            ->where('identifier', $identifier)
            ->delete();

        // Every other session for this user dies too — a reset is what someone does
        // when they believe their account is compromised.
        DB::table('sessions')->where('user_id', $user->getKey())->delete();

        return true;
    }
}
