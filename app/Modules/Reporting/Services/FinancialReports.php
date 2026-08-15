<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Services;

use App\Modules\Cheques\Enums\ChequeStatus;
use App\Modules\Reporting\Support\ShopClock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Who owes the shop, what is due, and when — the three questions the money side asks.
 *
 * ## Aging is FIFO, and that is a decision rather than a detail
 *
 * `ledger_entries` records debits and credits against a party; it does not record *which
 * debt a payment settled*, because nothing at the counter asks. So an aging report has to
 * choose a rule, and the choice changes every figure on the page.
 *
 * The rule here is the standard one: **a payment settles the oldest debt first.** A
 * customer who owed 5,000,000 in Tir and 3,000,000 in Mordad and has paid 5,000,000 owes
 * 3,000,000 that is **one month old**, not three. Any other rule reports a shop's best-paying
 * customers as its worst.
 *
 * The two rules that look simpler are both wrong in ways that matter:
 *
 * - *Bucket the debits and ignore the credits.* Reports every invoice ever issued as
 *   outstanding. A customer with a clean twelve-year history looks like the worst debt in
 *   the shop.
 * - *Put the whole balance in the bucket of the oldest unpaid entry.* One ancient
 *   1,000-rial rounding remnant drags a party's entire current balance into «۹۰+», and the
 *   report that exists to point at real trouble points everywhere.
 *
 * ## The FIFO runs in SQL, not in PHP
 *
 * The obvious implementation walks each party's entries in a loop, and on a shop with
 * 75,000 ledger rows that is 75,000 rows dragged into PHP to produce forty. The same
 * arithmetic is a window function: with credits applied oldest-first, the credit that
 * *reaches* a given debit is whatever is left after the debits before it, so
 *
 *     remaining(lot) = clamp(cumulative_debits_through_lot − total_credits, 0, lot)
 *
 * is exact FIFO in one pass, and the report stays inside the latency budget.
 *
 * ## `opening_balance` is a pseudo-entry, signed
 *
 * A shop migrating from paper carries a figure in, and `LedgerService::partyBalance()`
 * adds it to every balance it reports. If this report ignored it, the aging total would
 * disagree with the balance shown on the party's own screen — which is the one thing a
 * report about balances may not do. So it joins the entry stream dated `parties.created_at`
 * (when the shop wrote the figure down, the only date it has) and signed: positive is an
 * obligation, negative is a settlement. That is what makes the conservation claim hold —
 * **outstanding − unapplied credit = the sum of `partyBalance()`, exactly** — which
 * `FinancialReportScreenTest` asserts rather than trusting.
 *
 * ## Both directions, one algorithm
 *
 * «طلب» (customers owing the shop) and «بدهی» (the shop owing suppliers) are the same
 * question with debit and credit swapped, so the direction picks the columns and nothing
 * else. Two implementations would drift, and the payable one — the one a shop looks at
 * less often — would be the one that drifted.
 */
final class FinancialReports
{
    public const RECEIVABLE = 'receivable';

    public const PAYABLE = 'payable';

    /**
     * Outstanding party balances, bucketed by the age of the debt behind them.
     *
     * @param  string  $direction  self::RECEIVABLE — parties who owe the shop —
     *                             or self::PAYABLE, the shop's own debts
     * @return list<array{party_id: int, name: string, kind: string, total: int, current: int, days_60: int, days_90: int, older: int, credit: int}>
     */
    public function aging(?CarbonImmutable $asOf = null, string $direction = self::RECEIVABLE): array
    {
        $asOf ??= CarbonImmutable::now();

        $payable = $direction === self::PAYABLE;

        $obligation = $payable ? 'credit' : 'debit';
        $settlement = $payable ? 'debit' : 'credit';

        // The opening figure, split into the side it lands on. A negative opening balance
        // is money the shop owes: an obligation under `payable`, a settlement under
        // `receivable`. `greatest(…, 0)` on both sides keeps each row one-sided, which is
        // the shape the rest of the query (and the `ledger_entries` CHECK) assumes.
        $openingObligation = $payable ? 'greatest(-p.opening_balance, 0)' : 'greatest(p.opening_balance, 0)';
        $openingSettlement = $payable ? 'greatest(p.opening_balance, 0)' : 'greatest(-p.opening_balance, 0)';

        // The as-of day in the shop's wall clock, so "thirty days old" counts the days the
        // shop had rather than the days UTC had.
        $asOfDay = $asOf->setTimezone(ShopClock::timezone())->toDateString();
        $lotDay = ShopClock::dayOf('l.occurred_at');

        $sql = <<<SQL
            with entry as (
                select
                    le.party_id                as party_id,
                    le.occurred_at             as occurred_at,
                    1                          as ord,
                    le.id                      as seq,
                    le.{$obligation}           as obligation,
                    le.{$settlement}           as settlement
                from ledger_entries le
                where le.party_id is not null
                  and le.occurred_at <= ?

                union all

                select
                    p.id,
                    p.created_at,
                    0                          as ord,
                    0                          as seq,
                    {$openingObligation},
                    {$openingSettlement}
                from parties p
                where p.deleted_at is null
                  and p.opening_balance <> 0
                  and p.created_at <= ?
            ),
            party_total as (
                select party_id,
                       sum(obligation) as obligated,
                       sum(settlement) as settled
                from entry
                group by party_id
            ),
            lot as (
                select l.party_id,
                       l.occurred_at,
                       l.obligation,
                       sum(l.obligation) over (
                           partition by l.party_id
                           order by l.occurred_at, l.ord, l.seq
                           rows between unbounded preceding and current row
                       ) as cumulative
                from entry l
                where l.obligation > 0
            ),
            outstanding as (
                select l.party_id,
                       least(l.obligation, greatest(l.cumulative - t.settled, 0)) as amount,
                       (cast(? as date) - {$lotDay})                              as age_days
                from lot l
                join party_total t on t.party_id = l.party_id
            )
            select
                p.id                                                                       as party_id,
                p.name                                                                     as name,
                p.kind                                                                     as kind,
                coalesce(sum(o.amount), 0)                                                 as total,
                coalesce(sum(case when o.age_days <= 30 then o.amount else 0 end), 0)      as current,
                coalesce(sum(case when o.age_days between 31 and 60 then o.amount else 0 end), 0) as days_60,
                coalesce(sum(case when o.age_days between 61 and 90 then o.amount else 0 end), 0) as days_90,
                coalesce(sum(case when o.age_days > 90 then o.amount else 0 end), 0)       as older,
                greatest(t.settled - t.obligated, 0)                                       as credit
            from party_total t
            join parties p on p.id = t.party_id
            left join outstanding o on o.party_id = t.party_id and o.amount > 0
            where p.deleted_at is null
            group by p.id, p.name, p.kind, t.settled, t.obligated
            having coalesce(sum(o.amount), 0) > 0 or t.settled > t.obligated
            order by coalesce(sum(o.amount), 0) desc, p.id
            SQL;

        $rows = DB::select($sql, [$asOf, $asOf, $asOfDay]);

        $shaped = [];

        foreach ($rows as $row) {
            $values = (array) $row;

            $shaped[] = [
                'party_id' => $this->intOf($values['party_id'] ?? 0),
                'name' => $this->stringOf($values['name'] ?? '') ?: 'بدون نام',
                'kind' => $this->stringOf($values['kind'] ?? ''),
                'total' => $this->intOf($values['total'] ?? 0),
                'current' => $this->intOf($values['current'] ?? 0),
                'days_60' => $this->intOf($values['days_60'] ?? 0),
                'days_90' => $this->intOf($values['days_90'] ?? 0),
                'older' => $this->intOf($values['older'] ?? 0),
                'credit' => $this->intOf($values['credit'] ?? 0),
            ];
        }

        return $shaped;
    }

    /**
     * Cheques by the day they fall due — the shop's next few weeks of cash, as a shape.
     *
     * ## A day per row, not a cheque per row
     *
     * The Cheques module already lists individual cheques with every filter a person could
     * want. What it cannot answer is «چه روزی پول کم می‌آورم» — and that is a question about
     * days, not about cheques. So each row is one due date carrying what arrives, what
     * leaves, and the net.
     *
     * ## Only open cheques count toward the net
     *
     * A cleared cheque is not money that is *going* to arrive; it arrived, and counting it
     * again would tell a shop it has cash coming that it already spent. Cleared and bounced
     * amounts are still reported per day, because the calendar is read backwards as often as
     * forwards, but they sit in their own columns and stay out of the net.
     *
     * `Bounced` counts as open on purpose, following `ChequeStatus::isClosed()`: a bounced
     * cheque is the most active thing in the module, and dropping it from the calendar
     * removes exactly the row somebody needs to act on.
     *
     * @return list<array{due_date: string, incoming: int, incoming_count: int, outgoing: int, outgoing_count: int, net: int, cleared: int, bounced: int}>
     */
    public function chequeCalendar(ReportPeriod $period, ?string $direction = null): array
    {
        /*
        | `due_date` is a DATE column, not a timestamp — a cheque falls due on a day that
        | is printed on paper, not at an instant — so this is the one report in the module
        | with no timezone shift to get wrong.
        */
        $open = $this->openChequeStatuses();
        $openList = $this->quotedList($open);

        $rows = DB::table('cheques')
            ->whereNull('cheques.deleted_at')
            ->whereBetween('cheques.due_date', [$period->from->toDateString(), $period->to->toDateString()])
            ->when($direction !== null, fn ($q) => $q->where('cheques.direction', $direction))
            ->groupBy('cheques.due_date')
            ->orderBy('cheques.due_date')
            ->selectRaw("
                cheques.due_date as due_date,
                coalesce(sum(case when cheques.direction = 'received' and cheques.status in ({$openList}) then cheques.amount else 0 end), 0) as incoming,
                count(*) filter (where cheques.direction = 'received' and cheques.status in ({$openList})) as incoming_count,
                coalesce(sum(case when cheques.direction = 'issued' and cheques.status in ({$openList}) then cheques.amount else 0 end), 0) as outgoing,
                count(*) filter (where cheques.direction = 'issued' and cheques.status in ({$openList})) as outgoing_count,
                coalesce(sum(case when cheques.status = 'cleared' then cheques.amount else 0 end), 0) as cleared,
                coalesce(sum(case when cheques.status = 'bounced' then cheques.amount else 0 end), 0) as bounced
            ")
            ->get();

        $shaped = [];

        foreach ($rows as $row) {
            $values = (array) $row;

            $incoming = $this->intOf($values['incoming'] ?? 0);
            $outgoing = $this->intOf($values['outgoing'] ?? 0);

            $shaped[] = [
                'due_date' => $this->stringOf($values['due_date'] ?? ''),
                'incoming' => $incoming,
                'incoming_count' => $this->intOf($values['incoming_count'] ?? 0),
                'outgoing' => $outgoing,
                'outgoing_count' => $this->intOf($values['outgoing_count'] ?? 0),
                'net' => $incoming - $outgoing,
                'cleared' => $this->intOf($values['cleared'] ?? 0),
                'bounced' => $this->intOf($values['bounced'] ?? 0),
            ];
        }

        return $shaped;
    }

    /**
     * Cheques already past due and still open — today's problem, whatever the range says.
     *
     * The same decision the technician report makes about «روی میز»: an overdue cheque has
     * no future date to be inside, and a shop reading Shahrivar's calendar still needs to
     * know about the one that failed to clear in Mordad. Showing it only when somebody
     * happens to scroll the range back over it is how it gets forgotten.
     *
     * @return array{incoming: int, incoming_count: int, outgoing: int, outgoing_count: int}
     */
    public function overdueCheques(?CarbonImmutable $asOf = null): array
    {
        $asOf ??= CarbonImmutable::now();

        $today = $asOf->setTimezone(ShopClock::timezone())->toDateString();
        $openList = $this->quotedList($this->openChequeStatuses());

        $row = DB::table('cheques')
            ->whereNull('cheques.deleted_at')
            ->where('cheques.due_date', '<', $today)
            ->whereRaw("cheques.status in ({$openList})")
            ->selectRaw("
                coalesce(sum(case when cheques.direction = 'received' then cheques.amount else 0 end), 0) as incoming,
                count(*) filter (where cheques.direction = 'received') as incoming_count,
                coalesce(sum(case when cheques.direction = 'issued' then cheques.amount else 0 end), 0) as outgoing,
                count(*) filter (where cheques.direction = 'issued') as outgoing_count
            ")
            ->first();

        $values = (array) $row;

        return [
            'incoming' => $this->intOf($values['incoming'] ?? 0),
            'incoming_count' => $this->intOf($values['incoming_count'] ?? 0),
            'outgoing' => $this->intOf($values['outgoing'] ?? 0),
            'outgoing_count' => $this->intOf($values['outgoing_count'] ?? 0),
        ];
    }

    /**
     * دفتر اقساط — every instalment falling due in the range, and what has been collected
     * against it.
     *
     * ## A row per instalment, unlike the cheque calendar
     *
     * A «دفتر» is the book itself: the shop reads down it, ticks off what was paid, and
     * rings the ones that were not. Aggregating by day would remove the only two columns
     * that matter — whose instalment it is and whether they paid it.
     *
     * ## Collected is a SUM, and it nets off the overpayment
     *
     * Golden rule 3: `installment_rows` has no `paid_amount` column, deliberately, so what
     * a row has been paid is a SUM over `installment_collections`. And it is
     * `amount - unapplied`, not `amount`: an overpayment is left on the party as credit
     * rather than applied to this instalment, and counting it here would show a row as
     * over-collected while the money sits somewhere else.
     *
     * @return list<array{plan_number: string, party: string, sequence: int, due_at: string, amount: int, collected: int, outstanding: int, status: string, overdue_days: int}>
     */
    public function installmentsBook(ReportPeriod $period, ?CarbonImmutable $asOf = null): array
    {
        $asOf ??= CarbonImmutable::now();

        $today = $asOf->setTimezone(ShopClock::timezone())->toDateString();
        $dueDay = ShopClock::dayOf('installment_rows.due_at');

        $rows = DB::table('installment_rows')
            ->join('installment_plans', 'installment_plans.id', '=', 'installment_rows.installment_plan_id')
            ->leftJoin('parties', 'parties.id', '=', 'installment_plans.party_id')
            ->leftJoin('installment_collections', 'installment_collections.installment_row_id', '=', 'installment_rows.id')
            ->whereNull('installment_plans.deleted_at')
            ->whereBetween('installment_rows.due_at', [$period->from, $period->to])
            ->groupBy(
                'installment_rows.id',
                'installment_rows.sequence',
                'installment_rows.due_at',
                'installment_rows.amount',
                'installment_rows.status',
                'installment_plans.number',
                'parties.name',
            )
            ->orderBy('installment_rows.due_at')
            ->orderBy('installment_rows.id')
            ->selectRaw("
                installment_plans.number as plan_number,
                coalesce(parties.name, '') as party,
                installment_rows.sequence as sequence,
                installment_rows.due_at as due_at,
                installment_rows.amount as amount,
                installment_rows.status as status,
                coalesce(sum(installment_collections.amount - installment_collections.unapplied), 0) as collected,
                (cast(? as date) - {$dueDay}) as overdue_days
            ", [$today])
            ->get();

        $shaped = [];

        foreach ($rows as $row) {
            $values = (array) $row;

            $amount = $this->intOf($values['amount'] ?? 0);
            $collected = $this->intOf($values['collected'] ?? 0);
            $outstanding = max($amount - $collected, 0);
            $overdueDays = $this->intOf($values['overdue_days'] ?? 0);

            $shaped[] = [
                'plan_number' => $this->stringOf($values['plan_number'] ?? ''),
                'party' => $this->stringOf($values['party'] ?? '') ?: 'بدون نام',
                'sequence' => $this->intOf($values['sequence'] ?? 0),
                'due_at' => $this->stringOf($values['due_at'] ?? ''),
                'amount' => $amount,
                'collected' => $collected,
                'outstanding' => $outstanding,
                'status' => $this->stringOf($values['status'] ?? ''),
                // Only a row with money still on it can be overdue. A settled instalment
                // that was paid late is history, not a debt, and colouring it red on the
                // book sends somebody to chase a customer who already paid.
                'overdue_days' => $outstanding > 0 ? max($overdueDays, 0) : 0,
            ];
        }

        return $shaped;
    }

    /**
     * Statuses that still represent money in motion.
     *
     * Derived from `ChequeStatus::isClosed()` rather than listed, so a status added to the
     * enum next phase cannot silently fall out of the calendar.
     *
     * @return list<string>
     */
    private function openChequeStatuses(): array
    {
        $open = [];

        foreach (ChequeStatus::cases() as $status) {
            if (! $status->isClosed()) {
                $open[] = $status->value;
            }
        }

        return $open;
    }

    /**
     * Enum values as a SQL list.
     *
     * Inlined rather than bound for the same reason `ShopClock` inlines its zone: these
     * appear inside `filter (where …)` and `case` expressions that also appear in a
     * `GROUP BY`. Safe because the values come from a PHP enum, never from a request — and
     * proved rather than argued: anything outside the character set a status may contain is
     * stripped before it reaches the string.
     *
     * @param  list<string>  $values
     */
    private function quotedList(array $values): string
    {
        $safe = [];

        foreach ($values as $value) {
            $clean = preg_replace('/[^a-z_]/', '', (string) $value);

            if (is_string($clean) && $clean !== '') {
                $safe[] = "'".$clean."'";
            }
        }

        return $safe === [] ? "''" : implode(', ', $safe);
    }

    private function intOf(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private function stringOf(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }
}
