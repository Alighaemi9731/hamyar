<?php

declare(strict_types=1);

namespace App\Modules\Installments\Models;

use App\Modules\CRM\Models\Account;
use App\Modules\Identity\Models\User;
use App\Support\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One payment taken against one instalment.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int|null $branch_id
 * @property int $installment_row_id
 * @property int $installment_plan_id
 * @property int $account_id
 * @property int $amount
 * @property int $fee_part
 * @property int $profit_part
 * @property int $principal_part
 * @property int $unapplied
 * @property string $method
 * @property string|null $reference
 * @property CarbonImmutable $occurred_at
 * @property int|null $actor_id
 */
final class InstallmentCollection extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'branch_id', 'installment_row_id', 'installment_plan_id', 'account_id',
        'amount', 'fee_part', 'profit_part', 'principal_part', 'unapplied',
        'method', 'reference', 'occurred_at', 'actor_id',
    ];

    /**
     * Defaults in memory as well as in the database — a null reaching integer arithmetic
     * is how a receipt's parts silently stop summing to its total.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'fee_part' => 0,
        'profit_part' => 0,
        'principal_part' => 0,
        'unapplied' => 0,
        'method' => 'cash',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'fee_part' => 'integer',
            'profit_part' => 'integer',
            'principal_part' => 'integer',
            'unapplied' => 'integer',
            'occurred_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<InstallmentRow, $this>
     */
    public function row(): BelongsTo
    {
        return $this->belongsTo(InstallmentRow::class, 'installment_row_id');
    }

    /**
     * @return BelongsTo<InstallmentPlan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(InstallmentPlan::class, 'installment_plan_id');
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

    /**
     * What this payment actually settled, ignoring any overpayment.
     */
    public function settledPart(): int
    {
        return $this->fee_part + $this->profit_part + $this->principal_part;
    }
}
