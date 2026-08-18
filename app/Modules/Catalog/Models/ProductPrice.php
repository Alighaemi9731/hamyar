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
     * Record who moved a price, on the row that records that it moved.
     *
     * ## Why this is not `Auditable`
     *
     * The table is append-only, so a price change is an INSERT and the audit trail
     * would be a wall of `created` events carrying no comparison — the reader would
     * have to fetch the neighbouring row themselves to learn what the price *was*.
     * What an owner asks is «کی این قیمت را عوض کرد؟», and answering it needs the
     * before, the after and the person in one row.
     *
     * ## Why the subject is the variant, not this row
     *
     * A `ProductPrice` exists for exactly one moment and is never touched again, so a
     * history filtered to one would hold a single entry. The thing a shopkeeper opens
     * a history *for* is the variant — «این گوشی»: its name changed here, its barcode
     * there, its price three times since Farvardin. Pointing the entry at the variant
     * puts all of that on one screen, which is the screen they were going to look at.
     *
     * ## Why a model hook rather than PriceResolver::setPrice()
     *
     * Every write goes through `setPrice()` today — the grid, the bulk tool and the
     * importer all call it. A hook here stays correct when the fourth one does not,
     * and that is the failure this codebase keeps writing down: the guard that was
     * right until somebody added a path around it.
     */
    protected static function booted(): void
    {
        self::created(function (self $price): void {
            $previous = self::query()
                ->where('product_variant_id', $price->product_variant_id)
                ->where('price_level_id', $price->price_level_id)
                ->whereKeyNot($price->getKey())
                ->orderByDesc('effective_from')
                ->orderByDesc('id')
                ->first();

            // No previous row is the opening price, not a change — logging it would
            // put one entry per variant per level into the log on every first import,
            // which is thousands of rows saying nothing happened yet. An unchanged
            // amount is the bulk tool re-applying a rounding that landed on the same
            // number; also not a change.
            if ($previous === null || $previous->price === $price->price) {
                return;
            }

            // Fetched rather than read off `$price->variant`: lazy loading is
            // disabled outside production, so the relation accessor would throw here
            // in dev and in CI while quietly N+1-ing on the one environment nobody
            // watches. One explicit query, on the row we already know the id of.
            $variant = ProductVariant::query()->find($price->product_variant_id);

            if ($variant === null) {
                return;
            }

            activity('catalog')
                ->performedOn($variant)
                ->event('price_changed')
                ->withProperties([
                    // Same shape LogsActivity writes, so the viewer renders a
                    // before/after here with the code it already has rather than a
                    // second branch for this one event.
                    'attributes' => ['price' => $price->price],
                    'old' => ['price' => $previous->price],
                    'price_level_id' => $price->price_level_id,
                    'effective_from' => $price->effective_from->toIso8601String(),
                ])
                ->log('قیمت تغییر کرد');
        });
    }

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
