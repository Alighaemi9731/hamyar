<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Services;

use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Cheques\Enums\ChequeDirection;
use App\Modules\Cheques\Models\Cheque;
use App\Modules\Cheques\Services\ChequeCalendar;
use App\Modules\Installments\Models\InstallmentPlan;
use App\Modules\Installments\Models\InstallmentRow;
use App\Modules\Installments\Services\CollectInstallment;
use App\Modules\Inventory\Services\StockOverview;
use App\Modules\Repairs\Enums\TicketStatus;
use App\Modules\Repairs\Models\RepairTicket;
use App\Modules\Sales\Services\ProfitEngine;
use App\Support\Jalali;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Database\Eloquent\Builder;

/**
 * The numbers on the front page, each one borrowed from whoever owns it.
 *
 * ## Not one of these figures is computed here
 *
 * Every widget composes the service that already answers its question — `ProfitEngine`
 * for the day's trade, `ChequeCalendar` for what is falling due, `CollectInstallment` for
 * what a row still owes, `StockOverview` for what is on the shelf. A dashboard that
 * re-derives is a dashboard that disagrees with the screen it links to, and the shopkeeper
 * finds out by clicking through and seeing a different number. That is worse than no
 * dashboard: it costs them their trust in both screens.
 *
 * The one thing this class adds is *shape* — turning a service's answer into a small
 * payload a card can render.
 *
 * ## Money the viewer may not see is never computed, let alone sent
 *
 * `$withProfit` is a constructor argument of the query, not a filter applied afterwards.
 * A payload built in full and then stripped leaks the first time somebody adds a key and
 * forgets the stripping list; a payload that never contains the figure cannot. Cost and
 * margin are the most commercially sensitive numbers in the shop and staff turnover is
 * high (see `PermissionCatalogue`), so this one fails closed by construction.
 *
 * ## An empty widget is not a hidden widget
 *
 * "No cheques due this week" is an answer, and a useful one. A widget the viewer has no
 * permission for is absent entirely. Collapsing the two would have a cashier reading
 * «هیچ چکی سررسید ندارد» off a screen that simply was not allowed to ask.
 */
final class DashboardWidgets
{
    /**
     * The same cap the low-stock screen uses.
     *
     * Deliberately identical: the card links to that screen, and a count taken over a
     * different population than the list it opens is a bug report waiting to be filed.
     */
    private const LOW_STOCK_SCAN = 500;

    public function __construct(
        private readonly ProfitEngine $profit,
        private readonly SalesReports $sales,
        private readonly ChequeCalendar $cheques,
        private readonly CollectInstallment $collections,
        private readonly StockOverview $stock,
    ) {}

    /**
     * Today's trade — «امروز چقدر فروختیم».
     *
     * Today is the Jalali day in Tehran, from `Jalali::startOfDay` to the same day's end,
     * because that is the day the shop opened and closed. A UTC day would move the
     * boundary into the middle of the evening's business.
     *
     * @return array{revenue: array{value: int, formatted: string}, invoice_count: int, profit?: array{value: int, formatted: string}, margin_percent?: int}
     */
    public function todaysTrade(bool $withProfit, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $period = ReportPeriod::of($now, $now);

        $figures = $this->profit->forPeriod($period->from, $period->to);

        $trade = [
            'revenue' => Money::toArray($figures['revenue']),
            'invoice_count' => $figures['invoice_count'],
        ];

        if ($withProfit) {
            $trade['profit'] = Money::toArray($figures['profit']);
            $trade['margin_percent'] = $figures['margin_percent'];
        }

        return $trade;
    }

    /**
     * Daily revenue for a chart, with the quiet days present.
     *
     * `SalesReports::daily()` returns only days that had a sale, which is right for a
     * table and wrong for a chart: a line drawn through the rows alone compresses a
     * closed week into a single step and makes a bad fortnight look busy. So the range is
     * filled, and a day with no sales is a zero rather than a missing point.
     *
     * @return list<array{date: string, jalali: string, revenue: int, profit?: int}>
     */
    public function salesTrend(bool $withProfit, int $days = 30, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $period = ReportPeriod::of($now->subDays(max(1, $days) - 1), $now);

        $byDay = [];

        foreach ($this->sales->daily($period) as $row) {
            $day = is_scalar($row['date'] ?? null) ? (string) $row['date'] : '';

            if ($day !== '') {
                $byDay[$day] = $row;
            }
        }

        $trend = [];

        for ($cursor = $period->from; $cursor->lessThanOrEqualTo($period->to); $cursor = $cursor->addDay()) {
            $key = $cursor->toDateString();
            $row = $byDay[$key] ?? null;

            $point = [
                'date' => $key,
                'jalali' => Jalali::format($cursor, Jalali::DATE),
                'revenue' => $this->intOf($row['revenue'] ?? 0),
            ];

            if ($withProfit) {
                $point['profit'] = $this->intOf($row['margin'] ?? 0);
            }

            $trend[] = $point;
        }

        return $trend;
    }

    /**
     * Devices on the bench, by status.
     *
     * Every open status is listed even at zero. The board has a column for each, and a
     * dashboard that hides the empty ones changes shape every morning — which makes the
     * one number somebody is looking for move to a different place on the card.
     *
     * @return array{total: int, statuses: list<array{status: string, label: string, count: int}>}
     */
    public function repairsInProgress(): array
    {
        $counts = RepairTicket::query()
            ->whereIn('status', array_map(fn (TicketStatus $s): string => $s->value, $this->openTicketStatuses()))
            ->groupBy('status')
            ->selectRaw('status, count(*) as total')
            ->pluck('total', 'status');

        $statuses = [];
        $total = 0;

        foreach ($this->openTicketStatuses() as $status) {
            $count = $this->intOf($counts[$status->value] ?? 0);
            $total += $count;

            $statuses[] = [
                'status' => $status->value,
                'label' => $status->labelFa(),
                'count' => $count,
            ];
        }

        return ['total' => $total, 'statuses' => $statuses];
    }

    /**
     * رسوبی — finished weeks ago and still on the shelf.
     *
     * Its own card rather than a tenth bar on the repairs one, because it is not a stage
     * of work: it is money and shelf space the shop has already spent, and the action it
     * calls for (ring them, or start the disposal clock) is nothing like «برو سراغ بعدی».
     *
     * @return array{count: int, oldest: list<array{id: int, code: string, device: string, party_name: ?string, days: int}>}
     */
    public function abandonedDevices(int $limit = 5, ?CarbonImmutable $asOf = null): array
    {
        $asOf ??= CarbonImmutable::now();

        $tickets = RepairTicket::query()
            ->with('party:id,name')
            ->where('status', TicketStatus::Abandoned->value)
            ->orderBy('abandoned_at')
            ->limit($limit)
            ->get();

        $oldest = [];

        foreach ($tickets as $ticket) {
            $oldest[] = [
                'id' => $ticket->id,
                'code' => $ticket->code,
                'device' => trim($ticket->device_brand.' '.$ticket->device_model),
                'party_name' => $ticket->party?->name,
                'days' => max(0, (int) ($ticket->abandoned_at?->startOfDay()->diffInDays($asOf->startOfDay(), false) ?? 0)),
            ];
        }

        return [
            'count' => RepairTicket::query()->where('status', TicketStatus::Abandoned->value)->count(),
            'oldest' => $oldest,
        ];
    }

    /**
     * Cheques falling due, the shop's own obligations first.
     *
     * Issued leads because a bounced cheque of the shop's own is a public credit failure
     * that follows the owner around the bazaar — `ChequeCalendar` makes the same argument
     * at more length, and this card is the reason it was written.
     *
     * @return array{issued: array{overdue_count: int, overdue_total: array{value: int, formatted: string}, due_count: int, due_total: array{value: int, formatted: string}}, received: array{overdue_count: int, overdue_total: array{value: int, formatted: string}, due_count: int, due_total: array{value: int, formatted: string}}, soonest: list<array{id: int, direction: string, party_name: ?string, amount: array{value: int, formatted: string}, due_date: string, overdue: bool}>}
     */
    public function chequesDue(int $days = 7, ?CarbonImmutable $asOf = null): array
    {
        $asOf ??= CarbonImmutable::now();

        $issued = $this->cheques->upcoming(ChequeDirection::Issued, $days, $asOf);
        $received = $this->cheques->upcoming(ChequeDirection::Received, $days, $asOf);

        $soonest = [];

        foreach ([$issued, $received] as $side) {
            foreach ([...$side['overdue'], ...$side['due']] as $cheque) {
                $soonest[] = [
                    'id' => $cheque->id,
                    'direction' => $cheque->direction->value,
                    'party_name' => $cheque->party?->name,
                    'amount' => Money::toArray($cheque->outstanding()),
                    'due_date' => $cheque->due_date->toIso8601String(),
                    'overdue' => $cheque->due_date->lessThan($asOf->startOfDay()),
                ];
            }
        }

        usort($soonest, static fn (array $a, array $b): int => strcmp((string) $a['due_date'], (string) $b['due_date']));

        return [
            'issued' => $this->chequeSide($issued),
            'received' => $this->chequeSide($received),
            'soonest' => array_slice($soonest, 0, 6),
        ];
    }

    /**
     * Instalment rows past their due date and still owing something.
     *
     * The outstanding figure comes from `CollectInstallment` rather than from `amount`,
     * because a part payment leaves the row overdue at its original amount — deliberately,
     * so the schedule stays the schedule. A dashboard summing `amount` would chase people
     * for money they have already handed over.
     *
     * ## Asked for all the rows at once, and it has to be
     *
     * `outstandingOn()` per row inside the loop is what this originally did, and on a shop
     * that actually sells on instalments it made the front page issue two hundred queries
     * to render five lines — the single largest slice of the dashboard's latency, and
     * invisible to any test whose fixture has no instalments in it.
     * {@see CollectInstallment::outstandingForRows()} answers the same question for the
     * whole page in one grouped SUM.
     *
     * @return array{count: int, total: array{value: int, formatted: string}, worst: list<array{id: int, plan_number: string, party_name: ?string, outstanding: array{value: int, formatted: string}, days_late: int}>}
     */
    public function overdueInstallments(int $limit = 5, ?CarbonImmutable $asOf = null): array
    {
        $asOf ??= CarbonImmutable::now();

        $rows = InstallmentRow::query()
            ->with('plan.party:id,name')
            ->whereIn('status', [InstallmentRow::STATUS_PENDING, InstallmentRow::STATUS_OVERDUE])
            ->where('due_at', '<', $asOf->startOfDay())
            ->orderBy('due_at')
            ->limit(200)
            ->get();

        $outstandingByRow = $this->collections->outstandingForRows($rows);

        $total = 0;
        $worst = [];

        $counted = 0;

        foreach ($rows as $row) {
            $outstanding = $outstandingByRow[$row->id] ?? 0;

            if ($outstanding <= 0) {
                continue;
            }

            $total += $outstanding;
            $counted++;

            if (count($worst) < $limit) {
                $plan = $row->plan;

                $worst[] = [
                    'id' => $row->id,
                    'plan_number' => $plan instanceof InstallmentPlan ? $plan->number : '',
                    'party_name' => $plan instanceof InstallmentPlan ? $plan->party?->name : null,
                    'outstanding' => Money::toArray($outstanding),
                    'days_late' => max(0, (int) $row->due_at->startOfDay()->diffInDays($asOf->startOfDay(), false)),
                ];
            }
        }

        return [
            // Rows still owing something, not rows that are late: a row settled in full
            // but never marked is not somebody to ring.
            'count' => $counted,
            'total' => Money::toArray($total),
            'worst' => $worst,
        ];
    }

    /**
     * Lines at or under their reorder threshold, emptiest first.
     *
     * Only products with a threshold set are considered — `null` there means nobody has
     * decided, and inventing a default would bury the handful of lines somebody did care
     * about under the whole catalogue.
     *
     * @return array{count: int, lines: list<array{variant_id: int, product_name: string, variant_name: string, on_hand: int, threshold: int, is_out: bool}>}
     */
    public function lowStock(int $limit = 5): array
    {
        $variants = ProductVariant::query()
            ->with('product:id,name,type,low_stock_threshold')
            ->where('is_active', true)
            ->whereHas('product', function (Builder $query): void {
                $query->where('is_active', true)->whereNotNull('low_stock_threshold');
            })
            ->orderBy('product_id')
            ->limit(self::LOW_STOCK_SCAN)
            ->get();

        $figures = $this->stock->onHandFor($variants);

        $lines = [];

        foreach ($variants as $variant) {
            $threshold = $variant->product->low_stock_threshold;
            $onHand = $figures[$variant->id] ?? 0;

            if ($threshold === null || $onHand > $threshold) {
                continue;
            }

            $lines[] = [
                'variant_id' => $variant->id,
                'product_name' => $variant->product->name,
                'variant_name' => $variant->displayName(),
                'on_hand' => $onHand,
                'threshold' => $threshold,
                'is_out' => $onHand <= 0,
            ];
        }

        usort($lines, static fn (array $a, array $b): int => $a['on_hand'] <=> $b['on_hand']);

        return [
            'count' => count($lines),
            'lines' => array_slice($lines, 0, $limit),
        ];
    }

    /**
     * @param  array{overdue: list<Cheque>, due: list<Cheque>, total_overdue: int, total_due: int}  $side
     * @return array{overdue_count: int, overdue_total: array{value: int, formatted: string}, due_count: int, due_total: array{value: int, formatted: string}}
     */
    private function chequeSide(array $side): array
    {
        return [
            'overdue_count' => count($side['overdue']),
            'overdue_total' => Money::toArray($side['total_overdue']),
            'due_count' => count($side['due']),
            'due_total' => Money::toArray($side['total_due']),
        ];
    }

    /**
     * Statuses that mean the device is still the shop's problem.
     *
     * `abandoned` is excluded even though the device is physically present: it has its
     * own card, and counting it here would inflate "in progress" with work nobody is
     * doing.
     *
     * @return list<TicketStatus>
     */
    private function openTicketStatuses(): array
    {
        return [
            TicketStatus::Queued,
            TicketStatus::Diagnosing,
            TicketStatus::AwaitingApproval,
            TicketStatus::AwaitingParts,
            TicketStatus::Repairing,
            TicketStatus::Ready,
        ];
    }

    private function intOf(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
