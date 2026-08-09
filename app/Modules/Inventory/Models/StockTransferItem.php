<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Modules\Catalog\Models\ProductVariant;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of a transfer.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $stock_transfer_id
 * @property int $product_variant_id
 * @property int|null $product_unit_id
 * @property int $quantity
 * @property int|null $received_quantity
 */
final class StockTransferItem extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'stock_transfer_id', 'product_variant_id',
        'product_unit_id', 'quantity', 'received_quantity',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['quantity' => 'integer', 'received_quantity' => 'integer'];
    }

    /**
     * @return BelongsTo<StockTransfer, $this>
     */
    public function transfer(): BelongsTo
    {
        return $this->belongsTo(StockTransfer::class, 'stock_transfer_id');
    }

    /**
     * @return BelongsTo<ProductVariant, $this>
     */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    /**
     * @return BelongsTo<ProductUnit, $this>
     */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(ProductUnit::class, 'product_unit_id');
    }

    /**
     * How many went missing between the two ends. Zero when all arrived.
     */
    public function shortfall(): int
    {
        return $this->received_quantity === null ? 0 : $this->quantity - $this->received_quantity;
    }
}
