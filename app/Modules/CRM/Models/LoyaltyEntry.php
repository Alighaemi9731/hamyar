<?php

declare(strict_types=1);

namespace App\Modules\CRM\Models;

use App\Modules\Identity\Models\User;
use App\Support\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One movement of loyalty points. Append-only.
 *
 * Golden rule 3, applied to points: a party's balance is `SUM(points)` over this table
 * and never a stored column. Expiry writes a negative entry rather than deleting the
 * positive one, so a customer asking why their points vanished can be shown the line
 * that took them.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $party_id
 * @property int $points signed — positive earns, negative redeems or expires
 * @property string $reason
 * @property string|null $description
 * @property string|null $reference_type
 * @property int|null $reference_id
 * @property int|null $actor_id
 * @property CarbonImmutable $occurred_at
 */
final class LoyaltyEntry extends Model
{
    use BelongsToTenant;

    /** Append-only: there is no `updated_at` column. */
    public const UPDATED_AT = null;

    public const REASON_EARN = 'earn';

    public const REASON_REDEEM = 'redeem';

    public const REASON_EXPIRE = 'expire';

    /** An owner adjusting someone's points by hand, with a reason typed in. */
    public const REASON_MANUAL = 'manual';

    protected $fillable = [
        'tenant_id', 'party_id', 'points', 'reason', 'description',
        'reference_type', 'reference_id', 'actor_id', 'occurred_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'points' => 'integer',
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
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /**
     * What earned or spent the points — a sale, later a campaign.
     *
     * @return MorphTo<Model, $this>
     */
    public function reference(): MorphTo
    {
        return $this->morphTo('reference');
    }

    public function labelFa(): string
    {
        return match ($this->reason) {
            self::REASON_EARN => 'کسب امتیاز',
            self::REASON_REDEEM => 'استفاده از امتیاز',
            self::REASON_EXPIRE => 'انقضای امتیاز',
            default => 'تعدیل دستی',
        };
    }
}
