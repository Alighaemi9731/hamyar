<?php

declare(strict_types=1);

namespace App\Modules\CRM\Models;

use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Models\Branch;
use App\Support\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One side of one financial event.
 *
 * Append-only, like every ledger in this system. A mistake is corrected by writing the
 * opposite entry — never by editing this row — so a statement shows both the error and
 * the correction, which is what lets a shop explain a balance rather than merely assert
 * it.
 *
 * Exactly one of `debit`/`credit` is non-zero, enforced by a CHECK. Both columns exist
 * rather than one signed amount because that is the layout an Iranian bookkeeper expects
 * on a printed statement.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int|null $party_id
 * @property int|null $account_id
 * @property int|null $branch_id
 * @property int $debit
 * @property int $credit
 * @property string|null $reference_type
 * @property int|null $reference_id
 * @property string $batch_id
 * @property string|null $description
 * @property int|null $actor_id
 * @property CarbonImmutable $occurred_at
 * @property CarbonImmutable $created_at
 */
final class LedgerEntry extends Model
{
    use BelongsToTenant;

    /** Append-only: there is no `updated_at` column. */
    public const UPDATED_AT = null;

    protected $fillable = [
        'tenant_id', 'party_id', 'account_id', 'branch_id', 'debit', 'credit',
        'reference_type', 'reference_id', 'batch_id', 'description', 'actor_id', 'occurred_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'debit' => 'integer',
            'credit' => 'integer',
            'occurred_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
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
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
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
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function reference(): MorphTo
    {
        return $this->morphTo('reference');
    }

    /**
     * Signed amount, for arithmetic. Positive is a debit.
     */
    public function signedAmount(): int
    {
        return $this->debit - $this->credit;
    }
}
