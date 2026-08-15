<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Models\User;
use App\Modules\Reporting\Services\RepairReports;
use App\Modules\Reporting\Services\ReportPeriod;
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
    public function index(Request $request, RepairReports $reports): Response
    {
        $this->authorise($request);

        $period = $this->period($request);
        $user = $request->user() instanceof User ? $request->user() : null;
        $showsCost = ReportAccess::showsMargin($user);

        return Inertia::render('Reporting::Reports/Technicians', [
            'period' => $period->toArray(),
            'shows_cost' => $showsCost,
            'can_export' => $user instanceof User && $user->can('reporting.export'),
            'rows' => $this->payloadRows($reports->technicianPerformance($period), $showsCost),
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

        foreach ($reports->technicianPerformance($period) as $row) {
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
}
