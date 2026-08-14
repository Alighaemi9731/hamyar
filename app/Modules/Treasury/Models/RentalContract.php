<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Models;

use App\Modules\CRM\Models\Account;
use App\Modules\CRM\Models\Party;
use App\Support\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * قرارداد اجاره — a desk or a corner of the shop, leased to somebody.
 *
 * Common in Iranian mobile bazaars: the shop rents its repair bench to a technician, or a
 * metre of counter to an accessories seller, and that rent is real income the P&L has to
 * show. It is a document somebody signs, which is why it is a contract rather than a
 * recurring template with a party bolted on.
 *
 * `deposit` (ودیعه) is held, not earned. It is recorded here so the shop knows what it
 * owes back at the end, and it is deliberately NOT generated as income — booking a deposit
 * as revenue overstates the month it arrives in and understates the month it is returned.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int|null $branch_id
 * @property int $party_id
 * @property int $transaction_category_id
 * @property int $account_id
 * @property string $number
 * @property string $title
 * @property int $monthly_amount
 * @property int $deposit
 * @property int $due_day
 * @property CarbonImmutable $starts_on
 * @property CarbonImmutable|null $ends_on
 * @property CarbonImmutable|null $terminated_on
 * @property string|null $notes
 */
final class RentalContract extends Model
{
    use BelongsToTenant;
    use SoftDeletes;

    protected $fillable = [
        'tenant_id', 'branch_id', 'party_id', 'transaction_category_id', 'account_id',
        'number', 'title', 'monthly_amount', 'deposit', 'due_day',
        'starts_on', 'ends_on', 'terminated_on', 'notes',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'deposit' => 0,
        'due_day' => 1,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'monthly_amount' => 'integer',
            'deposit' => 'integer',
            'due_day' => 'integer',
            'starts_on' => 'immutable_date',
            'ends_on' => 'immutable_date',
            'terminated_on' => 'immutable_date',
        ];
    }

    /**
     * @return BelongsTo<Party, $this>
     */
    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    /**
     * @return BelongsTo<TransactionCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(TransactionCategory::class, 'transaction_category_id');
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * Still running on a given day.
     *
     * Termination beats the end date: a contract ended early stops earning on the day it
     * was terminated, not on the day the paper said it would finish.
     */
    public function isLiveOn(CarbonImmutable $date): bool
    {
        if ($this->starts_on->greaterThan($date)) {
            return false;
        }

        if ($this->terminated_on !== null && $this->terminated_on->lessThan($date)) {
            return false;
        }

        return $this->ends_on === null || ! $this->ends_on->lessThan($date);
    }
}
