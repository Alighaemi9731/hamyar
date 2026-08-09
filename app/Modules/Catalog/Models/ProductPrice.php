<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Models;

use App\Support\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One price, for one variant, at one level, from one moment.
 *
 * Append-only. A price change inserts a new row rather than updating the old one,
 * because a profit report for last month must use last month's price — and Iranian
 * prices move weekly, so "what did this cost in Mordad" is a question that gets asked.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $product_variant_id
 * @property int $price_level_id
 * @property int $price integer RIAL
 * @property CarbonImmutable $effective_from
 */
final class ProductPrice extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<\Database\Factories\ProductPriceFactory> */
    use HasFactory;

    protected $fillable = ['tenant_id', 'product_variant_id', 'price_level_id', 'price', 'effective_from'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['price' => 'integer', 'effective_from' => 'immutable_datetime'];
    }

    /**
     * @return BelongsTo<ProductVariant, $this>
     */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    /**
     * @return BelongsTo<PriceLevel, $this>
     */
    public function level(): BelongsTo
    {
        return $this->belongsTo(PriceLevel::class, 'price_level_id');
    }
}
