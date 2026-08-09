<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Enums;

/**
 * The fork in the road for the whole system.
 *
 * A serialized product is never "3 in stock" — it is three rows in `product_units`, each
 * with its own IMEI, condition and purchase cost. Treating a phone as a quantity loses
 * per-unit profit and makes the IMEI passport impossible, which is the product's biggest
 * differentiator.
 */
enum ProductType: string
{
    /** Accessories and parts: a quantity in a warehouse, weighted-average cost. */
    case Standard = 'standard';

    /** Phones: one row per physical device, per-unit cost and history. */
    case Serialized = 'serialized';

    public function labelFa(): string
    {
        return match ($this) {
            self::Standard => 'کالای عادی',
            self::Serialized => 'کالای سریال‌دار',
        };
    }

    public function tracksUnits(): bool
    {
        return $this === self::Serialized;
    }
}
