<?php

declare(strict_types=1);

namespace App\Modules\CRM\Models;

use App\Modules\Identity\Models\User;
use App\Support\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A promise to get back to someone.
 *
 * `done_at` is a timestamp rather than a boolean because "when was this dealt with" is
 * the question that actually gets asked — by the owner, about last week.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $party_id
 * @property string $title
 * @property string|null $body
 * @property CarbonImmutable $due_at
 * @property int|null $assignee_id
 * @property int|null $created_by
 * @property CarbonImmutable|null $done_at
 * @property int|null $done_by
 * @property-read Party $party
 */
final class PartyFollowUp extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'party_id', 'title', 'body', 'due_at',
        'assignee_id', 'created_by', 'done_at', 'done_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'due_at' => 'immutable_datetime',
            'done_at' => 'immutable_datetime',
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
     * @return BelongsTo<User, $this>
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    public function isDone(): bool
    {
        return $this->done_at !== null;
    }

    /**
     * Past its due date and still not done.
     *
     * Deliberately not a stored flag: it changes by the passage of time, and a column
     * that only becomes true when something writes to it would be wrong every night.
     */
    public function isOverdue(?CarbonImmutable $now = null): bool
    {
        return ! $this->isDone() && $this->due_at->isBefore($now ?? CarbonImmutable::now());
    }

    /**
     * @param  Builder<PartyFollowUp>  $query
     * @return Builder<PartyFollowUp>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('done_at');
    }
}
