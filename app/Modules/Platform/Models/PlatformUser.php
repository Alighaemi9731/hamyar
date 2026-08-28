<?php

declare(strict_types=1);

namespace App\Modules\Platform\Models;

use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * Hamyar staff — the people who run the platform, not a shop.
 *
 * Central model on its own guard. Keeping these accounts off the tenant `users` table
 * means a compromised shop login can never reach the platform panel, and it avoids
 * needing an RLS exemption on the one table an attacker most wants one for.
 *
 * @property int $id
 * @property string $name
 * @property string $email
 * @property bool $is_active
 */
final class PlatformUser extends Authenticatable implements FilamentUser
{
    /**
     * Deactivating a staff account must lock them out immediately.
     *
     * Filament calls this on every panel request, not only at login, so revoking access
     * does not wait for a session to expire — which matters most in the case you
     * actually care about, someone leaving under a cloud.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        unset($panel);

        return $this->is_active;
    }

    /** @use HasFactory<\Database\Factories\PlatformUserFactory> */
    use HasFactory;

    use Notifiable;

    protected $table = 'platform_users';

    protected $fillable = [
        'name',
        'email',
        'password',
        'is_active',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'immutable_datetime',
            'two_factor_confirmed_at' => 'immutable_datetime',
            'last_login_at' => 'immutable_datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
        ];
    }
}
