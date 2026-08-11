<?php

declare(strict_types=1);

namespace App\Modules\CRM\Models;

use App\Modules\Identity\Models\User;
use App\Support\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Something that happened with this party, dated and attributed.
 *
 * Distinct from `parties.notes`, which is the standing description of who someone is.
 * This is the conversation: "گفت هفته آینده برای گارانتی می‌آید".
 *
 * Append-only — there is no `updated_at` to maintain, and nothing in the application
 * edits one. A dated note that can be rewritten is not a record of what was said.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $party_id
 * @property string $body
 * @property int|null $author_id
 * @property CarbonImmutable $created_at
 */
final class PartyNote extends Model
{
    use BelongsToTenant;

    /** Append-only: there is no `updated_at` column. */
    public const UPDATED_AT = null;

    protected $fillable = ['tenant_id', 'party_id', 'body', 'author_id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['created_at' => 'immutable_datetime'];
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
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }
}
