<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Models;

use App\Support\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * One movement of SMS credit. Append-only; the balance is their sum.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $amount signed rial — positive adds, negative spends
 * @property string $type
 * @property string|null $description
 * @property CarbonImmutable $occurred_at
 */
final class SmsCreditEntry extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    public const TYPE_TOPUP = 'topup';

    public const TYPE_CHARGE = 'charge';

    public const TYPE_REFUND = 'refund';

    public const TYPE_ADJUSTMENT = 'adjustment';

    protected $fillable = [
        'tenant_id', 'amount', 'type', 'description',
        'reference_type', 'reference_id', 'actor_id', 'occurred_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'occurred_at' => 'immutable_datetime',
        ];
    }
}
