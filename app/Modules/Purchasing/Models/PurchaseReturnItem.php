<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Models;

use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Inventory\Models\ProductUnit;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of a return.
 *
 * `product_unit_id` is set for a handset and null for a quantity, mirroring the two
 * kinds of purchase line. `unit_cost` is copied rather than looked up: what the shop
 * gets credited is what it paid for *that* device, and the catalogue price may have
 * moved since.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $purchase_return_id
 * @property int $product_variant_id
 * @property int|null $product_unit_id
 * @property int $quantity
 * @property int $unit_cost integer RIAL
 */
final class PurchaseReturnItem extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'purchase_return_id', 'product_variant_id',
        'product_unit_id', 'quantity', 'unit_cost',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['quantity' => 'integer', 'unit_cost' => 'integer'];
    }

    /**
     * @return BelongsTo<PurchaseReturn, $this>
     */
    public function return(): BelongsTo
    {
        return $this->belongsTo(PurchaseReturn::class, 'purchase_return_id');
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

    public function lineTotal(): int
    {
        return $this->unit_cost * $this->quantity;
    }
}
