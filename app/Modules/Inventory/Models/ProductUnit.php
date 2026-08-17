<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Inventory\Enums\UnitCondition;
use App\Modules\Inventory\Enums\UnitStatus;
use App\Support\Imei;
use App\Support\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One physical handset.
 *
 * Never a quantity. Three iPhones are three rows, each with its own IMEI, cost and life
 * story — which is what makes per-unit profit and the IMEI passport possible.
 *
 * Status changes go through {@see \App\Modules\Inventory\Services\UnitStateMachine},
 * never by assigning `$unit->status`. The service is what writes the history row, and a
 * transition with no history entry is a hole in the passport.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $product_variant_id
 * @property int|null $warehouse_id
 * @property string|null $imei1
 * @property string|null $imei2
 * @property string|null $serial
 * @property UnitStatus $status
 * @property UnitCondition $condition
 * @property string|null $grade
 * @property int $cost
 * @property int|null $acquired_from_party_id
 * @property CarbonImmutable|null $acquired_at
 * @property string $hamta_status
 * @property string|null $hamta_activation_id
 * @property CarbonImmutable|null $hamta_transferred_at
 * @property string|null $hamta_note
 * @property int|null $hamta_actor_id
 * @property int|null $warranty_months
 * @property CarbonImmutable|null $warranty_until
 * @property string|null $notes
 * @property-read ProductVariant $variant
 */
final class ProductUnit extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<\Database\Factories\ProductUnitFactory> */
    use HasFactory;

    use SoftDeletes;

    /*
    | Plain strings, and deliberately not `App\Modules\Hamta\Enums\HamtaStatus`.
    |
    | The column is Inventory's — it has been on this table since Phase 3 — and Hamta is
    | downstream of Inventory. Casting to that enum would point an upstream module at a
    | downstream one for the sake of three constants (golden rule 6). Hamta translates at
    | its own boundary instead, and a CHECK constraint keeps the two in step.
    */
    public const HAMTA_NOT_REQUIRED = 'not_required';

    public const HAMTA_PENDING = 'pending';

    public const HAMTA_DONE = 'done';

    protected $fillable = [
        'tenant_id', 'product_variant_id', 'warehouse_id',
        'imei1', 'imei2', 'serial',
        'status', 'condition', 'grade', 'cost',
        'acquired_from_party_id', 'acquired_at',
        'hamta_status', 'hamta_activation_id', 'hamta_transferred_at', 'hamta_note', 'hamta_actor_id',
        'warranty_months', 'warranty_until', 'notes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => UnitStatus::class,
            'condition' => UnitCondition::class,
            'cost' => 'integer',
            'acquired_at' => 'immutable_datetime',
            'warranty_until' => 'immutable_datetime',
            'hamta_transferred_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        // Normalise on the way in, always. A number typed with Persian digits and one
        // scanned from a box must land in the column identically, or the unique index
        // and every later lookup silently treat them as different devices.
        self::saving(function (ProductUnit $unit): void {
            foreach (['imei1', 'imei2'] as $column) {
                $value = $unit->getAttribute($column);

                if (is_string($value) && $value !== '') {
                    $unit->setAttribute($column, Imei::normalise($value));
                }
            }
        });
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
     * The passport, oldest first.
     *
     * @return HasMany<ProductUnitHistory, $this>
     */
    public function histories(): HasMany
    {
        return $this->hasMany(ProductUnitHistory::class)->orderBy('id');
    }

    /**
     * Find a device by either IMEI or its serial, however the digits were typed.
     *
     * One method rather than three, because the POS scan box does not know which kind of
     * code was scanned and the salesperson should not have to choose.
     *
     * @param  Builder<ProductUnit>  $query
     * @return Builder<ProductUnit>
     */
    public function scopeMatchingCode(Builder $query, string $code): Builder
    {
        $digits = Imei::normalise($code);

        return $query->where(function (Builder $q) use ($code, $digits): void {
            if ($digits !== '') {
                $q->where('imei1', $digits)->orWhere('imei2', $digits);
            }

            $q->orWhere('serial', $code);
        });
    }

    /**
     * @param  Builder<ProductUnit>  $query
     * @return Builder<ProductUnit>
     */
    public function scopeOnHand(Builder $query): Builder
    {
        return $query->whereIn('status', array_map(
            static fn (UnitStatus $status): string => $status->value,
            array_filter(UnitStatus::cases(), static fn (UnitStatus $s): bool => $s->isOnHand())
        ));
    }
}
