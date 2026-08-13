<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Modules\Catalog\Models\ProductVariant;
use App\Support\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One hold on stock that has not moved.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $product_variant_id
 * @property int $warehouse_id
 * @property string $holder_type
 * @property int $holder_id
 * @property int $quantity
 * @property string $state
 * @property int|null $actor_id
 * @property CarbonImmutable|null $closed_at
 */
final class StockReservation extends Model
{
    use BelongsToTenant;

    public const STATE_ACTIVE = 'active';

    public const STATE_CONSUMED = 'consumed';

    public const STATE_RELEASED = 'released';

    /** @var array<string, mixed> */
    protected $attributes = ['state' => self::STATE_ACTIVE];

    /** @var list<string> */
    protected $fillable = [
        'tenant_id', 'product_variant_id', 'warehouse_id', 'holder_type', 'holder_id',
        'quantity', 'state', 'actor_id', 'closed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'closed_at' => 'immutable_datetime',
        ];
    }

    /**
     * Still holding stock. The only state that reduces what a till may sell.
     *
     * @param  Builder<StockReservation>  $query
     * @return Builder<StockReservation>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('state', self::STATE_ACTIVE);
    }

    /**
     * @return BelongsTo<ProductVariant, $this>
     */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function holder(): MorphTo
    {
        return $this->morphTo();
    }
}
