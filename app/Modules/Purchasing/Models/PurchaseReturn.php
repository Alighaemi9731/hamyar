<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Models;

use App\Modules\CRM\Models\Party;
use App\Modules\Identity\Models\User;
use App\Support\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Goods sent back to the supplier.
 *
 * A document in its own right, never an edit of the purchase it came from. The
 * shipment did arrive; the return is a second event with its own date, and collapsing
 * the two would rewrite a month that may already be closed and leave the stock
 * movement with nothing to explain it.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $purchase_invoice_id
 * @property int|null $party_id
 * @property string $number
 * @property int $total integer RIAL
 * @property string|null $reason
 * @property CarbonImmutable $returned_at
 * @property int|null $actor_id
 */
final class PurchaseReturn extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'purchase_invoice_id', 'party_id', 'number',
        'total', 'reason', 'returned_at', 'actor_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['total' => 'integer', 'returned_at' => 'immutable_datetime'];
    }

    /**
     * @return BelongsTo<PurchaseInvoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(PurchaseInvoice::class, 'purchase_invoice_id');
    }

    /**
     * @return HasMany<PurchaseReturnItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(PurchaseReturnItem::class);
    }

    /**
     * @return BelongsTo<Party, $this>
     */
    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
