<?php

declare(strict_types=1);

namespace App\Modules\Platform\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * What the platform billed one shop.
 *
 * Platform-owned like {@see Subscription} — RLS-protected on `tenant_id`, but without
 * `BelongsToTenant` so the Filament panel can read across shops inside
 * `TenantContext::runAsPlatform()`.
 *
 * `lines` is a JSON snapshot, not a relation to `plans`. An invoice must still say what
 * it said the day it was issued even after the plan is renamed or repriced in the panel —
 * an invoice that changes retroactively is worthless as a record.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int|null $subscription_id
 * @property int|null $coupon_id
 * @property string $number
 * @property int $subtotal
 * @property int $discount
 * @property int $credit_applied
 * @property int $total
 * @property string $status
 * @property CarbonImmutable|null $paid_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property array<int, array{label: string, amount: int}> $lines
 * @property-read Subscription|null $subscription
 */
final class SubscriptionInvoice extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_PAID = 'paid';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELED = 'canceled';

    protected $fillable = [
        'tenant_id', 'subscription_id', 'coupon_id', 'number',
        'subtotal', 'discount', 'credit_applied', 'total', 'status', 'paid_at', 'lines',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'subtotal' => 'integer',
            'discount' => 'integer',
            'credit_applied' => 'integer',
            'total' => 'integer',
            'paid_at' => 'immutable_datetime',
            'lines' => 'array',
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
     * @return HasMany<PaymentAttempt, $this>
     */
    public function attempts(): HasMany
    {
        return $this->hasMany(PaymentAttempt::class);
    }

    public function isPaid(): bool
    {
        return $this->status === self::STATUS_PAID;
    }

    /**
     * A zero-total invoice (fully covered by credit, or a 100% coupon) needs no gateway
     * round trip — sending someone to Zarinpal to pay ۰ ریال fails at the gateway.
     */
    public function requiresPayment(): bool
    {
        return $this->total > 0;
    }
}
