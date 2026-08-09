<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Models;

use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Inventory\Models\ProductUnit;
use App\Support\Imei;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One handset on an incoming shipment.
 *
 * Not yet a `ProductUnit` — that is created on receipt. Until then this is what the
 * operator typed, which is why the IMEI here is nullable and un-triggered: a draft may
 * legitimately be half-filled.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $purchase_invoice_id
 * @property int $product_variant_id
 * @property string|null $imei1
 * @property string|null $imei2
 * @property string|null $serial
 * @property string $condition
 * @property string|null $grade
 * @property int $unit_cost
 * @property int $landed_allocation
 * @property int|null $product_unit_id
 */
final class PurchaseUnitItem extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'purchase_invoice_id', 'product_variant_id',
        'imei1', 'imei2', 'serial', 'condition', 'grade',
        'unit_cost', 'landed_allocation', 'product_unit_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['unit_cost' => 'integer', 'landed_allocation' => 'integer'];
    }

    protected static function booted(): void
    {
        // Normalised here as well as on ProductUnit, so a draft can be searched and
        // de-duplicated before it is ever received.
        self::saving(function (PurchaseUnitItem $item): void {
            foreach (['imei1', 'imei2'] as $column) {
                $value = $item->getAttribute($column);

                if (is_string($value) && $value !== '') {
                    $item->setAttribute($column, Imei::normalise($value));
                }
            }
        });
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
     * The device this intake row became, once received.
     *
     * @return BelongsTo<ProductUnit, $this>
     */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(ProductUnit::class, 'product_unit_id');
    }

    public function trueUnitCost(): int
    {
        return $this->unit_cost + $this->landed_allocation;
    }
}
