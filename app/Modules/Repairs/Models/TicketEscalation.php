<?php

declare(strict_types=1);

namespace App\Modules\Repairs\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One nudge that has already gone out.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $repair_ticket_id
 * @property int $step
 * @property int $after_days
 */
final class TicketEscalation extends Model
{
    use BelongsToTenant;

    /** @var list<string> */
    protected $fillable = ['tenant_id', 'repair_ticket_id', 'step', 'after_days'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['step' => 'integer', 'after_days' => 'integer'];
    }

    /**
     * @return BelongsTo<RepairTicket, $this>
     */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(RepairTicket::class, 'repair_ticket_id');
    }
}
