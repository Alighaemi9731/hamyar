<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A specific, sellable configuration: colour × storage × RAM.
 *
 * The matrix values live in `options`, deliberately not `attributes` — Eloquent uses
 * `$attributes` for a model's raw column values, so that name would make
 * `$variant->attributes` return the wrong thing with no error.
 *
 * This is the unit everything else points at — stock movements, prices, serialized units
 * and invoice lines all reference a variant, because "two iPhone 15 Pro" is not a
 * fulfillable instruction and "two black 256GB" is.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $product_id
 * @property array<string, string> $options
 * @property string|null $name
 * @property string|null $sku
 * @property string|null $barcode
 * @property bool $is_active
 * @property-read Product $product
 */
final class ProductVariant extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<\Database\Factories\ProductVariantFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $fillable = ['tenant_id', 'product_id', 'options', 'name', 'sku', 'barcode', 'is_active'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['options' => 'array', 'is_active' => 'boolean'];
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return HasMany<ProductPrice, $this>
     */
    public function prices(): HasMany
    {
        return $this->hasMany(ProductPrice::class);
    }

    /**
     * What a human calls this variant.
     *
     * Falls back to the attribute values joined together — "مشکی · ۲۵۶ گیگ" — so a shop
     * that generates 6 variants from a matrix does not have to name all six.
     */
    public function displayName(): string
    {
        if (is_string($this->name) && $this->name !== '') {
            return $this->name;
        }

        $options = $this->options;

        return $options === []
            ? $this->product->name
            : implode(' · ', array_values($options));
    }

    /**
     * @param  Builder<ProductVariant>  $query
     * @return Builder<ProductVariant>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
