<?php

declare(strict_types=1);

namespace App\Modules\Sales\Models;

use App\Modules\CRM\Models\Party;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Models\Branch;
use App\Modules\Sales\Enums\InvoiceStatus;
use App\Support\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One sale, or one quote.
 *
 * Totals are stored rather than derived, and that is the opposite of the rule stock and
 * balances follow — for a reason. A quantity on hand is a *current* fact and must never
 * drift; an invoice total is a *historical* one, and it has to keep saying what the
 * customer was charged even after prices, VAT rates and discounts all change underneath
 * it. The lines that produced it are kept beside it so the arithmetic stays checkable.
 *
 * `paid_total` is a convenience for list screens. The truth is
 * `SUM(invoice_payments.amount)`, and finalisation asserts the two agree before it
 * commits.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $branch_id
 * @property int|null $party_id
 * @property int|null $salesperson_id
 * @property string|null $number
 * @property string $type
 * @property InvoiceStatus $status
 * @property CarbonImmutable|null $issued_at
 * @property CarbonImmutable|null $voided_at
 * @property int|null $voided_by
 * @property string|null $void_reason
 * @property int $subtotal
 * @property int $discount_amount
 * @property int $vat_amount
 * @property int $shipping_amount
 * @property int $rounding_adjustment
 * @property int $total
 * @property int $paid_total
 * @property array<string, mixed>|null $settings_snapshot
 * @property string|null $notes
 * @property CarbonImmutable|null $created_at
 * @property-read Branch $branch
 */
final class SalesInvoice extends Model
{
    use BelongsToTenant;
    use SoftDeletes;

    public const TYPE_INVOICE = 'invoice';

    public const TYPE_QUOTE = 'quote';

    /**
     * The column defaults, restated in PHP.
     *
     * The database already defaults all of these, but a default only applies on INSERT —
     * a freshly `create()`d model carries **null** for anything the caller did not name,
     * and every one of these is read before the row is re-fetched. That bit twice in one
     * afternoon: a null `status` made a brand-new invoice fail its own `isDraft()` check,
     * and a null `discount_amount` reached `InvoiceTotals` as an int argument.
     *
     * Stating them here makes the in-memory model agree with the row it is about to
     * become, which is what every caller already assumes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'type' => self::TYPE_INVOICE,
        'status' => 'draft',
        'subtotal' => 0,
        'discount_amount' => 0,
        'vat_amount' => 0,
        'shipping_amount' => 0,
        'rounding_adjustment' => 0,
        'total' => 0,
        'paid_total' => 0,
    ];

    protected $fillable = [
        'tenant_id', 'branch_id', 'party_id', 'salesperson_id', 'number',
        'type', 'status', 'issued_at', 'voided_at', 'voided_by', 'void_reason',
        'subtotal', 'discount_amount', 'vat_amount', 'shipping_amount',
        'rounding_adjustment', 'total',
        'paid_total', 'settings_snapshot', 'notes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => InvoiceStatus::class,
            'issued_at' => 'immutable_datetime',
            'voided_at' => 'immutable_datetime',
            'subtotal' => 'integer',
            'discount_amount' => 'integer',
            'vat_amount' => 'integer',
            'shipping_amount' => 'integer',
            'rounding_adjustment' => 'integer',
            'total' => 'integer',
            'paid_total' => 'integer',
            'settings_snapshot' => 'array',
        ];
    }

    /**
     * @return HasMany<SalesInvoiceItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(SalesInvoiceItem::class);
    }

    /**
     * @return HasMany<InvoicePayment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(InvoicePayment::class);
    }

    /**
     * @return HasMany<SalesReturn, $this>
     */
    public function returns(): HasMany
    {
        return $this->hasMany(SalesReturn::class);
    }

    /**
     * @return HasOne<TradeIn, $this>
     */
    public function tradeIn(): HasOne
    {
        return $this->hasOne(TradeIn::class);
    }

    /**
     * @return BelongsTo<Party, $this>
     */
    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function salesperson(): BelongsTo
    {
        return $this->belongsTo(User::class, 'salesperson_id');
    }

    public function isDraft(): bool
    {
        return $this->status === InvoiceStatus::Draft;
    }

    public function isFinal(): bool
    {
        return $this->status === InvoiceStatus::Final;
    }

    public function isQuote(): bool
    {
        return $this->type === self::TYPE_QUOTE;
    }

    /**
     * What is still owed on this invoice, in rial.
     *
     * Never negative: an overpayment is change handed back at the counter, not a
     * balance the shop owes, and reporting it as one would make it look collectable.
     */
    public function outstanding(): int
    {
        return max(0, $this->total - $this->paid_total);
    }

    /**
     * @param  Builder<SalesInvoice>  $query
     * @return Builder<SalesInvoice>
     */
    public function scopeIssued(Builder $query): Builder
    {
        return $query->where('status', InvoiceStatus::Final->value);
    }
}
