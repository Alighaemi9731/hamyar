<?php

declare(strict_types=1);

namespace App\Modules\Cheques\Enums;

/**
 * Whose paper it is.
 *
 * Received: a customer gave it to the shop. Issued: the shop wrote it to a supplier.
 * The lifecycles mirror each other and are deliberately asymmetric in one place — see
 * `docs/specs/cheques.md` on why `presented` posts nothing.
 */
enum ChequeDirection: string
{
    case Received = 'received';

    case Issued = 'issued';

    public function labelFa(): string
    {
        return match ($this) {
            self::Received => 'دریافتی',
            self::Issued => 'پرداختی',
        };
    }
}
