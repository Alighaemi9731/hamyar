<?php

declare(strict_types=1);

namespace App\Modules\Installments\Services;

use App\Modules\CRM\Models\Account;
use App\Modules\CRM\Services\LedgerService;
use App\Modules\Installments\Events\InstallmentCollected;
use App\Modules\Installments\Models\InstallmentCollection;
use App\Modules\Installments\Models\InstallmentPlan;
use App\Modules\Installments\Models\InstallmentRow;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Taking money against an instalment plan.
 *
 * Postings are specified in `docs/specs/installment-collection.md` §5 and pinned by
 * `CollectInstallmentTest`.
 *
 * ## No posting is made when a plan is created, and this is where that bites
 *
 * The invoice already debited the customer for the whole `total_payable`; the schedule is a
 * promise about *when*, not an additional debt. So collection is a plain settlement —
 * DEBIT the till, CREDIT the party — and nothing here touches revenue. Booking income at
 * collection time would recognise the same sale twice: once when it was made and again as
 * the customer pays for it.
 *
 * That is the single most tempting wrong entry in this module, because a payment arriving
 * *feels* like income.
 *
 * ## The late fee is income, and it is posted when charged rather than as it accrues
 *
 * A fee accruing silently every night would write thousands of ledger rows nobody reads and
 * change a party's balance with no event behind it. Accrual is a calculation against the
 * clock; it becomes a ledger fact at the moment somebody collects.
 *
 * ## The row is not rewritten by how it was paid
 *
 * A part payment leaves the row `overdue` with its original amount. The schedule the
 * customer signed is not edited because they paid half of it — the outstanding figure is
 * derived from what has been collected, never by mutating the promise.
 */
final class CollectInstallment
{
    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly LedgerService $ledger,
        private readonly InstallmentMaths $maths,
    ) {}

    /**
     * What is still owed on a row: the promise, less everything collected against it.
     *
     * A SUM, never a stored column — golden rule 3. The customer arguing about how much is
     * left on their instalment is the last person a shop wants to be wrong in front of.
     *
     * One row, one query. That is right where it is used — inside `collect()`, under the
     * row lock, for the single row being paid — and wrong for a list, which is why
     * {@see outstandingForRows()} exists and why this delegates to it rather than keeping
     * a second copy of the arithmetic.
     */
    public function outstandingOn(InstallmentRow $row): int
    {
        return $this->outstandingForRows([$row])[$row->id] ?? max(0, $row->amount);
    }

    /**
     * The same figure for a whole page of rows, in one query rather than one per row.
     *
     * ## Why this is not an optimisation you can skip
     *
     * `outstandingOn()` inside a `foreach` is the natural thing to write and it reads
     * perfectly well, which is exactly what makes it dangerous: the dashboard's overdue
     * card scans up to two hundred rows, so the loop cost the front page two hundred
     * round trips on every render — measured as 203 queries and the largest single slice
     * of its single-user latency. The statement itself is cheap (`installment_collections`
     * is indexed on `(tenant_id, installment_row_id)` and each call touched two buffers);
     * the cost is entirely in the two hundred PHP↔Postgres crossings, which is the kind
     * of cost `pg_stat_statements` cannot show you.
     *
     * Still a SUM over the collections ledger, still never a stored column — golden rule 3
     * is about where the number comes from, not about how many rows one query covers.
     *
     * @param  iterable<int, InstallmentRow>  $rows
     * @return array<int, int> outstanding rial, keyed by installment row id
     */
    public function outstandingForRows(iterable $rows): array
    {
        $promised = [];

        foreach ($rows as $row) {
            $promised[$row->id] = $row->amount;
        }

        if ($promised === []) {
            return [];
        }

        $settled = [];

        $collections = InstallmentCollection::query()
            ->selectRaw('installment_row_id, coalesce(sum(fee_part + profit_part + principal_part), 0) as settled')
            ->whereIn('installment_row_id', array_keys($promised))
            ->groupBy('installment_row_id')
            ->get();

        foreach ($collections as $collection) {
            /** @var int|numeric-string $rowId */
            $rowId = $collection->getAttribute('installment_row_id');
            /** @var int|numeric-string $amount */
            $amount = $collection->getAttribute('settled');

            $settled[(int) $rowId] = (int) $amount;
        }

        $outstanding = [];

        foreach ($promised as $rowId => $amount) {
            $outstanding[$rowId] = max(0, $amount - ($settled[$rowId] ?? 0));
        }

        return $outstanding;
    }

    /**
     * Collect against one row.
     */
    public function collect(
        InstallmentRow $row,
        Account $into,
        int $amount,
        ?CarbonImmutable $at = null,
        ?int $actorId = null,
        string $method = 'cash',
        ?string $reference = null,
    ): InstallmentCollection {
        $at ??= CarbonImmutable::now();

        $this->guard($into, $amount);

        /** @var InstallmentCollection $result */
        $result = $this->connection->transaction(function () use ($row, $into, $amount, $at, $actorId, $method, $reference): InstallmentCollection {
            /*
            | Lock and re-read, for the reason every service in this system does: two
            | operators collecting the same instalment in two tabs both hold a stale row,
            | both see `pending`, and both post. The status check under the lock is the
            | idempotency key.
            */
            $locked = InstallmentRow::query()->lockForUpdate()->find($row->getKey());

            if (! $locked instanceof InstallmentRow) {
                throw new RuntimeException('این قسط دیگر در سیستم نیست.');
            }

            $row->setRawAttributes($locked->getRawOriginal(), true);

            if ($row->status === InstallmentRow::STATUS_PAID) {
                throw new RuntimeException("قسط {$row->sequence} قبلاً تسویه شده است.");
            }

            /** @var InstallmentPlan $plan */
            $plan = $row->plan()->firstOrFail();

            /*
            | Applied against what is STILL owed, not against the row's face amount.
            |
            | A second payment on a part-settled row must not be offered the same profit
            | and principal the first one already cleared — that would let a customer
            | settle 12,000,000 by paying 6,000,000 twice and having each half applied to
            | the same halves.
            */
            $alreadySettled = $row->amount - $this->outstandingOn($row);

            $applied = $this->maths->applyPayment($row, $plan, $amount, $at, $alreadySettled);

            $settlement = $applied['fee'] + $applied['profit'] + $applied['principal'];
            $overpaid = $applied['unapplied'];

            $lines = [
                // The money arrives. This is the only line that touches an account holding
                // real cash.
                ['account_id' => $into->id, 'debit' => $settlement + $overpaid, 'description' => "دریافت قسط {$row->sequence}"],
            ];

            if ($applied['fee'] > 0) {
                // The fee is genuinely new income — it was never on the original invoice.
                $lines[] = [
                    'account_id' => $this->lateFeeAccount()->id,
                    'credit' => $applied['fee'],
                    'description' => "جریمه تأخیر قسط {$row->sequence}",
                ];
            }

            $creditToParty = $applied['profit'] + $applied['principal'] + $overpaid;

            if ($creditToParty > 0) {
                // Settling what they already owed from the invoice. An overpayment rides
                // here too, leaving them in credit — which is the truth, and better than
                // being absorbed silently into a row that did not need it.
                $lines[] = [
                    'party_id' => $plan->party_id,
                    'credit' => $creditToParty,
                    'description' => "بابت قسط {$row->sequence} — قرارداد {$plan->number}",
                ];
            }

            /** @var InstallmentCollection $collection */
            $collection = InstallmentCollection::query()->create([
                'branch_id' => $plan->branch_id,
                'installment_row_id' => $row->getKey(),
                'installment_plan_id' => $plan->getKey(),
                'account_id' => $into->id,
                'amount' => $amount,
                'fee_part' => $applied['fee'],
                'profit_part' => $applied['profit'],
                'principal_part' => $applied['principal'],
                'unapplied' => $overpaid,
                'method' => $method,
                'reference' => $reference,
                'occurred_at' => $at,
                'actor_id' => $actorId,
            ]);

            $this->ledger->post($lines, $collection, $at, $actorId);

            // Derived from the sum, not from this payment alone: three payments over five
            // weeks is an ordinary way to settle an instalment here.
            $fullySettled = $this->outstandingOn($row) === 0;

            $row->forceFill([
                'status' => $fullySettled ? InstallmentRow::STATUS_PAID : $row->status,
                'settled_at' => $fullySettled ? $at : null,
            ])->save();

            return $collection;
        });

        DB::afterCommit(fn () => InstallmentCollected::dispatch($result));

        return $result;
    }

    private function guard(Account $into, int $amount): void
    {
        if ($amount <= 0) {
            throw new RuntimeException('مبلغ دریافتی باید بیشتر از صفر باشد.');
        }

        if ($amount % 10 !== 0) {
            // ADR 0009 — a figure that is not a whole toman cannot be printed on the
            // receipt the customer is waiting for.
            throw new RuntimeException('مبلغ باید مضربی از ۱۰ ریال (یک تومان) باشد.');
        }

        if (! $into->holdsMoney()) {
            throw new RuntimeException('دریافت قسط فقط به صندوق، بانک یا کارتخوان ممکن است.');
        }
    }

    /**
     * Where a late fee is recognised.
     *
     * Its own income account rather than lumped with sales: an owner asking "how much did
     * we make on late payments this year" is asking a question about their customers'
     * behaviour, not about trading, and the answer should not require a filter.
     */
    private function lateFeeAccount(): Account
    {
        /** @var Account $account */
        $account = Account::query()->firstOrCreate(
            ['type' => Account::TYPE_INCOME, 'name' => 'جریمه تأخیر اقساط'],
            ['is_active' => true],
        );

        return $account;
    }
}
