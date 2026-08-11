<?php

declare(strict_types=1);

namespace App\Modules\Sales\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One returned line.
 *
 * Points at the invoice line rather than the product, so a partial return knows which
 * price was actually charged — the customer gets back what they paid, not what the
 * item costs today.
 *
 * `regrade` is the cosmetic grade the handset comes back with. It is asked for rather
 * than assumed: a phone returned after two weeks is rarely the grade it left as, and
 * putting it back on the shelf at its old grade is how the next customer is misled.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $sales_return_id
 * @property int $sales_invoice_item_id
 * @property int $quantity
 * @property int $refund_amount
 * @property string|null $regrade
 */
final class SalesReturnItem extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'sales_return_id', 'sales_invoice_item_id',
        'quantity', 'refund_amount', 'regrade',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['quantity' => 'integer', 'refund_amount' => 'integer'];
    }

    /**
     * @return BelongsTo<SalesReturn, $this>
     */
    public function return(): BelongsTo
    {
        return $this->belongsTo(SalesReturn::class, 'sales_return_id');
    }

    /**
     * @return BelongsTo<SalesInvoiceItem, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(SalesInvoiceItem::class, 'sales_invoice_item_id');
    }
}
