<?php

declare(strict_types=1);

namespace App\Modules\Sales\Models;

use App\Modules\CRM\Models\Party;
use App\Modules\Identity\Models\User;
use App\Support\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Goods coming back over the counter.
 *
 * A document of its own, never an edit of the sale — the sale did happen, and the
 * return is a second event with its own date. Collapsing the two would rewrite a month
 * that may already be closed and leave the stock movement with nothing to explain it.
 * The same reasoning as a purchase return.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $sales_invoice_id
 * @property int|null $party_id
 * @property string $number
 * @property int $total
 * @property string|null $reason
 * @property CarbonImmutable $returned_at
 * @property int|null $actor_id
 */
final class SalesReturn extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'sales_invoice_id', 'party_id', 'number',
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
     * @return BelongsTo<SalesInvoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(SalesInvoice::class, 'sales_invoice_id');
    }

    /**
     * @return HasMany<SalesReturnItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(SalesReturnItem::class);
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
