<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Enums;

/**
 * Where one physical handset is in its life.
 *
 * The transition table below is the whole point of this enum. Without it, "sold" is a
 * column anybody can write, and a device can go from `sold` straight back to `in_stock`
 * with no return document — which is how a shop ends up selling the same phone twice and
 * only finding out when the second customer calls.
 */
enum UnitStatus: string
{
    /** On the shelf, sellable. */
    case InStock = 'in_stock';

    /** Held for a named customer. Still ours, not sellable to anyone else. */
    case Reserved = 'reserved';

    case Sold = 'sold';

    /** On the repair bench — ours or a customer's, tracked either way. */
    case InRepair = 'in_repair';

    /** Came back after a sale. Needs inspection before it can be sold again. */
    case Returned = 'returned';

    /** Dead, stolen, or written off. Terminal. */
    case WrittenOff = 'written_off';

    public function labelFa(): string
    {
        return match ($this) {
            self::InStock => 'موجود',
            self::Reserved => 'رزرو',
            self::Sold => 'فروخته‌شده',
            self::InRepair => 'در تعمیر',
            self::Returned => 'مرجوعی',
            self::WrittenOff => 'ضایعات',
        };
    }

    /**
     * Counts as stock the shop owns and could sell.
     *
     * `Reserved` is owned but not available; `InRepair` is owned but not on the shelf.
     * Both matter for a stock valuation and neither may be sold, so callers have to be
     * explicit about which question they are asking.
     */
    public function isOnHand(): bool
    {
        return match ($this) {
            self::InStock, self::Reserved, self::InRepair, self::Returned => true,
            self::Sold, self::WrittenOff => false,
        };
    }

    public function isSellable(): bool
    {
        return $this === self::InStock;
    }

    /**
     * Statuses this one may legally become.
     *
     * Notably absent: `Sold → InStock`. Undoing a sale is a *return*, which produces a
     * `Returned` unit and a credit document — the money has to move back too, and a
     * silent status flip loses that entirely.
     *
     * @return list<self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::InStock => [self::Reserved, self::Sold, self::InRepair, self::WrittenOff],
            self::Reserved => [self::InStock, self::Sold, self::InRepair, self::WrittenOff],
            self::Sold => [self::Returned, self::InRepair],
            self::InRepair => [self::InStock, self::Reserved, self::Sold, self::Returned, self::WrittenOff],
            self::Returned => [self::InStock, self::InRepair, self::WrittenOff],
            // Terminal. A written-off device coming back is a new acquisition with its
            // own cost, not a resurrection of the old row.
            self::WrittenOff => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedNext(), true);
    }
}
