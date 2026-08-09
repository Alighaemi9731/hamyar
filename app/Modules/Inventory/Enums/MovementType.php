<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Enums;

/**
 * Why stock moved.
 *
 * Every row in `stock_movements` carries one of these plus a polymorphic reference to
 * the document that caused it, so any quantity on hand can be explained line by line.
 * "The system says 4 but there are 3 on the shelf" is answerable by reading the ledger,
 * which is the entire reason quantity is never a stored column (golden rule 3).
 */
enum MovementType: string
{
    case Purchase = 'purchase';

    case Sale = 'sale';

    /** A customer brought something back. */
    case Return = 'return';

    case TransferOut = 'transfer_out';

    case TransferIn = 'transfer_in';

    /** A correction someone made deliberately, with a reason. */
    case Adjustment = 'adjustment';

    /** The result of a stock count session. */
    case Count = 'count';

    /** A part consumed by a repair job. */
    case RepairConsume = 'repair_consume';

    /** A part booked back after a repair did not need it. */
    case RepairReturn = 'repair_return';

    case WriteOff = 'write_off';

    public function labelFa(): string
    {
        return match ($this) {
            self::Purchase => 'خرید',
            self::Sale => 'فروش',
            self::Return => 'مرجوعی',
            self::TransferOut => 'حواله خروج',
            self::TransferIn => 'حواله ورود',
            self::Adjustment => 'اصلاح',
            self::Count => 'انبارگردانی',
            self::RepairConsume => 'مصرف در تعمیر',
            self::RepairReturn => 'بازگشت از تعمیر',
            self::WriteOff => 'ضایعات',
        };
    }

    /**
     * The sign this movement type must carry.
     *
     * Returns null where either direction is legitimate: an adjustment or a count can go
     * both ways, and forcing a sign there would mean encoding the direction twice and
     * eventually disagreeing with itself.
     */
    public function requiredSign(): ?int
    {
        return match ($this) {
            self::Purchase, self::Return, self::TransferIn, self::RepairReturn => 1,
            self::Sale, self::TransferOut, self::RepairConsume, self::WriteOff => -1,
            self::Adjustment, self::Count => null,
        };
    }
}
