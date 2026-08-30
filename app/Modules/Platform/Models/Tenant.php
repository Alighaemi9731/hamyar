<?php

declare(strict_types=1);

namespace App\Modules\Platform\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A shop.
 *
 * Central model: no `BelongsToTenant`, no RLS. This is the registry tenancy itself is
 * built from — scoping it to a tenant would be circular.
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string $status
 * @property array<string, mixed> $settings
 */
final class Tenant extends Model
{
    /** @use HasFactory<\Database\Factories\TenantFactory> */
    use HasFactory;

    use SoftDeletes;

    public const STATUS_TRIALING = 'trialing';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUSPENDED = 'suspended';

    public const STATUS_ARCHIVED = 'archived';

    protected $fillable = [
        'name',
        'slug',
        'status',
        'trial_ends_at',
        'settings',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'trial_ends_at' => 'immutable_datetime',
            'suspended_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return HasMany<Domain, $this>
     */
    public function domains(): HasMany
    {
        return $this->hasMany(Domain::class);
    }

    /**
     * Limits negotiated for this shop, overriding its plan.
     *
     * @return HasMany<TenantLimitOverride, $this>
     */
    public function limitOverrides(): HasMany
    {
        return $this->hasMany(TenantLimitOverride::class);
    }

    /**
     * @return HasMany<Domain, $this>
     */
    public function primaryDomain(): HasMany
    {
        return $this->domains()->where('is_primary', true);
    }

    /**
     * Can this shop still use the product?
     *
     * Suspended and archived tenants keep resolving (so their users get a clear
     * "your subscription is suspended" page rather than a 404 that looks like the
     * shop was deleted), but the middleware refuses to let them in.
     */
    /**
     * Billing history for this shop.
     *
     * Reading it requires the platform flag (ADR 0002 amendment) — from a tenant request
     * the shop sees only its own rows, which is the same thing from its point of view.
     *
     * @return HasMany<Subscription, $this>
     */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function isUsable(): bool
    {
        return in_array($this->status, [self::STATUS_TRIALING, self::STATUS_ACTIVE], true);
    }

    /**
     * Display preference with a fallback, e.g. `setting('currency_display', 'toman')`.
     */
    public function setting(string $key, mixed $default = null): mixed
    {
        return data_get($this->settings, $key, $default);
    }
}
