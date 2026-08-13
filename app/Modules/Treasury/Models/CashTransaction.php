<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Models;

use App\Modules\CRM\Models\Account;
use App\Modules\CRM\Models\Party;
use App\Modules\Identity\Models\User;
use App\Modules\Treasury\Enums\CashDirection;
use App\Support\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One payment out, or one receipt in that is not a sale.
 *
 * `amount` is always positive; `direction` carries the sign. Storing a signed amount would
 * mean every report has to remember which way is which, and the first one that forgets
 * reports a month of rent as income.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int|null $branch_id
 * @property int $transaction_category_id
 * @property int|null $recurring_template_id
 * @property int|null $rental_contract_id
 * @property int|null $party_id
 * @property int $account_id
 * @property CashDirection $direction
 * @property int $amount
 * @property string|null $description
 * @property string|null $reference
 * @property string|null $generated_key
 * @property CarbonImmutable $occurred_at
 * @property int|null $actor_id
 * @property-read TransactionCategory $category
 */
final class CashTransaction extends Model
{
    use BelongsToTenant;
    use SoftDeletes;

    protected $fillable = [
        'tenant_id', 'branch_id', 'transaction_category_id', 'recurring_template_id',
        'rental_contract_id', 'party_id', 'account_id', 'direction', 'amount',
        'description', 'reference', 'generated_key', 'occurred_at', 'actor_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'direction' => CashDirection::class,
            'amount' => 'integer',
            'occurred_at' => 'immutable_datetime',
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

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
