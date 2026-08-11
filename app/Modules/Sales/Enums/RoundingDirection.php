<?php

declare(strict_types=1);

namespace App\Modules\Sales\Enums;

/**
 * Which way a rounded total moves.
 *
 * A commercial decision, not an arithmetic one, which is why it is a shop setting and
 * not a constant. Iranian counters routinely round the last few hundred rial away
 * rather than hand over coins nobody carries — but *which* way they round is the shop's
 * choice, and it is worth real money over a year.
 */
enum RoundingDirection: string
{
    /** Exact totals. Every rial charged is a rial computed. */
    case None = 'none';

    /** Nearest step. Sometimes in the shop's favour, sometimes the customer's. */
    case Nearest = 'nearest';

    /** Always toward the customer — the shop absorbs the difference. */
    case Down = 'down';

    /** Always toward the shop. Legal, and the most likely to be noticed. */
    case Up = 'up';

    public function labelFa(): string
    {
        return match ($this) {
            self::None => 'بدون گرد کردن',
            self::Nearest => 'به نزدیک‌ترین',
            self::Down => 'به نفع مشتری (رو به پایین)',
            self::Up => 'رو به بالا',
        };
    }
}
