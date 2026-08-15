<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Models\User;
use App\Modules\Reporting\Services\FinancialReports;
use App\Modules\Reporting\Services\ReportPeriod;
use App\Modules\Reporting\Services\SavedFilters;
use App\Modules\Reporting\Support\ReportAccess;
use App\Support\Jalali;
use App\Support\Money;
use App\Support\Spreadsheet\ArraySheet;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * The money side: who owes, what falls due, and which instalments were missed.
 *
 * ## Three cuts, three gates
 *
 * Unlike the sales and profit screens, the cuts here are not variations on one question and
 * they do not share a permission. A Cashier holds `crm.view_balance` and `cheques.view` and
 * chases debts at the counter all day; they have no business in another department's
 * instalment book unless the shop gave them `installments.view`. So the gate is checked
 * **per cut**, against the same `ReportAccess` predicate `ReportCatalogue` uses to decide
 * whether to list the row at all.
 *
 * The consequence is deliberate: a viewer allowed one cut sees only that cut's tab. Offering
 * a tab that 403s is the same defect as listing a report that 403s, one screen further in.
 *
 * ## Aging takes a date; the other two take a range
 *
 * «چه کسی چقدر بدهکار است» is a figure at an instant, like a stock valuation — there is no
 * such thing as a balance "between Mordad and Shahrivar". The cheque calendar and the
 * instalment book are both about *when things fall due*, so they take the module's ordinary
 * Jalali range.
 */
final class FinancialReportController extends Controller
{
    private const CUTS = ['aging', 'cheques', 'installments'];

    private const DIRECTIONS = [FinancialReports::RECEIVABLE, FinancialReports::PAYABLE];

    public function index(Request $request, FinancialReports $reports, SavedFilters $presets): Response
    {
        $user = $this->viewer($request);

        $cut = $this->cut($request, $user);
        $period = ReportPeriod::fromJalali($request->string('from')->value(), $request->string('to')->value());
        $asOf = $this->asOf($request);
        $direction = $this->direction($request);

        return Inertia::render('Reporting::Reports/Financial', [
            'cut' => $cut,
            'cuts' => $this->availableCuts($user),
            'direction' => $direction,
            'period' => $period->toArray(),
            'as_of' => $asOf->toIso8601String(),
            'as_of_jalali' => Jalali::format($asOf),
            'can_export' => $user->can('reporting.export'),
            'report_key' => 'financial',
            'presets' => $presets->forReport($user, 'financial'),
            ...$this->payload($reports, $cut, $period, $asOf, $direction),
        ]);
    }

    public function export(Request $request, FinancialReports $reports): BinaryFileResponse
    {
        $user = $this->viewer($request);

        abort_unless($user->can('reporting.export'), 403);

        $cut = $this->cut($request, $user);
        $period = ReportPeriod::fromJalali($request->string('from')->value(), $request->string('to')->value());
        $asOf = $this->asOf($request);
        $direction = $this->direction($request);

        [$headings, $sheet] = match ($cut) {
            'cheques' => $this->chequeSheet($reports->chequeCalendar($period)),
            'installments' => $this->installmentSheet($reports->installmentsBook($period)),
            default => $this->agingSheet($reports->aging($asOf, $direction)),
        };

        $stamp = $cut === 'aging' ? $asOf->toDateString() : $period->from->toDateString();

        return Excel::download(new ArraySheet($headings, $sheet), sprintf('financial-%s-%s.xlsx', $cut, $stamp));
    }

    /**
     * `reporting.view` is the door; the cut's own gate is checked in {@see cut()}.
     */
    private function viewer(Request $request): User
    {
        $user = $request->user();

        abort_unless($user instanceof User && $user->can('reporting.view'), 403);

        return $user;
    }

    /**
     * The requested cut, refused if this viewer may not see it.
     *
     * Not silently redirected to one they may see: a bookmark to the instalment book opened
     * by somebody without `installments.view` should say no, not quietly show them the aging
     * report under the heading they asked for.
     */
    private function cut(Request $request, User $user): string
    {
        $requested = $request->string('cut')->value();
        $cut = in_array($requested, self::CUTS, true) ? $requested : $this->firstAllowedCut($user);

        abort_unless(ReportAccess::allows($user, $this->gateFor($cut)), 403);

        return $cut;
    }

    /**
     * What to open when no cut was named — the first one this viewer may actually see.
     *
     * A viewer with no cut at all gets `aging`, which then 403s in the caller. That is the
     * correct answer for somebody who reached this route with none of the three permissions,
     * and `ReportCatalogue` will not have offered them a link to it.
     */
    private function firstAllowedCut(User $user): string
    {
        foreach (self::CUTS as $cut) {
            if (ReportAccess::allows($user, $this->gateFor($cut))) {
                return $cut;
            }
        }

        return 'aging';
    }

    private function gateFor(string $cut): string
    {
        return match ($cut) {
            'cheques' => 'cheques',
            'installments' => 'installments',
            default => 'balances',
        };
    }

    /**
     * @return list<array{key: string, label: string}>
     */
    private function availableCuts(User $user): array
    {
        $labels = [
            'aging' => 'مانده طرف‌ها',
            'cheques' => 'تقویم چک‌ها',
            'installments' => 'دفتر اقساط',
        ];

        $cuts = [];

        foreach (self::CUTS as $cut) {
            if (ReportAccess::allows($user, $this->gateFor($cut))) {
                $cuts[] = ['key' => $cut, 'label' => $labels[$cut]];
            }
        }

        return $cuts;
    }

    private function asOf(Request $request): CarbonImmutable
    {
        $value = $request->string('as_of')->value();

        return $value === '' ? CarbonImmutable::now() : Jalali::endOfDay($value);
    }

    private function direction(Request $request): string
    {
        $direction = $request->string('direction')->value();

        return in_array($direction, self::DIRECTIONS, true) ? $direction : FinancialReports::RECEIVABLE;
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(
        FinancialReports $reports,
        string $cut,
        ReportPeriod $period,
        CarbonImmutable $asOf,
        string $direction,
    ): array {
        return match ($cut) {
            'cheques' => $this->chequePayload($reports, $period, $asOf),
            'installments' => $this->installmentPayload($reports, $period),
            default => $this->agingPayload($reports, $asOf, $direction),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function agingPayload(FinancialReports $reports, CarbonImmutable $asOf, string $direction): array
    {
        $rows = $reports->aging($asOf, $direction);

        $totals = ['total' => 0, 'current' => 0, 'days_60' => 0, 'days_90' => 0, 'older' => 0, 'credit' => 0];

        foreach ($rows as $row) {
            foreach (array_keys($totals) as $key) {
                $totals[$key] += $row[$key];
            }
        }

        return [
            'rows' => array_map(fn (array $row): array => [
                'party_id' => $row['party_id'],
                'name' => $row['name'],
                'kind' => $row['kind'],
                'total' => Money::toArray($row['total']),
                'current' => Money::toArray($row['current']),
                'days_60' => Money::toArray($row['days_60']),
                'days_90' => Money::toArray($row['days_90']),
                'older' => Money::toArray($row['older']),
                'credit' => Money::toArray($row['credit']),
            ], $rows),
            'totals' => [
                ...array_map(fn (int $value): array => Money::toArray($value), $totals),
                'parties' => count($rows),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function chequePayload(FinancialReports $reports, ReportPeriod $period, CarbonImmutable $asOf): array
    {
        $rows = $reports->chequeCalendar($period);

        $incoming = 0;
        $outgoing = 0;

        foreach ($rows as $row) {
            $incoming += $row['incoming'];
            $outgoing += $row['outgoing'];
        }

        $overdue = $reports->overdueCheques($asOf);

        return [
            'rows' => array_map(fn (array $row): array => [
                'due_date' => Jalali::format($row['due_date']),
                'incoming' => Money::toArray($row['incoming']),
                'incoming_count' => $row['incoming_count'],
                'outgoing' => Money::toArray($row['outgoing']),
                'outgoing_count' => $row['outgoing_count'],
                'net' => Money::toArray($row['net']),
                'cleared' => Money::toArray($row['cleared']),
                'bounced' => Money::toArray($row['bounced']),
            ], $rows),
            'totals' => [
                'incoming' => Money::toArray($incoming),
                'outgoing' => Money::toArray($outgoing),
                'net' => Money::toArray($incoming - $outgoing),
                'days' => count($rows),
            ],
            'overdue' => [
                'incoming' => Money::toArray($overdue['incoming']),
                'incoming_count' => $overdue['incoming_count'],
                'outgoing' => Money::toArray($overdue['outgoing']),
                'outgoing_count' => $overdue['outgoing_count'],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function installmentPayload(FinancialReports $reports, ReportPeriod $period): array
    {
        $rows = $reports->installmentsBook($period);

        $due = 0;
        $collected = 0;
        $outstanding = 0;
        $overdueAmount = 0;
        $overdueCount = 0;

        foreach ($rows as $row) {
            $due += $row['amount'];
            $collected += $row['collected'];
            $outstanding += $row['outstanding'];

            if ($row['overdue_days'] > 0) {
                $overdueAmount += $row['outstanding'];
                $overdueCount++;
            }
        }

        return [
            'rows' => array_map(fn (array $row): array => [
                'plan_number' => $row['plan_number'],
                'party' => $row['party'],
                'sequence' => $row['sequence'],
                'due_at' => Jalali::format($row['due_at']),
                'amount' => Money::toArray($row['amount']),
                'collected' => Money::toArray($row['collected']),
                'outstanding' => Money::toArray($row['outstanding']),
                'status' => $row['status'],
                'overdue_days' => $row['overdue_days'],
            ], $rows),
            'totals' => [
                'due' => Money::toArray($due),
                'collected' => Money::toArray($collected),
                'outstanding' => Money::toArray($outstanding),
                'overdue' => Money::toArray($overdueAmount),
                'overdue_count' => $overdueCount,
                'rows' => count($rows),
            ],
        ];
    }

    /**
     * Money leaves as two columns — integer rial and the formatted string — and the string
     * comes from the same `Money::toArray()` the screen calls, so a spreadsheet can never
     * quote rial while the page quotes toman.
     *
     * @param  list<array{party_id: int, name: string, kind: string, total: int, current: int, days_60: int, days_90: int, older: int, credit: int}>  $rows
     * @return array{0: list<string>, 1: list<list<int|string>>}
     */
    private function agingSheet(array $rows): array
    {
        $headings = [
            'طرف حساب', 'نوع', 'کل (ریال)', 'کل',
            'جاری', 'تا ۶۰ روز', 'تا ۹۰ روز', 'بیش از ۹۰ روز', 'بستانکاری',
        ];

        $sheet = [];

        foreach ($rows as $row) {
            $sheet[] = [
                $row['name'],
                $row['kind'],
                $row['total'],
                Money::toArray($row['total'])['formatted'],
                Money::toArray($row['current'])['formatted'],
                Money::toArray($row['days_60'])['formatted'],
                Money::toArray($row['days_90'])['formatted'],
                Money::toArray($row['older'])['formatted'],
                Money::toArray($row['credit'])['formatted'],
            ];
        }

        return [$headings, $sheet];
    }

    /**
     * @param  list<array{due_date: string, incoming: int, incoming_count: int, outgoing: int, outgoing_count: int, net: int, cleared: int, bounced: int}>  $rows
     * @return array{0: list<string>, 1: list<list<int|string>>}
     */
    private function chequeSheet(array $rows): array
    {
        $headings = [
            'سررسید', 'تعداد دریافتی', 'دریافتی (ریال)', 'دریافتی',
            'تعداد پرداختی', 'پرداختی (ریال)', 'پرداختی', 'خالص (ریال)', 'خالص', 'وصول‌شده', 'برگشتی',
        ];

        $sheet = [];

        foreach ($rows as $row) {
            $sheet[] = [
                Jalali::format($row['due_date']),
                $row['incoming_count'],
                $row['incoming'],
                Money::toArray($row['incoming'])['formatted'],
                $row['outgoing_count'],
                $row['outgoing'],
                Money::toArray($row['outgoing'])['formatted'],
                $row['net'],
                Money::toArray($row['net'])['formatted'],
                Money::toArray($row['cleared'])['formatted'],
                Money::toArray($row['bounced'])['formatted'],
            ];
        }

        return [$headings, $sheet];
    }

    /**
     * @param  list<array{plan_number: string, party: string, sequence: int, due_at: string, amount: int, collected: int, outstanding: int, status: string, overdue_days: int}>  $rows
     * @return array{0: list<string>, 1: list<list<int|string>>}
     */
    private function installmentSheet(array $rows): array
    {
        $headings = [
            'شماره قرارداد', 'طرف حساب', 'قسط', 'سررسید',
            'مبلغ (ریال)', 'مبلغ', 'وصول‌شده (ریال)', 'وصول‌شده', 'مانده (ریال)', 'مانده', 'وضعیت', 'روز تأخیر',
        ];

        $sheet = [];

        foreach ($rows as $row) {
            $sheet[] = [
                $row['plan_number'],
                $row['party'],
                $row['sequence'],
                Jalali::format($row['due_at']),
                $row['amount'],
                Money::toArray($row['amount'])['formatted'],
                $row['collected'],
                Money::toArray($row['collected'])['formatted'],
                $row['outstanding'],
                Money::toArray($row['outstanding'])['formatted'],
                $row['status'],
                $row['overdue_days'],
            ];
        }

        return [$headings, $sheet];
    }
}
