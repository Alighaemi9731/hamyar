<?php

declare(strict_types=1);

namespace App\Modules\Cheques\Models;

use App\Modules\Cheques\Enums\ChequeStatus;
use App\Modules\Identity\Models\User;
use App\Support\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One thing that happened to a cheque, and the ledger batch it posted.
 *
 * Append-only. `batch_id` is what lets a statement show the event as a unit and a
 * correction find every row it must undo — and it is null for the transitions that
 * deliberately post nothing, which is a fact worth being able to see rather than infer.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $cheque_id
 * @property ChequeStatus|null $from_status
 * @property ChequeStatus $to_status
 * @property string|null $batch_id
 * @property int $amount
 * @property string|null $note
 * @property CarbonImmutable $occurred_at
 * @property int|null $actor_id
 */
final class ChequeEvent extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    protected $fillable = [
        'tenant_id', 'cheque_id', 'from_status', 'to_status', 'batch_id',
        'amount', 'note', 'occurred_at', 'actor_id',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'amount' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'from_status' => ChequeStatus::class,
            'to_status' => ChequeStatus::class,
            'amount' => 'integer',
            'occurred_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Cheque, $this>
     */
    public function cheque(): BelongsTo
    {
        return $this->belongsTo(Cheque::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
