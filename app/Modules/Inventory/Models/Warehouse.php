<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A place stock sits.
 *
 * Several per branch is the normal case — shop floor, back room, repair bench — and the
 * distinction is load-bearing rather than organisational: `is_sellable` is false for a
 * repair bench, so parts committed to a job never show up as available to the till.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $branch_id
 * @property string $name
 * @property bool $is_sellable
 * @property bool $allows_negative_stock
 * @property bool $is_default
 * @property bool $is_active
 * @property-read Branch $branch
 */
final class Warehouse extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<\Database\Factories\WarehouseFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $fillable = [
        'tenant_id', 'branch_id', 'name',
        'is_sellable', 'allows_negative_stock', 'is_default', 'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_sellable' => 'boolean',
            'allows_negative_stock' => 'boolean',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * @param  Builder<Warehouse>  $query
     * @return Builder<Warehouse>
     */
    public function scopeSellable(Builder $query): Builder
    {
        return $query->where('is_active', true)->where('is_sellable', true);
    }
}
