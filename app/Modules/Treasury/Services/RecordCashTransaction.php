<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Services;

use App\Modules\CRM\Models\Account;
use App\Modules\CRM\Services\LedgerService;
use App\Modules\Treasury\Models\CashTransaction;
use App\Modules\Treasury\Models\TransactionCategory;
use App\Support\Quota\QuotaGuard;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use RuntimeException;

/**
 * Paying for something, or being paid for something that is not a sale.
 *
 * ## Two lines, and which way round depends on one boolean
 *
 * An expense debits the category's account and credits wherever the money came from. An
 * income does the reverse. That is the entire difference, and it is why this is one
 * service rather than two: the pair of lines is written once, so the two directions cannot
 * drift apart the way two hand-maintained services would.
 *
 * ```
 * expense:  DEBIT category account   CREDIT cash/bank
 * income:   DEBIT cash/bank          CREDIT category account
 * ```
 *
 * ## The party is on neither line
 *
 * Paying the landlord in cash settles nothing between the shop and the landlord — the
 * money changed hands on the spot. Debiting the party as well would make the shop appear
 * to be owed a month's rent it has just paid.
 *
 * A party on the transaction row is there so the shop can answer "what have we paid this
 * person this year", which is a reporting question, not a ledger one. When an expense
 * genuinely is on credit — an invoice from a supplier to be paid later — that is a
 * purchase, and Purchasing already models it.
 *
 * ## Money must come from somewhere money is
 *
 * The paying account has to be a cash box, a bank or a terminal. Paying rent "out of" the
 * sales account is a category error that produces a balance nobody can explain, and it is
 * exactly the mis-click an account picker invites if it does not filter.
 */
final class RecordCashTransaction
{
    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly LedgerService $ledger,
        private readonly QuotaGuard $quota,
    ) {}

    /**
     * Record and post one expense or income.
     *
     * @param  string|null  $generatedKey  set only by a generator; the unique index on it is what makes a re-run safe
     */
    public function record(
        TransactionCategory $category,
        Account $account,
        int $amount,
        CarbonImmutable $occurredAt,
        ?int $partyId = null,
        ?string $description = null,
        ?string $reference = null,
        ?string $generatedKey = null,
        ?int $recurringTemplateId = null,
        ?int $rentalContractId = null,
        ?int $actorId = null,
        ?int $branchId = null,
    ): ?CashTransaction {
        $this->guard($category, $account, $amount);

        try {
            /** @var CashTransaction $transaction */
            $transaction = $this->connection->transaction(function () use (
                $category, $account, $amount, $occurredAt, $partyId, $description,
                $reference, $generatedKey, $recurringTemplateId, $rentalContractId, $actorId, $branchId
            ): CashTransaction {
                /*
                | Manual entries only.
                |
                | `generatedKey` is set by `GenerateRecurring`, which books rent and
                | salaries — and can catch up several months in a single run after a
                | scheduler outage. Metering that would refuse the shop's own standing
                | costs for something it did not do, on the day it was least able to act
                | on the refusal.
                */
                if ($generatedKey === null) {
                    $this->quota->consume('treasury.cash_transactions');
                }

                $transaction = CashTransaction::query()->create([
                    'branch_id' => $branchId,
                    'transaction_category_id' => $category->id,
                    'recurring_template_id' => $recurringTemplateId,
                    'rental_contract_id' => $rentalContractId,
                    'party_id' => $partyId,
                    'account_id' => $account->id,
                    'direction' => $category->direction,
                    'amount' => $amount,
                    'description' => $description,
                    'reference' => $reference,
                    'generated_key' => $generatedKey,
                    'occurred_at' => $occurredAt,
                    'actor_id' => $actorId,
                ]);

                $label = $description ?? $category->name;

                $lines = $category->direction->isOutgoing()
                    ? [
                        ['account_id' => $category->account_id, 'debit' => $amount, 'description' => $label],
                        ['account_id' => $account->id, 'credit' => $amount, 'description' => $label],
                    ]
                    : [
                        ['account_id' => $account->id, 'debit' => $amount, 'description' => $label],
                        ['account_id' => $category->account_id, 'credit' => $amount, 'description' => $label],
                    ];

                $this->ledger->post($lines, $transaction, $occurredAt, $actorId);

                return $transaction;
            });

            return $transaction;
        } catch (QueryException $exception) {
            /*
            | 23505 — the generated-once unique index fired.
            |
            | Somebody has already booked this template's period, either an earlier run or
            | a second worker a millisecond ago. Returning null rather than throwing is the
            | right shape: the caller is a generator asking "does this need doing", and the
            | answer is no. A throw would make a scheduled command fail on an outcome that
            | is entirely normal.
            |
            | Only for a key collision. Any other database failure is a real problem and
            | must not be mistaken for "already done".
            */
            if ($generatedKey !== null && $exception->getCode() === '23505') {
                return null;
            }

            throw $exception;
        }
    }

    private function guard(TransactionCategory $category, Account $account, int $amount): void
    {
        if ($amount <= 0) {
            throw new RuntimeException('مبلغ باید بیشتر از صفر باشد.');
        }

        if (! $account->holdsMoney()) {
            throw new RuntimeException(
                'پرداخت و دریافت فقط از صندوق، بانک یا کارتخوان ممکن است.'
            );
        }

        if (! $category->is_active) {
            throw new RuntimeException('این سرفصل غیرفعال است.');
        }

        // ADR 0009: a figure that is not a whole number of toman cannot be rendered, and
        // the failure would happen on the printed receipt with somebody waiting for it.
        if ($amount % 10 !== 0) {
            throw new RuntimeException('مبلغ باید مضربی از ۱۰ ریال (یک تومان) باشد.');
        }
    }
}
