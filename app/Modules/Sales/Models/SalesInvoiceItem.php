<?php

declare(strict_types=1);

namespace App\Modules\Sales\Models;

use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Inventory\Models\ProductUnit;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line: a quantity of something, or one specific handset.
 *
 * `description` is copied rather than looked up. A product renamed next year must not
 * rewrite what this invoice says it sold — the same reason `unit_price` is stored and
 * not re-derived from the price list.
 *
 * `cost_snapshot` is written at finalisation and never recomputed. It is what makes
 * profit answerable months later: for a handset it is that device's own purchase cost
 * (specific identity), for a standard line the weighted average at the moment of sale.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $sales_invoice_id
 * @property int|null $product_variant_id
 * @property int|null $product_unit_id
 * @property string $description
 * @property int $quantity
 * @property int $unit_price
 * @property int $discount_amount
 * @property int $vat_rate
 * @property int $vat_amount
 * @property int $line_total
 * @property int $cost_snapshot
 * @property int|null $warranty_months
 */
final class SalesInvoiceItem extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'sales_invoice_id', 'product_variant_id', 'product_unit_id',
        'description', 'quantity', 'unit_price', 'discount_amount',
        'vat_rate', 'vat_amount', 'line_total', 'cost_snapshot', 'warranty_months',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_price' => 'integer',
            'discount_amount' => 'integer',
            'vat_rate' => 'integer',
            'vat_amount' => 'integer',
            'line_total' => 'integer',
            'cost_snapshot' => 'integer',
            'warranty_months' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<SalesInvoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(SalesInvoice::class, 'sales_invoice_id');
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
     * A line selling one identified handset rather than a quantity.
     */
    public function isSerialized(): bool
    {
        return $this->product_unit_id !== null;
    }

    /**
     * Profit on this line, in rial.
     *
     * Uses the snapshot, never a current cost — which is the entire point of storing
     * one. Excludes VAT: tax collected on the shop's behalf was never the shop's margin.
     */
    public function profit(): int
    {
        return $this->line_total - $this->vat_amount - ($this->cost_snapshot * $this->quantity);
    }
}
