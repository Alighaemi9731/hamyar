<?php

declare(strict_types=1);

namespace App\Modules\Cheques\Models;

use App\Modules\Cheques\Enums\ChequeDirection;
use App\Modules\Cheques\Enums\ChequeStatus;
use App\Modules\CRM\Models\Account;
use App\Modules\CRM\Models\Party;
use App\Support\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One piece of paper.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int|null $branch_id
 * @property ChequeDirection $direction
 * @property ChequeStatus $status
 * @property int $party_id
 * @property int|null $endorsed_to_party_id
 * @property int|null $account_id
 * @property int $amount
 * @property int $recovered_amount
 * @property string $bank_name
 * @property string $serial
 * @property string|null $sayad_id
 * @property CarbonImmutable $due_date
 * @property CarbonImmutable|null $received_at
 * @property CarbonImmutable|null $deposited_at
 * @property CarbonImmutable|null $cleared_at
 * @property CarbonImmutable|null $bounced_at
 * @property string|null $bounce_reason
 * @property int $presentation_attempt
 */
final class Cheque extends Model
{
    use BelongsToTenant;
    use SoftDeletes;

    protected $fillable = [
        'tenant_id', 'branch_id', 'direction', 'status', 'party_id', 'endorsed_to_party_id',
        'account_id', 'amount', 'recovered_amount', 'bank_name', 'branch_name', 'serial',
        'sayad_id', 'account_holder', 'due_date', 'received_at', 'deposited_at',
        'cleared_at', 'bounced_at', 'bounce_reason', 'presentation_attempt',
        'reference_type', 'reference_id', 'notes', 'actor_id',
    ];

    /**
     * Column defaults in memory as well as in the database.
     *
     * A freshly created model otherwise reads `null` for `recovered_amount` until it is
     * reloaded, and null reaching integer arithmetic is how a shortfall silently becomes
     * the whole face value. The same omission cost a Z report its cash figure in Phase 5.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'in_hand',
        'recovered_amount' => 0,
        'presentation_attempt' => 1,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'direction' => ChequeDirection::class,
            'status' => ChequeStatus::class,
            'amount' => 'integer',
            'recovered_amount' => 'integer',
            'presentation_attempt' => 'integer',
            'due_date' => 'immutable_date',
            'received_at' => 'immutable_datetime',
            'deposited_at' => 'immutable_datetime',
            'cleared_at' => 'immutable_datetime',
            'bounced_at' => 'immutable_datetime',
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
     * @return BelongsTo<Party, $this>
     */
    public function endorsedTo(): BelongsTo
    {
        return $this->belongsTo(Party::class, 'endorsed_to_party_id');
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * @return HasMany<ChequeEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(ChequeEvent::class);
    }

    public function isReceived(): bool
    {
        return $this->direction === ChequeDirection::Received;
    }

    /**
     * What is still at risk on this cheque.
     *
     * The face value, less anything a bank actually paid on a partial settlement. This is
     * the figure the credit check adds to a party's balance — see `docs/specs/cheques.md`
     * on why a zero party balance is not the same as nothing owing.
     */
    public function outstanding(): int
    {
        return max(0, $this->amount - $this->recovered_amount);
    }
}
