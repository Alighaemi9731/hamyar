<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Services;

use App\Modules\CRM\Models\Account;
use App\Modules\CRM\Models\LedgerEntry;
use App\Modules\Sales\Services\ProfitEngine;
use App\Modules\Treasury\Enums\CashDirection;
use App\Modules\Treasury\Models\CashTransaction;
use Carbon\CarbonImmutable;

/**
 * What the shop actually made, once the rent is paid.
 *
 * ## Gross margin comes from Sales, not from the ledger
 *
 * `ProfitEngine` already owns revenue and cost of goods, computed from
 * `sales_invoice_items.cost_snapshot` — the figure written at finalisation and never
 * recomputed. Deriving it a second time here from account balances would produce a number
 * that disagrees with the invoice it summarises the first time a rounding adjustment or a
 * return enters the picture, and ADR 0009 is explicit that reports must reproduce invoice
 * figures exactly.
 *
 * So this composes rather than recalculates: gross margin from Sales, operating costs from
 * Treasury, other income from Treasury, and one subtraction.
 *
 * ## Expenses come from the categories, not from every debit
 *
 * A P&L that summed all expense-account movements would be right today and wrong the moment
 * something writes to an expense account without a `cash_transactions` row behind it — a
 * bank fee on a transfer, say, or a cheque's returned-item charge. Both of those ARE
 * operating costs and both should appear, so the query is over expense-type **accounts**
 * with a category breakdown layered on top from `cash_transactions`, and the two are
 * reconciled: anything in the accounts without a category is reported as «سایر» rather than
 * silently dropped.
 *
 * That last part is the difference between a report a shop trusts and one where the
 * headline never quite equals the rows beneath it.
 *
 * ## Dates are `occurred_at`, always
 *
 * Rent paid on the 1st and keyed on the 5th belongs to the 1st. Every figure here groups by
 * when the money moved, never by when somebody typed it.
 */
final class ProfitAndLoss
{
    public function __construct(private readonly ProfitEngine $sales) {}

    /**
     * @return array{
     *     from: string, to: string,
     *     revenue: int, cost_of_goods: int, gross_margin: int,
     *     other_income: int, operating_costs: int, net_profit: int,
     *     expense_breakdown: list<array{category: string, amount: int}>,
     *     income_breakdown: list<array{category: string, amount: int}>
     * }
     */
    public function forPeriod(CarbonImmutable $from, CarbonImmutable $to, ?int $branchId = null): array
    {
        $from = $from->startOfDay();
        $to = $to->endOfDay();

        $margin = $this->sales->forPeriod($from, $to, $branchId);

        $revenue = $this->intFrom($margin, 'revenue');
        $cost = $this->intFrom($margin, 'cost');
        $gross = $revenue - $cost;

        $operating = $this->accountTotal(Account::TYPE_EXPENSE, $from, $to);
        $otherIncome = $this->accountTotal(Account::TYPE_INCOME, $from, $to);

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'revenue' => $revenue,
            'cost_of_goods' => $cost,
            'gross_margin' => $gross,
            'other_income' => $otherIncome,
            'operating_costs' => $operating,
            'net_profit' => $gross + $otherIncome - $operating,
            'expense_breakdown' => $this->breakdown(CashDirection::Expense, Account::TYPE_EXPENSE, $from, $to),
            'income_breakdown' => $this->breakdown(CashDirection::Income, Account::TYPE_INCOME, $from, $to),
        ];
    }

    /**
     * Net movement on every account of a type, as a positive cost or income figure.
     *
     * An expense account is debited when money is spent, so its movement is positive and
     * used as-is. An income account is credited, so its movement is negative and flipped —
     * the sign convention is an artefact of double entry, and a report that leaked it would
     * show a shop's rental income as a negative number.
     */
    private function accountTotal(string $type, CarbonImmutable $from, CarbonImmutable $to): int
    {
        $ids = Account::query()->where('type', $type)->pluck('id')->all();

        if ($ids === []) {
            return 0;
        }

        $movement = LedgerEntry::query()
            ->whereIn('account_id', $ids)
            ->whereBetween('occurred_at', [$from, $to])
            ->selectRaw('coalesce(sum(debit), 0) - coalesce(sum(credit), 0) as movement')
            ->value('movement');

        $movement = is_numeric($movement) ? (int) $movement : 0;

        return $type === Account::TYPE_INCOME ? -$movement : $movement;
    }

    /**
     * The category rows, plus whatever the accounts hold that no category explains.
     *
     * @return list<array{category: string, amount: int}>
     */
    private function breakdown(CashDirection $direction, string $accountType, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $rows = CashTransaction::query()
            ->with('category')
            ->where('direction', $direction->value)
            ->whereBetween('occurred_at', [$from, $to])
            ->get()
            ->groupBy('transaction_category_id');

        $breakdown = [];
        $categorised = 0;

        foreach ($rows as $group) {
            /** @var CashTransaction $first */
            $first = $group->first();
            $amount = (int) $group->sum(fn (CashTransaction $t): int => $t->amount);

            $categorised += $amount;

            $breakdown[] = [
                'category' => $first->category?->path() ?? 'بدون سرفصل',
                'amount' => $amount,
            ];
        }

        // The reconciling row. A bank fee posts to an expense account with no
        // `cash_transactions` behind it, and it is a real cost — dropping it would make the
        // rows fail to add up to the headline, which is how a shop stops believing a report.
        $unexplained = $this->accountTotal($accountType, $from, $to) - $categorised;

        if ($unexplained !== 0) {
            $breakdown[] = ['category' => 'سایر', 'amount' => $unexplained];
        }

        usort($breakdown, fn (array $a, array $b): int => $b['amount'] <=> $a['amount']);

        return $breakdown;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function intFrom(array $values, string $key): int
    {
        $value = $values[$key] ?? 0;

        return is_numeric($value) ? (int) $value : 0;
    }
}
