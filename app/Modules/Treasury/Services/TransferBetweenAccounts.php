<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Services;

use App\Modules\CRM\Models\Account;
use App\Modules\CRM\Services\LedgerService;
use App\Modules\Treasury\Events\TransferRecorded;
use App\Modules\Treasury\Models\AccountTransfer;
use App\Support\Quota\QuotaGuard;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Moving money the shop already has from one place to another.
 *
 * Banking the day's cash, settling the کارتخوان into the bank, topping up petty cash
 * from the till. Frequent, unglamorous, and the operation most likely to be got subtly
 * wrong — because it is the only common event where **no party is involved and the
 * shop's total does not change**.
 *
 * ## Two lines, one batch, and the total is unmoved
 *
 * Credit the source, debit the destination, same amount. The shop is no richer and no
 * poorer; money is simply somewhere else. That is why a transfer must never touch a
 * revenue or expense account: a shop that books its own cash banking as income reports a
 * month of takings twice, and the first person to notice is an accountant in the
 * following spring.
 *
 * ## The fee is a real expense, and it is a third line
 *
 * A bank charges to accept a cash deposit; a PSP takes a percentage of every card
 * settlement. That money genuinely leaves the shop, so it cannot be folded into the
 * transfer amount without making the destination balance wrong. The source is credited
 * with amount + fee, the destination debited with amount, and the difference is debited
 * to the shop's fee expense — three lines that still sum to zero.
 *
 * Getting this wrong is the classic version of this bug: the shop credits the till
 * 50,000,000, debits the bank 49,650,000, and 350,000 evaporates. The ledger refuses
 * that write, which is the point of a balanced-set API.
 *
 * ## Same account, both ends
 *
 * Refused. It posts a debit and a credit that cancel, leaves two rows explaining
 * nothing, and is always a mis-click rather than an intention.
 */
final class TransferBetweenAccounts
{
    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly LedgerService $ledger,
        private readonly AccountBalances $balances,
        private readonly QuotaGuard $quota,
    ) {}

    /**
     * Move money between two of the shop's own accounts.
     *
     * @param  int  $fee  what the bank or PSP took, in rial — leaves the shop on top of the amount moved
     */
    public function transfer(
        Account $from,
        Account $to,
        int $amount,
        ?int $fee = 0,
        ?string $reference = null,
        ?CarbonImmutable $occurredAt = null,
        ?int $actorId = null,
    ): AccountTransfer {
        $fee = max(0, $fee ?? 0);

        $this->guard($from, $to, $amount);

        $occurredAt ??= CarbonImmutable::now();

        /** @var AccountTransfer $transfer */
        $transfer = $this->connection->transaction(function () use ($from, $to, $amount, $fee, $reference, $occurredAt, $actorId): AccountTransfer {
            $this->quota->consume('treasury.transfers');

            $transfer = AccountTransfer::query()->create([
                'from_account_id' => $from->id,
                'to_account_id' => $to->id,
                'amount' => $amount,
                'fee' => $fee,
                'reference' => $reference,
                'occurred_at' => $occurredAt,
                'actor_id' => $actorId,
            ]);

            $description = "انتقال از {$from->name} به {$to->name}";

            $lines = [
                // Money leaves here — including the fee, which really did come out of
                // this account even though it never arrived at the other one.
                ['account_id' => $from->id, 'credit' => $amount + $fee, 'description' => $description],
                ['account_id' => $to->id, 'debit' => $amount, 'description' => $description],
            ];

            if ($fee > 0) {
                // The third line. Without it the set does not balance, and the ledger
                // refuses the write rather than letting the difference disappear.
                $lines[] = [
                    'account_id' => $this->feeAccountId(),
                    'debit' => $fee,
                    'description' => "کارمزد انتقال — {$from->name}",
                ];
            }

            $this->ledger->post($lines, $transfer, $occurredAt, $actorId);

            return $transfer;
        });

        // After commit: a listener that texts or refreshes a dashboard must not fire for
        // a transfer that rolled back.
        DB::afterCommit(fn () => TransferRecorded::dispatch($transfer));

        return $transfer;
    }

    private function guard(Account $from, Account $to, int $amount): void
    {
        if ($amount <= 0) {
            throw new RuntimeException('مبلغ انتقال باید بیشتر از صفر باشد.');
        }

        if ($from->id === $to->id) {
            throw new RuntimeException('مبدأ و مقصد انتقال نمی‌توانند یکی باشند.');
        }

        if (! $from->is_active || ! $to->is_active) {
            throw new RuntimeException('انتقال به یا از حساب غیرفعال ممکن نیست.');
        }

        /*
        | Overdrawing is refused for a cash box and allowed for a bank.
        |
        | A till cannot hold less than nothing — if the software thinks it does, somebody
        | has mistyped, and letting it through buries the mistake under later entries. A
        | bank account genuinely can go negative (an overdraft, or a fee landing before a
        | deposit clears), and refusing that would make the software disagree with the
        | statement.
        */
        if ($from->type === Account::TYPE_CASH && $this->balances->balanceOf($from) < $amount) {
            throw new RuntimeException(
                "موجودی {$from->name} برای این انتقال کافی نیست."
            );
        }
    }

    /**
     * Where a transfer fee is booked.
     *
     * Falls back to creating the account rather than throwing: a shop that has never
     * paid a bank fee has no such account, and the first cash deposit should not fail at
     * the counter because of a missing row in a chart nobody has set up yet.
     */
    private function feeAccountId(): int
    {
        /** @var Account $account */
        $account = Account::query()->firstOrCreate(
            ['type' => Account::TYPE_EXPENSE, 'name' => 'کارمزد بانکی'],
            ['is_active' => true],
        );

        return $account->id;
    }
}
