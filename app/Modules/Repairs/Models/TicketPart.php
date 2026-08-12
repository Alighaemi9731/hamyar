<?php

declare(strict_types=1);

namespace App\Modules\Repairs\Models;

use App\Modules\Catalog\Models\ProductVariant;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One part on a repair.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $repair_ticket_id
 * @property int $product_variant_id
 * @property int $warehouse_id
 * @property int|null $stock_reservation_id
 * @property int $quantity
 * @property int $unit_cost
 * @property int $unit_price
 * @property string $state
 * @property int|null $actor_id
 */
final class TicketPart extends Model
{
    use BelongsToTenant;

    public const STATE_RESERVED = 'reserved';

    public const STATE_CONSUMED = 'consumed';

    public const STATE_RETURNED = 'returned';

    /** @var array<string, mixed> */
    protected $attributes = [
        'state' => self::STATE_RESERVED,
        'unit_cost' => 0,
        'unit_price' => 0,
    ];

    /** @var list<string> */
    protected $fillable = [
        'tenant_id', 'repair_ticket_id', 'product_variant_id', 'warehouse_id',
        'stock_reservation_id', 'quantity', 'unit_cost', 'unit_price', 'state', 'actor_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_cost' => 'integer',
            'unit_price' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<RepairTicket, $this>
     */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(RepairTicket::class, 'repair_ticket_id');
    }

    /**
     * @return BelongsTo<ProductVariant, $this>
     */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }
}
