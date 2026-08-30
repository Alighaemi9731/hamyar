<?php

declare(strict_types=1);

namespace App\Modules\Platform\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One trip to the payment gateway.
 *
 * A separate row per attempt rather than columns on the invoice, because a shop
 * routinely tries two or three times when a gateway times out, and we need the whole
 * sequence to answer "did they pay twice?".
 *
 * `authority` is Zarinpal's handle for the attempt and carries a UNIQUE index. That
 * index is the idempotency guarantee: a replayed callback — the shop refreshing the
 * return page, the gateway retrying — cannot produce a second verified attempt for the
 * same authority.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $subscription_invoice_id
 * @property string $gateway
 * @property string|null $authority
 * @property string|null $reference the gateway's receipt number, shown to the shop
 * @property string|null $return_to same-host relative path to return to; null = the receipt
 * @property int $amount
 * @property string $status
 * @property string|null $error
 * @property array<string, mixed> $payload
 * @property CarbonImmutable|null $verified_at
 * @property-read SubscriptionInvoice $invoice
 */
final class PaymentAttempt extends Model
{
    public const STATUS_INITIATED = 'initiated';

    public const STATUS_VERIFIED = 'verified';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELED = 'canceled';

    protected $fillable = [
        'tenant_id', 'subscription_invoice_id', 'gateway', 'authority', 'reference',
        'return_to', 'amount', 'status', 'error', 'payload', 'verified_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'payload' => 'array',
            'verified_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<SubscriptionInvoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(SubscriptionInvoice::class, 'subscription_invoice_id');
    }

    public function isVerified(): bool
    {
        return $this->status === self::STATUS_VERIFIED;
    }

    /**
     * Still open to a verification result — anything else has already been decided and
     * must not be decided again.
     */
    public function isPending(): bool
    {
        return $this->status === self::STATUS_INITIATED;
    }
}
