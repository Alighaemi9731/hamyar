<?php

declare(strict_types=1);

namespace App\Modules\Installments\Models;

use App\Modules\CRM\Models\Party;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Models\Branch;
use App\Modules\Sales\Models\SalesInvoice;
use App\Support\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One instalment contract.
 *
 * The stored totals are the contract's own figures — what the customer signed — and the
 * rows beneath them are what actually gets collected. They agree by construction because
 * the last row absorbs the rounding remainder; `InstallmentPlanTest` asserts it.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $branch_id
 * @property int|null $sales_invoice_id
 * @property int $party_id
 * @property int|null $guarantor_party_id
 * @property string $number
 * @property int $down_payment
 * @property int $principal
 * @property int $profit_percent
 * @property int $profit_amount
 * @property int $total_payable
 * @property int $installment_count
 * @property int $interval_months
 * @property CarbonImmutable $first_due_at
 * @property string $status
 * @property string|null $notes
 * @property int|null $created_by
 * @property-read Party $party
 * @property-read Party|null $guarantor
 * @property-read Branch $branch
 * @property-read SalesInvoice|null $invoice
 */
final class InstallmentPlan extends Model
{
    use BelongsToTenant;
    use SoftDeletes;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_SETTLED = 'settled';

    public const STATUS_DEFAULTED = 'defaulted';

    /**
     * The DB defaults, restated so a freshly created model agrees with the row it is
     * about to become — see `SalesInvoice` for the bug this prevents.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => self::STATUS_ACTIVE,
        'down_payment' => 0,
        'profit_percent' => 0,
        'profit_amount' => 0,
        'interval_months' => 1,
    ];

    protected $fillable = [
        'tenant_id', 'branch_id', 'sales_invoice_id', 'party_id', 'guarantor_party_id',
        'number', 'down_payment', 'principal', 'profit_percent', 'profit_amount',
        'total_payable', 'installment_count', 'interval_months', 'first_due_at',
        'status', 'notes', 'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'first_due_at' => 'immutable_datetime',
            'down_payment' => 'integer',
            'principal' => 'integer',
            'profit_percent' => 'integer',
            'profit_amount' => 'integer',
            'total_payable' => 'integer',
            'installment_count' => 'integer',
            'interval_months' => 'integer',
        ];
    }

    /**
     * @return HasMany<InstallmentRow, $this>
     */
    public function rows(): HasMany
    {
        return $this->hasMany(InstallmentRow::class)->orderBy('sequence');
    }

    /**
     * @return BelongsTo<Party, $this>
     */
    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    /**
     * @return BelongsTo<Party, $this>
     */
    public function guarantor(): BelongsTo
    {
        return $this->belongsTo(Party::class, 'guarantor_party_id');
    }

    /**
     * @return BelongsTo<SalesInvoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(SalesInvoice::class, 'sales_invoice_id');
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
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * What the customer pays in total, including the money already handed over.
     *
     * The down payment is not part of `total_payable` — that is the financed amount plus
     * its profit — but it is part of what the phone cost them, and the contract prints
     * both.
     */
    public function contractTotal(): int
    {
        return $this->down_payment + $this->total_payable;
    }
}
