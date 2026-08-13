<?php

declare(strict_types=1);

namespace App\Modules\Repairs\Models;

use App\Modules\Identity\Models\User;
use App\Modules\Repairs\Enums\TicketStatus;
use App\Support\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One move, and who made it.
 *
 * Append-only, exactly like `product_unit_histories`. A repair's history is the answer
 * to the question a customer asks after three weeks — "what has actually been happening
 * to my phone?" — and it is only worth as much as its worst gap. So the state machine
 * writes status and history in one transaction, and nothing else may write status at all.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $repair_ticket_id
 * @property TicketStatus|null $from_status
 * @property TicketStatus $to_status
 * @property int|null $actor_id
 * @property string|null $note
 * @property CarbonImmutable|null $created_at
 */
final class TicketStatusHistory extends Model
{
    use BelongsToTenant;

    /** @var list<string> */
    protected $fillable = [
        'tenant_id', 'repair_ticket_id', 'from_status', 'to_status', 'actor_id', 'note',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'from_status' => TicketStatus::class,
            'to_status' => TicketStatus::class,
        ];
    }

    /**
     * @return BelongsTo<RepairTicket, $this>
     */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(RepairTicket::class, 'repair_ticket_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
