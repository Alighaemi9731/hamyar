<?php

declare(strict_types=1);

namespace App\Modules\Moadian\Models;

use App\Modules\Sales\Models\SalesInvoice;
use App\Support\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One document's journey to the authority.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $sales_invoice_id
 * @property string $type
 * @property array<string, mixed> $payload
 * @property string $status
 * @property string|null $reference_number
 * @property string|null $tax_id
 * @property string|null $error_code
 * @property string|null $error_message
 * @property int $attempts
 * @property CarbonImmutable|null $sent_at
 * @property CarbonImmutable|null $confirmed_at
 */
final class MoadianInvoice extends Model
{
    use BelongsToTenant;

    public const STATUS_PENDING = 'pending';

    public const STATUS_SENDING = 'sending';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_FAILED = 'failed';

    public const TYPE_MAIN = 'main';

    public const TYPE_CANCEL = 'cancel';

    public const TYPE_CORRECTION = 'correction';

    protected $fillable = [
        'tenant_id', 'sales_invoice_id', 'type', 'payload', 'status',
        'reference_number', 'tax_id', 'error_code', 'error_message',
        'attempts', 'sent_at', 'confirmed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'attempts' => 'integer',
            'sent_at' => 'immutable_datetime',
            'confirmed_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<SalesInvoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(SalesInvoice::class, 'sales_invoice_id');
    }

    /**
     * Needs a human to look at it.
     *
     * `failed` is included: the queue gave up after its retries, and a transport failure
     * nobody is told about is the silent failure the spec calls the worst outcome.
     */
    public function needsAttention(): bool
    {
        return in_array($this->status, [self::STATUS_REJECTED, self::STATUS_FAILED], true);
    }
}
