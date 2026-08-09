<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Enums\MovementType;
use App\Support\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One line of the quantity ledger.
 *
 * Never edited and never deleted. A mistake is corrected by writing an opposing
 * movement, which leaves both the error and the fix visible — that is what makes the
 * ledger able to explain a discrepancy instead of merely disagreeing with the shelf.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $product_variant_id
 * @property int $warehouse_id
 * @property int $quantity signed: positive in, negative out
 * @property MovementType $type
 * @property string|null $reference_type
 * @property int|null $reference_id
 * @property int $unit_cost
 * @property int|null $actor_id
 * @property string|null $note
 * @property CarbonImmutable $occurred_at
 * @property CarbonImmutable $created_at
 */
final class StockMovement extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<\Database\Factories\StockMovementFactory> */
    use HasFactory;

    /** Append-only: there is no `updated_at` column. */
    public const UPDATED_AT = null;

    protected $fillable = [
        'tenant_id', 'product_variant_id', 'warehouse_id', 'quantity', 'type',
        'reference_type', 'reference_id', 'unit_cost', 'actor_id', 'note', 'occurred_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_cost' => 'integer',
            'type' => MovementType::class,
            'occurred_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<ProductVariant, $this>
     */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    /**
     * @return BelongsTo<Warehouse, $this>
     */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
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
}
