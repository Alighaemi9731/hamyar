<?php

declare(strict_types=1);

namespace App\Modules\Cheques\Enums;

/**
 * Where a cheque is in its life.
 *
 * The transitions and their exact ledger effect are specified in `docs/specs/cheques.md`,
 * written before this code and pinned row-for-row by `ChequePostingMatrixTest`. This enum
 * is the state half of that table; it must not disagree with it.
 */
enum ChequeStatus: string
{
    /** Received: the paper is in the shop's drawer. Issued: written and handed over. */
    case InHand = 'in_hand';

    /** Received only: lodged with a bank for collection. */
    case Deposited = 'deposited';

    /** Issued only: the payee has lodged it. Optional — most shops never learn this. */
    case Presented = 'presented';

    case Cleared = 'cleared';

    case Bounced = 'bounced';

    /** Received only: endorsed onward to settle the shop's own debt — خرج کردن چک. */
    case SpentToThirdParty = 'spent_to_third_party';

    /** Received only: the endorsee's bank dishonoured it and told us. */
    case ReturnedByEndorsee = 'returned_by_endorsee';

    /** Handed back to the party who wrote it. */
    case Returned = 'returned';

    case WrittenOff = 'written_off';

    case Cancelled = 'cancelled';

    public function labelFa(): string
    {
        return match ($this) {
            self::InHand => 'نزد ما',
            self::Deposited => 'در جریان وصول',
            self::Presented => 'ارائه‌شده',
            self::Cleared => 'وصول شده',
            self::Bounced => 'برگشتی',
            self::SpentToThirdParty => 'خرج شده',
            self::ReturnedByEndorsee => 'برگشت از شخص ثالث',
            self::Returned => 'مسترد شده',
            self::WrittenOff => 'سوخت شده',
            self::Cancelled => 'ابطال شده',
        };
    }

    /**
     * Nothing more will happen to this cheque.
     *
     * `Bounced` is deliberately NOT closed: re-presentation is the common next step, and
     * a shop chasing a bounced cheque is doing the most active thing in this module.
     */
    public function isClosed(): bool
    {
        return in_array($this, [
            self::Cleared, self::Returned, self::WrittenOff, self::Cancelled,
        ], true);
    }

    /**
     * Still exposing the shop to a loss.
     *
     * Drives the credit check: a customer whose cheques are all in one of these states has
     * settled nothing yet, whatever their party balance says. `SpentToThirdParty` counts
     * because endorsement does not discharge recourse — if it bounces at the endorsee, the
     * shop is liable and the drawer is still the drawer.
     *
     * @return list<string>
     */
    public static function outstandingForExposure(): array
    {
        return [
            self::InHand->value,
            self::Deposited->value,
            self::SpentToThirdParty->value,
            self::Bounced->value,
            self::ReturnedByEndorsee->value,
        ];
    }
}
