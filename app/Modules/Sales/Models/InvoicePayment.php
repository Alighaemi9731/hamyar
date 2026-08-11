<?php

declare(strict_types=1);

namespace App\Modules\Sales\Models;

use App\Modules\CRM\Models\Account;
use App\Modules\Identity\Models\User;
use App\Modules\Sales\Enums\PaymentMethod;
use App\Support\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One tender against one invoice.
 *
 * Several per sale is the normal case, not the exception: part cash, part card, the
 * rest on a post-dated cheque. Each row carries the evidence its method leaves behind —
 * a terminal trace number, a transfer reference, a cheque serial — because those are
 * what the shop is asked for when a payment is disputed weeks later.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $sales_invoice_id
 * @property PaymentMethod $method
 * @property int|null $account_id
 * @property int $amount
 * @property string|null $reference
 * @property int|null $cheque_id
 * @property CarbonImmutable $received_at
 * @property int|null $actor_id
 */
final class InvoicePayment extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'sales_invoice_id', 'method', 'account_id',
        'amount', 'reference', 'cheque_id', 'received_at', 'actor_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'method' => PaymentMethod::class,
            'amount' => 'integer',
            'received_at' => 'immutable_datetime',
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
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
