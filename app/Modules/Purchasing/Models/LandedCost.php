<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Models;

use App\Modules\Purchasing\Services\LandedCostAllocator;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Freight, customs or courier on a shipment.
 *
 * Allocated into unit costs on receipt, so profit reflects what the phone actually cost
 * to have on the shelf — not just what the supplier's invoice said.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $purchase_invoice_id
 * @property string $type
 * @property int $amount
 * @property string $allocation
 * @property string|null $description
 */
final class LandedCost extends Model
{
    use BelongsToTenant;

    public const TYPE_FREIGHT = 'freight';

    public const TYPE_CUSTOMS = 'customs';

    public const TYPE_COURIER = 'courier';

    public const TYPE_OTHER = 'other';

    protected $fillable = [
        'tenant_id', 'purchase_invoice_id', 'type', 'amount', 'allocation', 'description',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['amount' => 'integer'];
    }

    /**
     * @return BelongsTo<PurchaseInvoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(PurchaseInvoice::class, 'purchase_invoice_id');
    }

    public function isByValue(): bool
    {
        return $this->allocation === LandedCostAllocator::BY_VALUE;
    }
}
