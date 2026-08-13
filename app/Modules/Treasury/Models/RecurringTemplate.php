<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Models;

use App\Modules\CRM\Models\Account;
use App\Modules\CRM\Models\Party;
use App\Modules\Treasury\Enums\CashDirection;
use App\Support\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Something that happens every month whether anybody remembers it or not.
 *
 * Deliberately has **no** `last_run_at`. The period is the identity — see the migration —
 * so a template is a description of what recurs, never a pointer to where a job got to.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int|null $branch_id
 * @property int $transaction_category_id
 * @property int|null $party_id
 * @property int $account_id
 * @property string $name
 * @property CashDirection $direction
 * @property int $amount
 * @property int $day_of_month
 * @property CarbonImmutable $starts_on
 * @property CarbonImmutable|null $ends_on
 * @property bool $is_active
 */
final class RecurringTemplate extends Model
{
    use BelongsToTenant;
    use SoftDeletes;

    protected $fillable = [
        'tenant_id', 'branch_id', 'transaction_category_id', 'party_id', 'account_id',
        'name', 'direction', 'amount', 'day_of_month', 'starts_on', 'ends_on', 'is_active',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'day_of_month' => 1,
        'is_active' => true,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'direction' => CashDirection::class,
            'amount' => 'integer',
            'day_of_month' => 'integer',
            'starts_on' => 'immutable_date',
            'ends_on' => 'immutable_date',
            'is_active' => 'boolean',
        ];
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
     * @return BelongsTo<Party, $this>
     */
    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }
}
