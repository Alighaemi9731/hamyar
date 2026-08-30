<?php

declare(strict_types=1);

namespace App\Modules\Platform\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A limit negotiated for one shop, beating whatever its plan says.
 *
 * Platform-owned, written only from the Filament panel, read by `LimitResolver` in tenant
 * context with an explicit `where tenant_id`.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $metric
 * @property int|null $value
 * @property string $reason
 *
 * `value` null means unlimited for this shop — the same meaning `plan_limits.value` has,
 * deliberately, because two columns for one idea must not disagree about null.
 * @property CarbonImmutable|null $expires_at
 * @property int|null $created_by
 */
final class TenantLimitOverride extends Model
{
    protected $fillable = ['tenant_id', 'metric', 'value', 'reason', 'expires_at', 'created_by'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'value' => 'integer',
            'expires_at' => 'immutable_datetime',
        ];
    }

    /**
     * Is this override in force right now?
     *
     * An expired row is left in place rather than deleted: "this shop had fifty seats
     * until Mehr, and why" is exactly the question support asks, and a deleted row
     * answers it with silence.
     */
    public function isLive(?CarbonImmutable $now = null): bool
    {
        $now ??= CarbonImmutable::now();

        return $this->expires_at === null || $this->expires_at->greaterThan($now);
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
