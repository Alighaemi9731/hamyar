<?php

declare(strict_types=1);

namespace App\Modules\Platform\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A sellable plan. Central — one catalogue for every tenant.
 *
 * @property int $id
 * @property string $code
 * @property string $name_fa
 * @property string $interval
 * @property int $price integer RIAL (golden rule 2)
 * @property int $trial_days
 * @property string|null $tagline_fa
 * @property int $position
 * @property bool $is_public
 */
final class Plan extends Model
{
    protected $fillable = [
        'code', 'name_fa', 'tagline_fa', 'interval', 'price', 'trial_days', 'is_public', 'position',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price' => 'integer',
            'trial_days' => 'integer',
            'is_public' => 'boolean',
        ];
    }

    /**
     * Plans are addressed by their code, not their id.
     *
     * `POST /billing/subscribe/{plan}` is the one route a shop's own screens hit, and the
     * billing page has always posted `plan.code` to it — against an id-bound route, so the
     * upgrade button 404'd for every shop that ever pressed it. Making the code the route
     * key fixes it in the honest direction: the code is unique, immutable once created
     * (`PlanForm` disables the field on edit) and readable in a URL, where an
     * auto-increment id tells a support engineer nothing.
     */
    public function getRouteKeyName(): string
    {
        return 'code';
    }

    /**
     * @return HasMany<PlanLimit, $this>
     */
    public function limits(): HasMany
    {
        return $this->hasMany(PlanLimit::class);
    }

    /**
     * Days in one billing period. Used by the proration calculator when a subscription
     * has no explicit period yet.
     */
    public function intervalDays(): int
    {
        return match ($this->interval) {
            'year' => 365,
            'quarter' => 91,
            default => 30,
        };
    }

    /**
     * A limit's value, or null for unlimited.
     *
     * Null means unlimited on purpose: a sentinel like 0 or -1 reads as "none" to
     * anyone skimming, which is the opposite of what it means.
     */
    public function limit(string $key): ?int
    {
        $limit = $this->limits->firstWhere('key', $key);

        return $limit?->value;
    }
}
