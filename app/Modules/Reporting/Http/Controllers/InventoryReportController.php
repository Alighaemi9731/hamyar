<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Models\User;
use App\Modules\Reporting\Services\InventoryReports;
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
 * Stock valuation and dead stock — the two questions asked of a shelf.
 *
 * ## One date, not a range
 *
 * Every other report in this module takes a Jalali *range*, and this one deliberately does
 * not: «موجودی» is a figure at an instant, and "stock on hand between Mordad and
 * Shahrivar" is not a question. What an accountant does ask is "what was it worth on the
 * last day of the year", so the filter is a single as-of date — which only works at all
 * because on-hand is a SUM over movements rather than a stored total (golden rule 3).
 *
 * Dead stock takes a threshold in days instead, because "not moved for ninety days" is how
 * the question is phrased at the counter.
 *
 * ## Cost is money, so the screen is gated
 *
 * A valuation IS a money figure — there is no version of it with the cost removed — so it
 * follows the profit report's shape and refuses rather than strips. Dead stock without
 * value would still list the lines, but a shop deciding what to discount needs to know
 * what it is sitting on, so both cuts are behind the same gate.
 */
final class InventoryReportController extends Controller
{
    private const CUTS = ['valuation', 'dead'];

    private const DEAD_DAYS = [30, 60, 90, 180, 365];

    public function index(Request $request, InventoryReports $reports): Response
    {
        $this->authorise($request);

        $cut = $this->cut($request);
        $asOf = $this->asOf($request);
        $days = $this->days($request);

        $rows = $this->rows($reports, $cut, $asOf, $days);

        return Inertia::render('Reporting::Reports/Inventory', [
            'cut' => $cut,
            'as_of' => $asOf->toIso8601String(),
            'as_of_jalali' => Jalali::format($asOf),
            'days' => $days,
            'day_options' => self::DEAD_DAYS,
            'can_export' => $request->user() instanceof User && $request->user()->can('reporting.export'),
            'totals' => $this->totals($rows),
            'rows' => $this->payloadRows($rows, $cut),
        ]);
    }

    public function export(Request $request, InventoryReports $reports): BinaryFileResponse
    {
        $this->authorise($request);

        $user = $request->user();

        abort_unless($user instanceof User && $user->can('reporting.export'), 403);

        $cut = $this->cut($request);
        $asOf = $this->asOf($request);
        $days = $this->days($request);

        $headings = $cut === 'valuation'
            ? ['عنوان', 'نوع', 'تعداد', 'بهای واحد (ریال)', 'بهای واحد', 'ارزش (ریال)', 'ارزش']
            : ['عنوان', 'نوع', 'تعداد', 'روز بی‌حرکت', 'آخرین خروج', 'ارزش (ریال)', 'ارزش'];

        $sheet = [];

        foreach ($this->rows($reports, $cut, $asOf, $days) as $row) {
            $kind = ($row['kind'] ?? '') === 'serialized' ? 'دستگاه' : 'کالا';
            $value = $this->intOf($row['value'] ?? 0);

            $sheet[] = $cut === 'valuation'
                ? [
                    $this->stringOf($row['label'] ?? ''),
                    $kind,
                    $this->intOf($row['quantity'] ?? 0),
                    $this->intOf($row['unit_cost'] ?? 0),
                    Money::toArray($this->intOf($row['unit_cost'] ?? 0))['formatted'],
                    $value,
                    Money::toArray($value)['formatted'],
                ]
                : [
                    $this->stringOf($row['label'] ?? ''),
                    $kind,
                    $this->intOf($row['quantity'] ?? 0),
                    $this->intOf($row['idle_days'] ?? 0),
                    Jalali::format($this->stringOf($row['last_out'] ?? '')),
                    $value,
                    Money::toArray($value)['formatted'],
                ];
        }

        $name = sprintf('inventory-%s-%s.xlsx', $cut, $asOf->toDateString());

        return Excel::download(new ArraySheet($headings, $sheet), $name);
    }

    private function authorise(Request $request): void
    {
        $user = $request->user();

        abort_unless($user instanceof User && $user->can('reporting.view'), 403);

        /*
        | Cost, from either direction. `inventory.view_cost` is the permission a
        | Warehousekeeper holds precisely so they can price what they count; asking only
        | for the back-office one would hand the stocktake to the accountant and leave
        | the person doing it guessing. `ReportCatalogue` asks the same pair, so the
        | index and the screen cannot disagree about who may look.
        */
        abort_unless($user->can('reporting.view_financial') || $user->can('inventory.view_cost'), 403);
    }

    private function cut(Request $request): string
    {
        $cut = $request->string('cut')->value();

        return in_array($cut, self::CUTS, true) ? $cut : 'valuation';
    }

    private function asOf(Request $request): CarbonImmutable
    {
        $value = $request->string('as_of')->value();

        return $value === '' ? CarbonImmutable::now() : Jalali::endOfDay($value);
    }

    /**
     * The idle threshold, clamped to the offered options.
     *
     * Clamped rather than validated: a stale bookmark asking for 45 days should get the
     * nearest sensible report, not an error page. Anything unrecognised falls back to 90,
     * which is the figure the counter uses when nobody has decided.
     */
    private function days(Request $request): int
    {
        $days = $request->integer('days');

        return in_array($days, self::DEAD_DAYS, true) ? $days : 90;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(InventoryReports $reports, string $cut, CarbonImmutable $asOf, int $days): array
    {
        return $cut === 'dead' ? $reports->deadStock($days) : $reports->valuation($asOf);
    }

    /**
     * The split, because two kinds of stock are two kinds of asset.
     *
     * A single «ارزش کل» would let a shop with forty handsets and no accessories read the
     * same as one with neither, and the accountant asks for the two separately.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function totals(array $rows): array
    {
        $value = 0;
        $devices = 0;
        $items = 0;
        $deviceValue = 0;

        foreach ($rows as $row) {
            $rowValue = is_numeric($row['value'] ?? null) ? (int) $row['value'] : 0;
            $quantity = is_numeric($row['quantity'] ?? null) ? (int) $row['quantity'] : 0;

            $value += $rowValue;

            if (($row['kind'] ?? '') === 'serialized') {
                $devices += $quantity;
                $deviceValue += $rowValue;

                continue;
            }

            $items += $quantity;
        }

        return [
            'value' => Money::toArray($value),
            'device_value' => Money::toArray($deviceValue),
            'devices' => $devices,
            'items' => $items,
            'lines' => count($rows),
        ];
    }

    private function intOf(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private function stringOf(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function payloadRows(array $rows, string $cut): array
    {
        $shaped = [];

        foreach ($rows as $row) {
            $line = [
                'label' => is_scalar($row['label'] ?? null) ? (string) $row['label'] : '',
                'kind' => is_scalar($row['kind'] ?? null) ? (string) $row['kind'] : 'standard',
                'quantity' => is_numeric($row['quantity'] ?? null) ? (int) $row['quantity'] : 0,
                'value' => Money::toArray(is_numeric($row['value'] ?? null) ? (int) $row['value'] : 0),
            ];

            if ($cut === 'valuation') {
                $line['unit_cost'] = Money::toArray(is_numeric($row['unit_cost'] ?? null) ? (int) $row['unit_cost'] : 0);
            } else {
                $line['idle_days'] = is_numeric($row['idle_days'] ?? null) ? (int) $row['idle_days'] : 0;
                $line['last_out'] = Jalali::format(is_scalar($row['last_out'] ?? null) ? (string) $row['last_out'] : null);
            }

            $shaped[] = $line;
        }

        return $shaped;
    }
}
