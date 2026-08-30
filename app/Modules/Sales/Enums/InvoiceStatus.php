<?php

declare(strict_types=1);

namespace App\Modules\Sales\Enums;

/**
 * Where a sales document is in its life.
 *
 * Three states and two transitions, deliberately. Anything richer — "pending",
 * "confirmed", "delivered" — belongs to the thing being sold, not to the piece of
 * paper, and a status column that tries to track both ends up meaning neither.
 */
enum InvoiceStatus: string
{
    /** A basket someone is still filling. No number, no stock effect, no ledger. */
    case Draft = 'draft';

    /** Issued. Numbered, stock moved, money accounted for. */
    case Final = 'final';

    /**
     * Cancelled after issue. Every effect reversed, the **number kept**.
     *
     * A tax invoice number that disappears is a gap somebody has to explain, so the row
     * survives and says what happened to it.
     */
    case Void = 'void';

    public function labelFa(): string
    {
        return match ($this) {
            self::Draft => 'پیش‌نویس',
            self::Final => 'نهایی',
            self::Void => 'ابطال‌شده',
        };
    }
}
