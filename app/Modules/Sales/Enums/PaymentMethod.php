<?php

declare(strict_types=1);

namespace App\Modules\Sales\Enums;

/**
 * How the money arrived.
 *
 * Five methods because an Iranian shop routinely splits one sale across several: part
 * cash, part card-to-card to the owner's personal account, the rest on a post-dated
 * cheque. Collapsing them into "paid" loses the cash-box reconciliation, the trace
 * number the bank will ask for, and the cheque that has to be chased in two months.
 */
enum PaymentMethod: string
{
    case Cash = 'cash';

    /** کارتخوان — the shop's own POS terminal, settled to a bank account. */
    case PosTerminal = 'pos_terminal';

    /** کارت به کارت — a direct transfer, evidenced only by a reference the customer reads out. */
    case CardToCard = 'card_to_card';

    case Cheque = 'cheque';

    /**
     * Not money arriving. The customer owes it, and it posts to their ledger.
     */
    case Credit = 'credit';

    public function labelFa(): string
    {
        return match ($this) {
            self::Cash => 'نقدی',
            self::PosTerminal => 'کارتخوان',
            self::CardToCard => 'کارت به کارت',
            self::Cheque => 'چک',
            self::Credit => 'نسیه',
        };
    }

    /**
     * Whether this method puts money in a shop account now.
     *
     * `Credit` does not — it is a promise, and it debits the customer instead.
     * `Cheque` does not either: a cheque in hand is an asset, but the cash arrives when
     * it clears (Phase 7), and treating it as cash on hand overstates the till every
     * time one bounces.
     */
    public function settlesImmediately(): bool
    {
        return match ($this) {
            self::Cash, self::PosTerminal, self::CardToCard => true,
            self::Cheque, self::Credit => false,
        };
    }

    /**
     * Whether an account must be named for this method.
     */
    public function needsAccount(): bool
    {
        return $this->settlesImmediately();
    }

    /**
     * Whether the customer's balance moves.
     */
    public function postsToParty(): bool
    {
        return $this === self::Credit;
    }
}
