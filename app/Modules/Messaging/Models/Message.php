<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Models;

use App\Modules\CRM\Models\Party;
use App\Support\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One text message, written before it is sent.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int|null $party_id
 * @property string $to
 * @property string|null $template_key
 * @property string|null $provider_template_id
 * @property list<string>|null $tokens
 * @property string $status
 * @property string|null $driver
 * @property string|null $provider_reference
 * @property string|null $error
 * @property int $segments
 * @property int $cost
 * @property string|null $idempotency_key
 * @property CarbonImmutable $queued_at
 * @property CarbonImmutable|null $sent_at
 */
final class Message extends Model
{
    use BelongsToTenant;

    public const STATUS_QUEUED = 'queued';

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    /** Never handed to a driver: opted out, unsendable number, or no credit. */
    public const STATUS_SUPPRESSED = 'suppressed';

    protected $fillable = [
        'tenant_id', 'branch_id', 'party_id', 'to', 'template_key', 'provider_template_id',
        'tokens', 'body', 'status', 'driver', 'provider_reference', 'error',
        'segments', 'cost', 'idempotency_key', 'reference_type', 'reference_id',
        'queued_at', 'sent_at',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => self::STATUS_QUEUED,
        'segments' => 1,
        'cost' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tokens' => 'array',
            'segments' => 'integer',
            'cost' => 'integer',
            'queued_at' => 'immutable_datetime',
            'sent_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Party, $this>
     */
    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }
}
