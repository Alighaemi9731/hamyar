<?php

declare(strict_types=1);

namespace App\Modules\Identity\Services;

use App\Modules\Identity\Models\User;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/**
 * TOTP two-factor: enrolment, verification and recovery codes.
 *
 * The secret and the recovery codes are encrypted at rest by the model's casts, so a
 * database dump does not hand over anyone's second factor.
 *
 * Enrolment is deliberately two-step — generate, then **confirm with a real code**
 * before `two_factor_confirmed_at` is set. Enabling on generation alone is how users
 * lock themselves out: they scan a QR that did not save, log out, and can never get
 * back in.
 */
final class TwoFactorService
{
    /** Accept a code from the adjacent 30s windows: phone clocks drift. */
    private const WINDOW = 1;

    private const RECOVERY_CODE_COUNT = 8;

    public function __construct(private readonly Google2FA $google2fa) {}

    /**
     * Begin enrolment. Returns the secret and the otpauth:// URI for the QR code.
     *
     * @return array{secret: string, uri: string}
     */
    public function begin(User $user): array
    {
        $secret = $this->google2fa->generateSecretKey();

        $user->forceFill([
            'two_factor_secret' => $secret,
            // Not confirmed yet — 2FA is not active until a code proves the
            // authenticator actually holds the secret.
            'two_factor_confirmed_at' => null,
        ])->save();

        $issuer = config()->string('app.name');

        $tenant = $user->tenant()->first();
        $label = $tenant instanceof \App\Modules\Platform\Models\Tenant ? $tenant->name : $issuer;

        $account = $user->mobile ?? $user->email ?? (string) $user->id;

        return [
            'secret' => $secret,
            'uri' => $this->google2fa->getQRCodeUrl($issuer, trim($label.':'.$account), $secret),
        ];
    }

    /**
     * Finish enrolment: verify a code, then issue recovery codes.
     *
     * @return list<string>|null recovery codes, or null when the code was wrong
     */
    public function confirm(User $user, string $code): ?array
    {
        if (! $this->verify($user, $code)) {
            return null;
        }

        $codes = $this->generateRecoveryCodes();

        $user->forceFill([
            'two_factor_recovery_codes' => $codes,
            'two_factor_confirmed_at' => now(),
        ])->save();

        return $codes;
    }

    public function verify(User $user, string $code): bool
    {
        $secret = $user->two_factor_secret;

        if ($secret === null || $secret === '') {
            return false;
        }

        // Persian digits reach here from a Persian keyboard.
        $normalised = preg_replace('/\D/', '', \App\Support\Digits::toLatin(trim($code))) ?? '';

        if ($normalised === '') {
            return false;
        }

        return $this->google2fa->verifyKey($secret, $normalised, self::WINDOW) !== false;
    }

    /**
     * Consume a recovery code. Single use — it is removed on success, because a
     * reusable recovery code is just a second password.
     */
    public function consumeRecoveryCode(User $user, string $code): bool
    {
        $codes = $user->two_factor_recovery_codes ?? [];

        $candidate = trim($code);

        foreach ($codes as $index => $stored) {
            // Constant-time compare: recovery codes are credentials.
            if (hash_equals($stored, $candidate)) {
                unset($codes[$index]);

                $user->forceFill(['two_factor_recovery_codes' => array_values($codes)])->save();

                return true;
            }
        }

        return false;
    }

    public function disable(User $user): void
    {
        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();
    }

    /**
     * @return list<string>
     */
    public function generateRecoveryCodes(): array
    {
        return array_map(
            static fn (): string => Str::lower(Str::random(5)).'-'.Str::lower(Str::random(5)),
            range(1, self::RECOVERY_CODE_COUNT)
        );
    }
}
