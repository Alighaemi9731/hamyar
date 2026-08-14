<?php

declare(strict_types=1);

namespace App\Modules\Cheques\Services;

use App\Modules\CRM\Models\Account;

/**
 * The four accounts the cheque lifecycle moves value between.
 *
 * Created on demand rather than by a migration, because a shop that never takes a cheque
 * should not carry four accounts it cannot explain — and because the first cheque must not
 * fail at the counter over a missing row in a chart nobody set up.
 *
 * **None of these hold money**, and that is load-bearing. `Account::moneyHoldingTypes()`
 * deliberately excludes them, so they cannot be selected as a payment destination at the
 * till and cannot inflate the figure a shopkeeper counts against the drawer. An operator
 * who *can* pick «چک‌های نزد صندوق» as a cash account eventually will, and the invariant
 * that makes a stolen cheque discoverable dies that day.
 *
 * The invariant, stated once so it can be tested: **the balance of `cheques_receivable`
 * equals the face value of the paper physically in the shop's drawer.**
 */
final class ChequeAccounts
{
    /** Received cheques in the drawer — اسناد دریافتنی. */
    public const RECEIVABLE = 'cheques_receivable';

    /** Received cheques lodged with a bank — چک‌های در جریان وصول. */
    public const IN_COLLECTION = 'cheques_in_collection';

    /** Dishonoured paper the shop holds after an endorsee handed it back. */
    public const RETURNED = 'cheques_returned';

    /** The shop's own cheques, not yet cleared — اسناد پرداختنی. */
    public const PAYABLE = 'cheques_payable';

    public function receivable(): Account
    {
        return $this->account(self::RECEIVABLE, 'چک‌های نزد صندوق');
    }

    public function inCollection(): Account
    {
        return $this->account(self::IN_COLLECTION, 'چک‌های در جریان وصول');
    }

    public function returned(): Account
    {
        return $this->account(self::RETURNED, 'چک‌های برگشتی نزد ما');
    }

    public function payable(): Account
    {
        return $this->account(self::PAYABLE, 'اسناد پرداختنی');
    }

    /**
     * Where a bank's charge for handling a cheque lands.
     *
     * Shared with Treasury rather than owned here: a returned-item fee and a transfer fee
     * are the same kind of cost, and a shop looking at "what does banking cost us" wants
     * one number rather than two headings that have to be added up.
     */
    public function bankCharges(): Account
    {
        return $this->account(Account::TYPE_EXPENSE, 'کارمزد بانکی');
    }

    /** A customer's debt the shop has given up on. */
    public function badDebt(): Account
    {
        return $this->account(Account::TYPE_EXPENSE, 'مطالبات سوخت‌شده');
    }

    private function account(string $type, string $name): Account
    {
        /** @var Account $account */
        $account = Account::query()->firstOrCreate(
            ['type' => $type, 'name' => $name],
            ['is_active' => true],
        );

        return $account;
    }
}
