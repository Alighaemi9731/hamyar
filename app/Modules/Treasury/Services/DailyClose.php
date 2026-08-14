<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Services;

use App\Modules\CRM\Models\Account;
use App\Modules\CRM\Models\LedgerEntry;
use Carbon\CarbonImmutable;

/**
 * Closing the till — across every account, not just the drawer.
 *
 * Phase 5 shipped a Z report for the POS: what one salesperson took, in one shift, at one
 * till. This is the shop-wide version, and it answers a different question. The Z report
 * asks "does the cash in this drawer match what we sold". This asks "does every place we
 * keep money match what the books say", which is the question an owner asks at closing
 * time and the one a bank statement eventually settles.
 *
 * ## The expected figure is a movement, not a balance
 *
 * What should be in the drawer at close is the opening balance plus the day's movement —
 * and the day's movement is a SUM over entries dated that day, never a running total
 * anybody keeps. A shop that banked cash at 3pm and sold a phone at 5pm has two movements
 * and one closing figure, and the arithmetic must be visible or the operator cannot find
 * the discrepancy.
 *
 * ## Unreconciled entries are shown beside the balance, deliberately
 *
 * A bank account whose balance is right and whose entries are half unticked is a shop that
 * has not actually checked anything. Putting the two numbers next to each other is what
 * turns "the balance looks fine" into "four of these have never been confirmed".
 *
 * ## Dates are the shop's day, not UTC
 *
 * `occurred_at` is stored UTC and a shop closes at a wall-clock hour. The window is
 * computed in the display timezone and converted, or a sale at 11pm lands in tomorrow's
 * close and the drawer is short by exactly one phone.
 */
final class DailyClose
{
    public function __construct(private readonly AccountBalances $balances) {}

    /**
     * The shop's position at the end of a day.
     *
     * @return array{date: string, accounts: list<array{id: int, name: string, type: string, opening: int, movement: int, closing: int, unreconciled: int}>, totals: array{opening: int, movement: int, closing: int}}
     */
    public function for(CarbonImmutable $day): array
    {
        $start = $day->startOfDay();
        $end = $day->endOfDay();

        $accounts = Account::query()
            ->whereIn('type', Account::moneyHoldingTypes())
            ->where('is_active', true)
            ->orderBy('type')
            ->orderBy('name')
            ->get();

        $movements = $this->movementsBetween(array_values($accounts->pluck('id')->all()), $start, $end);

        $rows = [];
        $totalOpening = 0;
        $totalMovement = 0;
        $totalClosing = 0;

        foreach ($accounts as $account) {
            // Everything up to the moment the day began. Not the account's stored opening
            // balance — that is where the shop started in life, not where it started today.
            $opening = $this->balances->balanceOf($account, $start->subSecond());
            $movement = $movements[(int) $account->id] ?? 0;
            $closing = $opening + $movement;

            $rows[] = [
                'id' => (int) $account->id,
                'name' => $account->name,
                'type' => $account->type,
                'opening' => $opening,
                'movement' => $movement,
                'closing' => $closing,
                'unreconciled' => $this->balances->unreconciledTotal($account),
            ];

            $totalOpening += $opening;
            $totalMovement += $movement;
            $totalClosing += $closing;
        }

        return [
            'date' => $day->toDateString(),
            'accounts' => $rows,
            'totals' => [
                'opening' => $totalOpening,
                'movement' => $totalMovement,
                'closing' => $totalClosing,
            ],
        ];
    }

    /**
     * Net movement per account within a window, in one query.
     *
     * @param  list<mixed>  $accountIds
     * @return array<int, int>
     */
    private function movementsBetween(array $accountIds, CarbonImmutable $start, CarbonImmutable $end): array
    {
        if ($accountIds === []) {
            return [];
        }

        $rows = LedgerEntry::query()
            ->whereIn('account_id', $accountIds)
            ->whereBetween('occurred_at', [$start, $end])
            ->groupBy('account_id')
            ->selectRaw('account_id, coalesce(sum(debit), 0) - coalesce(sum(credit), 0) as movement')
            ->toBase()
            ->get();

        $movements = [];

        foreach ($rows as $row) {
            $values = (array) $row;
            $id = $values['account_id'] ?? null;
            $movement = $values['movement'] ?? 0;

            if (is_numeric($id)) {
                $movements[(int) $id] = is_numeric($movement) ? (int) $movement : 0;
            }
        }

        return $movements;
    }
}
