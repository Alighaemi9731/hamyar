<?php

declare(strict_types=1);

namespace App\Modules\CRM\Enums;

/**
 * What a party mostly is.
 *
 * A label for filtering and defaults, NOT a restriction. A party marked `Customer` can
 * still appear on a purchase invoice — in this trade the same person sells you a
 * trade-in and buys a charger in the same visit, and a data-entry dead end at the
 * counter is worse than an imprecise label.
 */
enum PartyKind: string
{
    case Customer = 'customer';

    case Supplier = 'supplier';

    /** همکار — another shop that buys at reseller prices. */
    case Colleague = 'colleague';

    case Both = 'both';

    public function labelFa(): string
    {
        return match ($this) {
            self::Customer => 'مشتری',
            self::Supplier => 'تأمین‌کننده',
            self::Colleague => 'همکار',
            self::Both => 'مشتری و تأمین‌کننده',
        };
    }
}
