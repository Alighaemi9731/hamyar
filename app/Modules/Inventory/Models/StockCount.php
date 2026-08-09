<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Support\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A counting session — انبارگردانی.
 *
 * Records what was counted and turns the difference into adjustment movements. It never
 * sets a total, so the shrinkage stays visible and reportable.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $warehouse_id
 * @property string $number
 * @property string $status
 * @property bool $is_blind
 * @property CarbonImmutable|null $applied_at
 * @property string|null $notes
 * @property-read Warehouse $warehouse
 */
final class StockCount extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<\Database\Factories\StockCountFactory> */
    use HasFactory;

    public const STATUS_OPEN = 'open';

    public const STATUS_APPLIED = 'applied';

    public const STATUS_CANCELED = 'canceled';

    protected $fillable = [
        'tenant_id', 'warehouse_id', 'number', 'status', 'is_blind', 'applied_at', 'actor_id', 'notes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['is_blind' => 'boolean', 'applied_at' => 'immutable_datetime'];
    }

    /**
     * @return HasMany<StockCountItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(StockCountItem::class);
    }

    /**
     * @return BelongsTo<Warehouse, $this>
     */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }
}
