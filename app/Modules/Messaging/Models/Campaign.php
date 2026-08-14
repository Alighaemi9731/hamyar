<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Models;

use App\Support\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * One bulk send.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int|null $branch_id
 * @property string $name
 * @property string $body
 * @property string|null $provider_template_id
 * @property array<string, mixed>|null $filters
 * @property string $status
 * @property int $per_minute
 * @property CarbonImmutable|null $scheduled_for
 * @property int $queued_count
 * @property int $skipped_count
 */
final class Campaign extends Model
{
    use BelongsToTenant;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_SCHEDULED = 'scheduled';

    public const STATUS_SENDING = 'sending';

    public const STATUS_SENT = 'sent';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'tenant_id', 'branch_id', 'name', 'body', 'provider_template_id', 'filters',
        'status', 'per_minute', 'scheduled_for', 'started_at', 'finished_at',
        'queued_count', 'skipped_count', 'actor_id',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => self::STATUS_DRAFT,
        'per_minute' => 60,
        'queued_count' => 0,
        'skipped_count' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'filters' => 'array',
            'per_minute' => 'integer',
            'queued_count' => 'integer',
            'skipped_count' => 'integer',
            'scheduled_for' => 'immutable_datetime',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
        ];
    }

    public function isSendable(): bool
    {
        return in_array($this->status, [self::STATUS_DRAFT, self::STATUS_SCHEDULED], true)
            && $this->provider_template_id !== null;
    }
}
