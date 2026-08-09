<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Models;

use App\Modules\CRM\Models\Party;
use App\Modules\Inventory\Models\Branch;
use App\Modules\Inventory\Models\Warehouse;
use App\Support\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A shipment from a supplier.
 *
 * Draft until received. While it is a draft nothing exists downstream — no stock
 * movements, no `product_units`, no ledger entry — so a half-typed shipment is not yet
 * a lie about what is on the shelf.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $branch_id
 * @property int $warehouse_id
 * @property int|null $party_id
 * @property string $number
 * @property string $status
 * @property CarbonImmutable|null $issued_at
 * @property CarbonImmutable|null $received_at
 * @property int $subtotal
 * @property int $discount
 * @property int $vat_amount
 * @property int $landed_total
 * @property int $total
 * @property string|null $notes
 * @property-read Warehouse $warehouse
 * @property-read Branch $branch
 */
final class PurchaseInvoice extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<\Database\Factories\PurchaseInvoiceFactory> */
    use HasFactory;

    use SoftDeletes;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_RECEIVED = 'received';

    public const STATUS_VOID = 'void';

    protected $fillable = [
        'tenant_id', 'branch_id', 'warehouse_id', 'party_id', 'number', 'status',
        'issued_at', 'received_at', 'subtotal', 'discount', 'vat_amount',
        'landed_total', 'total', 'notes', 'actor_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'issued_at' => 'immutable_datetime',
            'received_at' => 'immutable_datetime',
            'subtotal' => 'integer',
            'discount' => 'integer',
            'vat_amount' => 'integer',
            'landed_total' => 'integer',
            'total' => 'integer',
        ];
    }

    /**
     * @return HasMany<PurchaseInvoiceItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(PurchaseInvoiceItem::class);
    }

    /**
     * @return HasMany<PurchaseUnitItem, $this>
     */
    public function unitItems(): HasMany
    {
        return $this->hasMany(PurchaseUnitItem::class);
    }

    /**
     * @return HasMany<LandedCost, $this>
     */
    public function landedCosts(): HasMany
    {
        return $this->hasMany(LandedCost::class);
    }

    /**
     * @return BelongsTo<Party, $this>
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Party::class, 'party_id');
    }

    /**
     * @return BelongsTo<Warehouse, $this>
     */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isReceived(): bool
    {
        return $this->status === self::STATUS_RECEIVED;
    }
}
