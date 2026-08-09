<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Models;

use App\Modules\Catalog\Models\ProductVariant;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A standard (non-serialized) line: a quantity of something.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $purchase_invoice_id
 * @property int $product_variant_id
 * @property int $quantity
 * @property int $unit_cost
 * @property int $line_total
 * @property int $landed_allocation
 */
final class PurchaseInvoiceItem extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'purchase_invoice_id', 'product_variant_id',
        'quantity', 'unit_cost', 'line_total', 'landed_allocation',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_cost' => 'integer',
            'line_total' => 'integer',
            'landed_allocation' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<PurchaseInvoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(PurchaseInvoice::class, 'purchase_invoice_id');
    }

    /**
     * @return BelongsTo<ProductVariant, $this>
     */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    /**
     * What each unit really cost once freight and customs are spread in.
     *
     * Truncated, because a fractional rial does not exist. The rounding loss stays on
     * the invoice's landed total rather than being invented back into the cost.
     */
    public function trueUnitCost(): int
    {
        return $this->unit_cost + intdiv($this->landed_allocation, max(1, $this->quantity));
    }
}
