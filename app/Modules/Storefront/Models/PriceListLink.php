<?php

declare(strict_types=1);

namespace App\Modules\Storefront\Models;

use App\Modules\Catalog\Models\PriceLevel;
use App\Support\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A reseller price list, shared as a link.
 *
 * ## Three ways to be closed, and they are different answers
 *
 * Expired, revoked, and wrong-password are distinct states because the visitor needs
 * different things from each: an expired link should say so (ask the shop for a new one), a
 * revoked one should say the same (the shop withdrew it), and a wrong password should say
 * only that — never whether the link itself is real.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $lookup
 * @property string $token_hash
 * @property string|null $label
 * @property int $price_level_id
 * @property string|null $password_hash
 * @property CarbonImmutable $expires_at
 * @property array<int, int>|null $categories
 * @property int $view_count
 * @property CarbonImmutable|null $last_viewed_at
 * @property CarbonImmutable|null $revoked_at
 */
final class PriceListLink extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'lookup', 'token_hash', 'label', 'price_level_id',
        'password_hash', 'expires_at', 'categories', 'created_by',
    ];

    /**
     * Hashes never cross the wire, in either direction.
     *
     * @var list<string>
     */
    protected $hidden = ['token_hash', 'password_hash'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'categories' => 'array',
            'expires_at' => 'immutable_datetime',
            'last_viewed_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<PriceLevel, $this>
     */
    public function priceLevel(): BelongsTo
    {
        return $this->belongsTo(PriceLevel::class, 'price_level_id');
    }

    /**
     * @return HasMany<PriceListView, $this>
     */
    public function views(): HasMany
    {
        return $this->hasMany(PriceListView::class);
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function isExpired(?CarbonImmutable $at = null): bool
    {
        return $this->expires_at->lessThanOrEqualTo($at ?? CarbonImmutable::now());
    }

    public function needsPassword(): bool
    {
        return $this->password_hash !== null && $this->password_hash !== '';
    }

    /**
     * Usable right now — before any password is considered.
     */
    public function isLive(?CarbonImmutable $at = null): bool
    {
        return ! $this->isRevoked() && ! $this->isExpired($at);
    }
}
