<?php

declare(strict_types=1);

namespace App\Modules\Platform\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
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
     * @return BelongsToMany<Module, $this>
     */
    public function modules(): BelongsToMany
    {
        // Table named explicitly: Laravel's convention would order the segments
        // alphabetically to `module_plan`, but the documented schema (roadmap 2.1)
        // calls it `plan_module`.
        return $this->belongsToMany(Module::class, 'plan_module');
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
