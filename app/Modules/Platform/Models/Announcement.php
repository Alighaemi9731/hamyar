<?php

declare(strict_types=1);

namespace App\Modules\Platform\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A notice from the platform to shops.
 *
 * Central: no `BelongsToTenant`, because one row is read by many tenants. `tenant_id`
 * here means "only this shop sees it", the inverse of its meaning everywhere else in the
 * schema — which is worth stating out loud, since a reader who assumes the usual meaning
 * would conclude this table is missing its isolation.
 *
 * @property int $id
 * @property int|null $tenant_id
 * @property string $title
 * @property string $body
 * @property string $level
 * @property CarbonImmutable|null $starts_at
 * @property CarbonImmutable|null $ends_at
 */
final class Announcement extends Model
{
    public const LEVEL_INFO = 'info';

    public const LEVEL_WARNING = 'warning';

    public const LEVEL_CRITICAL = 'critical';

    protected $fillable = ['tenant_id', 'title', 'body', 'level', 'starts_at', 'ends_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
        ];
    }

    /**
     * The single shop this notice targets, if any.
     *
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Live right now, and addressed to this shop.
     *
     * A null `starts_at` means "already running" and a null `ends_at` means "until
     * withdrawn" — both are the common case, so neither should require filling in a date
     * to publish a notice.
     *
     * @param  Builder<Announcement>  $query
     * @return Builder<Announcement>
     */
    public function scopeVisibleTo(Builder $query, ?int $tenantId, ?CarbonImmutable $now = null): Builder
    {
        $now ??= CarbonImmutable::now();

        return $query
            ->where(fn (Builder $q) => $q->whereNull('tenant_id')->orWhere('tenant_id', $tenantId))
            ->where(fn (Builder $q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
            ->where(fn (Builder $q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', $now))
            ->orderByDesc('level')
            ->orderByDesc('id');
    }
}
