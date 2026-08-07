<?php

declare(strict_types=1);

namespace App\Modules\Platform\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * What one shop has bought.
 *
 * Deliberately NOT `BelongsToTenant`. It carries a `tenant_id`, but it is the
 * platform's record of what it is owed — a tenant must never be able to write it, and
 * the Platform module reads across all of them for MRR and churn. Tenant-facing
 * screens go through Platform services rather than querying this directly.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $plan_id
 * @property string $status
 * @property int $credit_balance
 * @property CarbonImmutable|null $trial_ends_at
 * @property CarbonImmutable|null $current_period_start
 * @property CarbonImmutable|null $current_period_end
 * @property CarbonImmutable|null $canceled_at
 * @property CarbonImmutable|null $grace_ends_at
 * @property-read Plan $plan
 */
final class Subscription extends Model
{
    public const STATUS_TRIALING = 'trialing';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_PAST_DUE = 'past_due';

    public const STATUS_CANCELED = 'canceled';

    protected $fillable = [
        'tenant_id', 'plan_id', 'status', 'trial_ends_at',
        'current_period_start', 'current_period_end', 'canceled_at', 'grace_ends_at',
        'credit_balance',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'trial_ends_at' => 'immutable_datetime',
            'current_period_start' => 'immutable_datetime',
            'current_period_end' => 'immutable_datetime',
            'canceled_at' => 'immutable_datetime',
            'grace_ends_at' => 'immutable_datetime',
            'credit_balance' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * @return BelongsTo<Plan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /**
     * @return HasMany<SubscriptionAddon, $this>
     */
    public function addons(): HasMany
    {
        return $this->hasMany(SubscriptionAddon::class);
    }

    public function isTrialing(?CarbonImmutable $now = null): bool
    {
        $now ??= CarbonImmutable::now();

        return $this->status === self::STATUS_TRIALING
            && $this->trial_ends_at !== null
            && $this->trial_ends_at->greaterThan($now);
    }

    /**
     * Can this shop use the product right now?
     *
     * `past_due` still counts while inside its grace window — cutting a shop off the
     * hour a payment fails, when Iranian gateways routinely have transient outages,
     * would lock people out of their own till mid-sale.
     */
    public function isUsable(?CarbonImmutable $now = null): bool
    {
        $now ??= CarbonImmutable::now();

        return match ($this->status) {
            self::STATUS_TRIALING => $this->trial_ends_at?->greaterThan($now) ?? false,
            self::STATUS_ACTIVE => $this->current_period_end?->greaterThan($now) ?? true,
            self::STATUS_PAST_DUE => $this->grace_ends_at?->greaterThan($now) ?? false,
            default => false,
        };
    }

    /**
     * Module codes this subscription grants: the plan's modules plus active add-ons.
     *
     * @return list<string>
     */
    public function grantedModuleCodes(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();

        /** @var list<string> $fromPlan */
        $fromPlan = $this->plan->modules->pluck('code')->values()->all();

        /** @var list<string> $fromAddons */
        $fromAddons = $this->addons
            ->filter(fn (SubscriptionAddon $addon): bool => $addon->isActive($now))
            ->map(fn (SubscriptionAddon $addon): string => $addon->module->code)
            ->values()
            ->all();

        return array_values(array_unique([...$fromPlan, ...$fromAddons]));
    }
}
