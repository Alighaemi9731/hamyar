<?php

declare(strict_types=1);

namespace App\Modules\Identity\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\Permission\Traits\HasRoles;

/**
 * A member of a shop's staff.
 *
 * Tenant-scoped and RLS-protected. Platform staff are a different model entirely
 * ({@see \App\Modules\Platform\Models\PlatformUser}) — see that migration for why.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $name
 * @property string|null $email
 * @property string|null $mobile
 * @property string $password
 * @property bool $is_active
 * @property string|null $two_factor_secret
 * @property list<string>|null $two_factor_recovery_codes
 * @property \Carbon\CarbonImmutable|null $two_factor_confirmed_at
 * @property \Carbon\CarbonImmutable|null $last_login_at
 * @property string|null $last_login_ip
 */
final class User extends Authenticatable
{
    use BelongsToTenant;

    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory;

    use HasRoles;
    use LogsActivity;
    use Notifiable;
    use SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'name',
        'email',
        'mobile',
        'password',
        'is_active',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        // Never serialised, even accidentally: a leaked TOTP secret defeats 2FA
        // entirely, and recovery codes are single-use passwords.
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
            'mobile_verified_at' => 'immutable_datetime',
            'two_factor_confirmed_at' => 'immutable_datetime',
            'last_login_at' => 'immutable_datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            // Encrypted at rest. The database never holds a usable secret, so a dump
            // leak does not hand over everyone's second factor.
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
        ];
    }

    public function hasTwoFactorEnabled(): bool
    {
        return $this->two_factor_confirmed_at !== null;
    }

    /**
     * The audit trail a shop owner reads when asking "who changed this?".
     * Secrets are excluded rather than merely hidden — the activity log is a separate
     * table that outlives the row it describes.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'email', 'mobile', 'is_active'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    /**
     * spatie/permission resolves the "team" from this. Returning the model's own
     * tenant rather than the ambient context matters when an administrator edits
     * another tenant's user from the Platform panel.
     */
    public function getTenantId(): int
    {
        return $this->tenant_id;
    }
}
