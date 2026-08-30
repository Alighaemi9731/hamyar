<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Models\User;
use App\Modules\Reporting\Http\Concerns\MetersExports;
use App\Modules\Reporting\Services\OperationsReports;
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
 * SMS usage and what it cost.
 *
 * The wallet balance rides along with the usage rows rather than living on its own screen:
 * a shop reading «این ماه ۸۰۰ هزار تومان پیامک فرستادیم» immediately asks «چقدر مانده؟», and
 * making them navigate for the second half of one thought is how they stop asking the first.
 */
final class OperationsReportController extends Controller
{
    use MetersExports;

    public function index(Request $request, OperationsReports $reports, SavedFilters $presets): Response
    {
        $this->authorise($request);

        $period = ReportPeriod::fromJalali($request->string('from')->value(), $request->string('to')->value());
        $rows = $reports->smsUsage($period);
        $wallet = $reports->smsWallet($period);

        return Inertia::render('Reporting::Reports/Operations', [
            'period' => $period->toArray(),
            'can_export' => $request->user() instanceof User && $request->user()->can('reporting.export'),
            'report_key' => 'operations',
            'presets' => $presets->forReport($request->user(), 'operations'),
            'rows' => array_map(fn (array $row): array => [
                'label' => $row['label'],
                'sent' => $row['sent'],
                'failed' => $row['failed'],
                'suppressed' => $row['suppressed'],
                'queued' => $row['queued'],
                'messages' => $row['messages'],
                'segments' => $row['segments'],
                'cost' => Money::toArray($row['cost']),
            ], $rows),
            'totals' => $this->totals($rows),
            'wallet' => [
                'balance' => Money::toArray($wallet['balance']),
                'topups' => Money::toArray($wallet['topups']),
                'charges' => Money::toArray($wallet['charges']),
                'refunds' => Money::toArray($wallet['refunds']),
            ],
        ]);
    }

    public function export(Request $request, OperationsReports $reports): BinaryFileResponse
    {
        $this->authorise($request);

        $user = $request->user();

        abort_unless($user instanceof User && $user->can('reporting.export'), 403);

        $period = ReportPeriod::fromJalali($request->string('from')->value(), $request->string('to')->value());

        $headings = ['قالب', 'ارسال‌شده', 'ناموفق', 'مسدود', 'در صف', 'کل پیامک', 'بخش', 'هزینه (ریال)', 'هزینه'];

        $sheet = [];

        foreach ($reports->smsUsage($period) as $row) {
            $sheet[] = [
                $row['label'],
                $row['sent'],
                $row['failed'],
                $row['suppressed'],
                $row['queued'],
                $row['messages'],
                $row['segments'],
                $row['cost'],
                Money::toArray($row['cost'])['formatted'],
            ];
        }

        $name = sprintf('sms-usage-%s.xlsx', $period->from->toDateString());

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
        abort_unless(ReportAccess::showsMessaging($user), 403);
    }

    /**
     * @param  list<array{template: string, label: string, sent: int, failed: int, suppressed: int, queued: int, messages: int, segments: int, cost: int}>  $rows
     * @return array<string, mixed>
     */
    private function totals(array $rows): array
    {
        $messages = 0;
        $segments = 0;
        $cost = 0;
        $failed = 0;

        foreach ($rows as $row) {
            $messages += $row['messages'];
            $segments += $row['segments'];
            $cost += $row['cost'];
            $failed += $row['failed'];
        }

        return [
            'messages' => $messages,
            'segments' => $segments,
            'failed' => $failed,
            'cost' => Money::toArray($cost),
            'templates' => count($rows),
        ];
    }
}
