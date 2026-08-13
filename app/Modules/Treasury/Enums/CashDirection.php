<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Enums;

/**
 * Money out, or money in that is not a sale.
 *
 * One enum rather than two mirror types, for the same reason `cash_transactions` is one
 * table: an expense and an income are the same event with the arrows reversed, and
 * splitting them gives the posting logic two places to drift apart in.
 */
enum CashDirection: string
{
    case Expense = 'expense';

    case Income = 'income';

    public function labelFa(): string
    {
        return match ($this) {
            self::Expense => 'هزینه',
            self::Income => 'درآمد',
        };
    }

    /**
     * Does money leave the shop's accounts?
     */
    public function isOutgoing(): bool
    {
        return $this === self::Expense;
    }
}
