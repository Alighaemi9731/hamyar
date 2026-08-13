<?php

declare(strict_types=1);

namespace App\Modules\Repairs\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The device's condition at drop-off, one line per checked item.
 *
 * Stored per answer rather than as a paragraph somebody typed, because this is the
 * record that settles «صفحه از قبل شکسته بود» three weeks later. A paraphrase in a notes
 * field is not evidence; a dated row saying the screen was recorded as cracked is.
 *
 * `label` is copied from the tenant's template rather than joined to it — the template
 * gets edited, and an answer that silently re-labels itself would rewrite what the
 * customer signed.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $repair_ticket_id
 * @property string $item_key
 * @property string $label
 * @property string $answer
 * @property string|null $note
 * @property int $position
 */
final class TicketChecklistAnswer extends Model
{
    use BelongsToTenant;

    /** @var list<string> */
    protected $fillable = [
        'tenant_id', 'repair_ticket_id', 'item_key', 'label', 'answer', 'note', 'position',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['position' => 'integer'];
    }

    /**
     * @return BelongsTo<RepairTicket, $this>
     */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(RepairTicket::class, 'repair_ticket_id');
    }
}
