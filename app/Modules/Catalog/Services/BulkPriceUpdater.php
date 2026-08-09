<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Services;

use App\Modules\Catalog\Models\ProductVariant;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

/**
 * Change many prices at once, with a preview that is the same computation as the apply.
 *
 * Iranian prices move weekly, so this screen gets used constantly — and a bulk price
 * tool that surprises anyone is worse than no bulk price tool. The guarantee is that
 * `preview()` and `apply()` share one code path: the preview is the apply, stopped
 * before the write. There is no second implementation to drift.
 *
 * Rounding is to whole rial and always **down**, so a percentage rise never lands a
 * fraction above what the shop intended.
 */
final class BulkPriceUpdater
{
    public const MODE_PERCENT = 'percent';

    public const MODE_AMOUNT = 'amount';

    public const MODE_SET = 'set';

    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly PriceResolver $prices,
    ) {}

    /**
     * What would change, without changing it.
     *
     * @param  Builder<ProductVariant>  $variants  already filtered by category/brand/etc
     * @return array{
     *     rows: list<array{variant_id: int, name: string, from: int|null, to: int}>,
     *     unchanged: int,
     *     skipped: int
     * }
     */
    public function preview(Builder $variants, int $priceLevelId, string $mode, int $value): array
    {
        $this->guardMode($mode, $value);

        $rows = [];
        $unchanged = 0;
        $skipped = 0;

        foreach ($variants->with('product')->get() as $variant) {
            /** @var int $variantId */
            $variantId = $variant->getKey();

            $current = $this->prices->priceFor($variantId, $priceLevelId);

            $new = $this->target($current, $mode, $value);

            if ($new === null) {
                // A percentage of nothing is nothing. Rather than inventing a price for
                // a variant that has never had one, it is reported as skipped so the
                // operator can see the gap instead of discovering it at the till.
                $skipped++;

                continue;
            }

            if ($new === $current) {
                $unchanged++;

                continue;
            }

            $rows[] = [
                'variant_id' => $variantId,
                'name' => $variant->displayName(),
                'from' => $current,
                'to' => $new,
            ];
        }

        return ['rows' => $rows, 'unchanged' => $unchanged, 'skipped' => $skipped];
    }

    /**
     * Apply exactly what `preview()` showed.
     *
     * Takes the preview's rows rather than re-deriving them, so an operator cannot
     * approve one set of changes and have another set applied because a price moved in
     * between.
     *
     * @param  list<array{variant_id: int, name: string, from: int|null, to: int}>  $rows
     * @return int number of prices written
     */
    public function apply(array $rows, int $priceLevelId, ?CarbonImmutable $from = null): int
    {
        $from ??= CarbonImmutable::now();

        /** @var int $written */
        $written = $this->connection->transaction(function () use ($rows, $priceLevelId, $from): int {
            foreach ($rows as $row) {
                $this->prices->setPrice($row['variant_id'], $priceLevelId, $row['to'], $from);
            }

            return count($rows);
        });

        return $written;
    }

    /**
     * The new price, or null when there is nothing to base it on.
     */
    private function target(?int $current, string $mode, int $value): ?int
    {
        if ($mode === self::MODE_SET) {
            return max(0, $value);
        }

        if ($current === null) {
            return null;
        }

        $new = match ($mode) {
            // intdiv truncates, so a rise lands at or just below the exact figure.
            self::MODE_PERCENT => $current + intdiv($current * $value, 100),
            default => $current + $value,
        };

        // A -100% or an over-large decrease must not produce a negative price, which
        // would read downstream as the shop owing the customer.
        return max(0, $new);
    }

    private function guardMode(string $mode, int $value): void
    {
        if (! in_array($mode, [self::MODE_PERCENT, self::MODE_AMOUNT, self::MODE_SET], true)) {
            throw new InvalidArgumentException("Unknown bulk price mode [{$mode}].");
        }

        if ($mode === self::MODE_SET && $value < 0) {
            throw new InvalidArgumentException('A fixed price cannot be negative.');
        }
    }
}
