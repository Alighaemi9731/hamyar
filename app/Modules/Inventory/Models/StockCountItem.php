<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Modules\Catalog\Models\ProductVariant;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One variant on a count sheet.
 *
 * `expected_quantity` is snapshotted when the line is added, so the variance is measured
 * against what the system believed at count time rather than a figure that moved while
 * the counting was happening.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $stock_count_id
 * @property int $product_variant_id
 * @property int $expected_quantity
 * @property int|null $counted_quantity
 * @property-read ProductVariant $variant
 */
final class StockCountItem extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'stock_count_id', 'product_variant_id', 'expected_quantity', 'counted_quantity',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['expected_quantity' => 'integer', 'counted_quantity' => 'integer'];
    }

    /**
     * @return BelongsTo<StockCount, $this>
     */
    public function count(): BelongsTo
    {
        return $this->belongsTo(StockCount::class, 'stock_count_id');
    }

    /**
     * @return BelongsTo<ProductVariant, $this>
     */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    /**
     * Counted minus expected. Negative is shrinkage.
     */
    public function variance(): ?int
    {
        return $this->counted_quantity === null ? null : $this->counted_quantity - $this->expected_quantity;
    }
}
