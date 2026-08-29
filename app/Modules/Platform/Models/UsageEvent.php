<?php

declare(strict_types=1);

namespace App\Modules\Platform\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * One commercially meaningful moment in a shop's usage: warned, blocked, or upgraded
 * after being blocked.
 *
 * The pricing signal. `kind` plus the unique index means at most one warning and one
 * block per credit per period, so the table stays small enough to keep for ever and
 * answers the only question that decides a price: which limit converts, and which one
 * just costs us the customer.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $metric
 * @property string $kind
 * @property string $period_key
 * @property int $used
 * @property int|null $limit_value
 * @property int $requested
 * @property string $plan_code
 * @property int|null $user_id
 * @property CarbonImmutable|null $created_at
 */
final class UsageEvent extends Model
{
    /** Crossed the warning threshold. */
    public const KIND_WARNING = 'warning';

    /** Refused, one unit at a time. */
    public const KIND_BLOCKED = 'blocked';

    /** Refused a batch — an import or a campaign that did not fit whole. */
    public const KIND_BULK_BLOCKED = 'bulk_blocked';

    /** Bought a bigger plan within a week of being blocked. The conversion. */
    public const KIND_UPGRADED_AFTER = 'upgraded_after';

    public $timestamps = false;

    protected $fillable = [
        'tenant_id', 'metric', 'kind', 'period_key', 'used',
        'limit_value', 'requested', 'plan_code', 'user_id', 'created_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'used' => 'integer',
            'limit_value' => 'integer',
            'requested' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }
}
