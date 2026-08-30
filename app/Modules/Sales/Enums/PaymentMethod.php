<?php

declare(strict_types=1);

namespace App\Modules\Sales\Enums;

/**
 * How the money arrived.
 *
 * Six methods because an Iranian shop routinely splits one sale across several: part
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

    /**
     * معاوضه — the customer paid with their old phone.
     *
     * A tender rather than a discount, and the distinction is not cosmetic. A discount
     * reduces the price of the new handset, which would compute VAT on a smaller base
     * and understate both the sale and the tax. What actually happened is two
     * transactions on one piece of paper: the shop sold a phone at full price and bought
     * one at an agreed price, and the second settles part of the first.
     */
    case TradeIn = 'trade_in';

    public function labelFa(): string
    {
        return match ($this) {
            self::Cash => 'نقدی',
            self::PosTerminal => 'کارتخوان',
            self::CardToCard => 'کارت به کارت',
            self::Cheque => 'چک',
            self::Credit => 'نسیه',
            self::TradeIn => 'معاوضه',
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
            // A trade-in settles the invoice but puts no money in a cash box — it puts a
            // handset on the shelf. The value is real and the ledger records it against
            // inventory instead (see FinaliseInvoice).
            self::Cheque, self::Credit, self::TradeIn => false,
        };
    }

    /**
     * Whether an account must be named for this method.
     */
    public function needsAccount(): bool
    {
        return $this->settlesImmediately();
    }
}
