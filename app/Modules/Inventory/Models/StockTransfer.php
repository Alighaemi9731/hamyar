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
 * A movement of stock between two warehouses, in two steps.
 *
 * Dispatched and received are separate events because the van journey is real. Between
 * them the goods belong to neither end — visible as in transit, sellable at neither.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $from_warehouse_id
 * @property int $to_warehouse_id
 * @property string $number
 * @property string $status
 * @property CarbonImmutable|null $dispatched_at
 * @property CarbonImmutable|null $received_at
 * @property string|null $notes
 */
final class StockTransfer extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<\Database\Factories\StockTransferFactory> */
    use HasFactory;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_DISPATCHED = 'dispatched';

    public const STATUS_RECEIVED = 'received';

    public const STATUS_CANCELED = 'canceled';

    protected $fillable = [
        'tenant_id', 'from_warehouse_id', 'to_warehouse_id', 'number', 'status',
        'dispatched_at', 'received_at', 'dispatched_by', 'received_by', 'notes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'dispatched_at' => 'immutable_datetime',
            'received_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return HasMany<StockTransferItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(StockTransferItem::class);
    }

    /**
     * @return BelongsTo<Warehouse, $this>
     */
    public function fromWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'from_warehouse_id');
    }

    /**
     * @return BelongsTo<Warehouse, $this>
     */
    public function toWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'to_warehouse_id');
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isDispatched(): bool
    {
        return $this->status === self::STATUS_DISPATCHED;
    }

    /**
     * Goods are on the road: gone from the source, not yet at the destination.
     */
    public function isInTransit(): bool
    {
        return $this->isDispatched();
    }
}
