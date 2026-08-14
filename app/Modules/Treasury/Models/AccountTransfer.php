<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Models;

use App\Modules\CRM\Models\Account;
use App\Modules\Identity\Models\User;
use App\Support\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One move of money between two of the shop's own accounts.
 *
 * The ledger records the consequences — a credit here, a debit there. This records the
 * act, which is what a shopkeeper asks about: how much went to the bank this month, who
 * authorised it, what the PSP charged.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $from_account_id
 * @property int $to_account_id
 * @property int $amount
 * @property int $fee
 * @property string|null $reference
 * @property CarbonImmutable $occurred_at
 * @property int|null $actor_id
 */
final class AccountTransfer extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'from_account_id', 'to_account_id', 'amount', 'fee',
        'reference', 'occurred_at', 'actor_id',
    ];

    /**
     * Column defaults, in memory as well as in the database.
     *
     * Without these a freshly created model reads `null` for `fee` until it is reloaded,
     * and `null` reaching integer arithmetic is how a total silently becomes zero. The
     * same omission cost a Z report its cash figure in Phase 5.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'fee' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'fee' => 'integer',
            'occurred_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function fromAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'from_account_id');
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function toAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'to_account_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /**
     * What actually left the source: the amount moved plus what it cost to move it.
     */
    public function totalOut(): int
    {
        return $this->amount + $this->fee;
    }
}
