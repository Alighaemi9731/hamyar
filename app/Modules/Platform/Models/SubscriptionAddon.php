<?php

declare(strict_types=1);

namespace App\Modules\Platform\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A module bought on top of a plan.
 *
 * Platform-owned like its parent subscription, and RLS-protected on its own
 * `tenant_id` rather than relying on the parent being protected — see the migration
 * `2026_08_08_000020` for why that reliance was not good enough.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $subscription_id
 * @property int $module_id
 * @property int $price
 * @property CarbonImmutable $starts_at
 * @property CarbonImmutable|null $ends_at
 * @property-read Module $module
 */
final class SubscriptionAddon extends Model
{
    protected $fillable = ['tenant_id', 'subscription_id', 'module_id', 'price', 'starts_at', 'ends_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price' => 'integer',
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Subscription, $this>
     */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    /**
     * @return BelongsTo<Module, $this>
     */
    public function module(): BelongsTo
    {
        return $this->belongsTo(Module::class);
    }

    /**
     * Removed add-ons keep working until period end, like a downgrade (ADR 0006):
     * `ends_at` is set in the future rather than the row being deleted.
     */
    public function isActive(?CarbonImmutable $now = null): bool
    {
        $now ??= CarbonImmutable::now();

        return $this->starts_at->lessThanOrEqualTo($now)
            && ($this->ends_at === null || $this->ends_at->greaterThan($now));
    }
}
