<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Models;

use App\Modules\Catalog\Enums\ProductType;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A thing the shop sells, before variants.
 *
 * "iPhone 15 Pro" is a product; "iPhone 15 Pro, black, 256GB" is a variant. Stock,
 * barcodes and prices all hang off the variant, never the product — a shop counts black
 * 256GB units, not iPhones in general.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int|null $category_id
 * @property int|null $brand_id
 * @property string $name
 * @property string|null $sku
 * @property ProductType $type
 * @property string|null $description
 * @property int|null $low_stock_threshold
 * @property bool $is_active
 */
final class Product extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<\Database\Factories\ProductFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $fillable = [
        'tenant_id', 'category_id', 'brand_id', 'name', 'sku',
        'type', 'description', 'low_stock_threshold', 'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => ProductType::class,
            'is_active' => 'boolean',
            'low_stock_threshold' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * @return BelongsTo<Brand, $this>
     */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /**
     * @return HasMany<ProductVariant, $this>
     */
    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class);
    }

    public function isSerialized(): bool
    {
        return $this->type->tracksUnits();
    }

    /**
     * @param  Builder<Product>  $query
     * @return Builder<Product>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
