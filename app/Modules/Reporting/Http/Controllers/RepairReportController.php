<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Services\BranchContext;
use App\Modules\Reporting\Http\Concerns\MetersExports;
use App\Modules\Reporting\Services\RepairReports;
use App\Modules\Reporting\Services\ReportPeriod;
use App\Modules\Reporting\Services\SavedFilters;
use App\Modules\Reporting\Support\ReportAccess;
use App\Support\Money;
use App\Support\Spreadsheet\ArraySheet;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * The bench, measured.
 *
 * ## Parts cost is behind the margin gate; the counts are not
 *
 * How many jobs somebody finished and how long they took are operational facts a manager
 * needs and a technician can see. What the parts cost the shop is a money figure, and it
 * obeys the same `ReportAccess` predicate as every other cost in this module — so the
 * column is dropped rather than the screen refused, which is the sales report's shape
 * rather than the profit report's. The difference is that without the money there is
 * still a report here: «چند دستگاه تحویل شد و چقدر طول کشید» stands on its own.
 */
final class RepairReportController extends Controller
{
    use MetersExports;

    public function index(Request $request, RepairReports $reports, SavedFilters $presets): Response
    {
        $this->authorise($request);

        $period = $this->period($request);
        $user = $request->user() instanceof User ? $request->user() : null;
        $showsCost = ReportAccess::showsMargin($user);

        return Inertia::render('Reporting::Reports/Technicians', [
            'report_key' => 'technicians',
            'presets' => $presets->forReport($request->user(), 'technicians'),
            'period' => $period->toArray(),
            'shows_cost' => $showsCost,
            'can_export' => $user instanceof User && $user->can('reporting.export'),
            'rows' => $this->payloadRows($reports->technicianPerformance($period, $this->branchIds()), $showsCost),
        ]);
    }

    public function export(Request $request, RepairReports $reports): BinaryFileResponse
    {
        $this->authorise($request);

        $user = $request->user();

        abort_unless($user instanceof User && $user->can('reporting.export'), 403);

        $period = $this->period($request);
        $showsCost = ReportAccess::showsMargin($user);

        $headings = ['تکنسین', 'تحویل‌شده', 'روی میز', 'میانگین زمان (ساعت)'];

        if ($showsCost) {
            $headings = [...$headings, 'هزینه قطعات (ریال)', 'هزینه قطعات'];
        }

        $sheet = [];

        foreach ($reports->technicianPerformance($period, $this->branchIds()) as $row) {
            $line = [
                $row['technician'],
                $row['delivered'],
                $row['open'],
                $row['avg_turnaround_hours'],
            ];

            if ($showsCost) {
                $line = [...$line, $row['parts_cost'], Money::toArray($row['parts_cost'])['formatted']];
            }

            $sheet[] = $line;
        }

        $name = sprintf('technicians-%s-%s.xlsx', $period->from->toDateString(), $period->to->toDateString());

        /*
        | The credit, after the workbook is built and before it is handed over.
        |
        | Counting first would charge for a report that then failed to render — a refusal
        | the shop cannot see the cause of, on the one screen where they were trying to
        | get something out of the system. `reporting.exports` is one credit for all seven
        | reports: a shopkeeper thinks "I exported four things today", not "I exported two
        | sales reports and two tax reports".
        */
        $this->meterExport();

        return Excel::download(new ArraySheet($headings, $sheet), $name);
    }

    private function authorise(Request $request): void
    {
        $user = $request->user();

        abort_unless($user instanceof User && $user->can('reporting.view'), 403);
    }

    private function period(Request $request): ReportPeriod
    {
        return ReportPeriod::fromJalali(
            $request->string('from')->value() ?: null,
            $request->string('to')->value() ?: null,
        );
    }

    /**
     * @param  list<array{technician: string, delivered: int, open: int, avg_turnaround_hours: int, parts_cost: int}>  $rows
     * @return list<array<string, mixed>>
     */
    private function payloadRows(array $rows, bool $showsCost): array
    {
        $shaped = [];

        foreach ($rows as $row) {
            $line = [
                'technician' => $row['technician'],
                'delivered' => $row['delivered'],
                'open' => $row['open'],
                'avg_turnaround_hours' => $row['avg_turnaround_hours'],
            ];

            if ($showsCost) {
                $line['parts_cost'] = Money::toArray($row['parts_cost']);
            }

            $shaped[] = $line;
        }

        return $shaped;
    }

    /**
     * The branches this report covers: the one being viewed, or every branch the viewer is
     * allowed when they are looking at «همه شعب».
     *
     * Resolved here rather than threaded through the private helpers because several of
     * them run per cut, and a parameter on each was four more places for the two halves of
     * the rule to come apart. `BranchAccess` memoises, so the repeat calls are free.
     *
     * Before 10.1 the report controllers passed nothing at all — so a manager restricted to
     * one branch read the whole shop's figures the moment they opened a report. The access
     * floor was enforced on every list screen and on none of the reports.
     *
     * @return list<int>|null
     */
    private function branchIds(): ?array
    {
        return app(BranchContext::class)->scopeIds();
    }
}
