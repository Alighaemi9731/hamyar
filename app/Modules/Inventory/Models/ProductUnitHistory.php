<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Enums\UnitStatus;
use App\Support\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One line of a device's life story. Append-only.
 *
 * `updated_at` deliberately does not exist: a history row that can be edited is not
 * history. The table has `created_at` only, and nothing in the application updates or
 * deletes these rows.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $product_unit_id
 * @property UnitStatus|null $from_status
 * @property UnitStatus $to_status
 * @property int|null $actor_id
 * @property string|null $reference_type
 * @property int|null $reference_id
 * @property string|null $note
 * @property CarbonImmutable $created_at
 */
final class ProductUnitHistory extends Model
{
    use BelongsToTenant;

    /** Append-only: there is no `updated_at` column to maintain. */
    public const UPDATED_AT = null;

    protected $fillable = [
        'tenant_id', 'product_unit_id', 'from_status', 'to_status',
        'actor_id', 'reference_type', 'reference_id', 'note',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'from_status' => UnitStatus::class,
            'to_status' => UnitStatus::class,
            'created_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<ProductUnit, $this>
     */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(ProductUnit::class, 'product_unit_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /**
     * The document that caused this transition — a purchase, a sale, a repair ticket.
     *
     * @return MorphTo<Model, $this>
     */
    public function reference(): MorphTo
    {
        return $this->morphTo('reference');
    }
}
