<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Models\Branch;
use App\Modules\Inventory\Services\BranchAccess;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Services\DailyCloseReport;
use App\Support\Digits;
use App\Support\Jalali;
use App\Support\Money;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * گزارش Z — closing the till.
 *
 * One day, one branch, and the figure the person counting notes is comparing against.
 * The date is a Jalali string on the way in and a UTC range on the way to the query
 * (golden rule 5); the range is the shop's own day, not UTC midnight, or a sale made at
 * half past eleven at night lands on tomorrow's report.
 */
final class DailyCloseController extends Controller
{
    public function __construct(private readonly BranchAccess $branches) {}

    public function show(Request $request, DailyCloseReport $report): Response
    {
        $this->authorize('viewAny', SalesInvoice::class);

        /** @var User $user */
        $user = $request->user();

        $date = $this->requestedDate($request);
        $branch = $this->requestedBranch($request, $user);

        $data = $report->forDay(
            from: Jalali::startOfDay($date),
            to: Jalali::endOfDay($date),
            branchId: $branch?->id,
            // Withheld entirely rather than zeroed, like everywhere else profit appears.
            withProfit: $user->can('sales.view_profit'),
        );

        return Inertia::render('Sales::Reports/DailyClose', [
            'date' => $date,
            'branch' => $branch === null ? null : ['id' => $branch->id, 'name' => $branch->name],
            'branches' => $this->branchOptions($user),
            'report' => $this->payload($data),
        ]);
    }

    /**
     * The Jalali day being closed, defaulting to today in the shop's timezone.
     */
    private function requestedDate(Request $request): string
    {
        $given = Digits::toLatin(trim($request->string('date')->value()));

        return preg_match('/^\d{4}\/\d{1,2}\/\d{1,2}$/', $given) === 1
            ? $given
            : Jalali::today(Jalali::DATE, false);
    }

    private function requestedBranch(Request $request, User $user): ?Branch
    {
        if ($request->filled('branch_id') && $this->branches->canUse($user, $request->integer('branch_id'))) {
            /** @var Branch|null $branch */
            $branch = Branch::query()->find($request->integer('branch_id'));

            return $branch;
        }

        // A single-branch shop never picks one, and a multi-branch owner reading the
        // consolidated figure is a legitimate view — so null means "all of them" rather
        // than being an error to correct.
        return $request->has('branch_id') ? null : $this->branches->defaultFor($user);
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    private function branchOptions(User $user): array
    {
        $allowed = $this->branches->allowedFor($user);

        $branches = Branch::query()
            ->where('is_active', true)
            ->when($allowed !== null, fn ($query) => $query->whereIn('id', $allowed))
            ->orderBy('name')
            ->get(['id', 'name'])
            ->all();

        return array_values(array_map(
            fn (Branch $branch): array => ['id' => $branch->id, 'name' => $branch->name],
            $branches,
        ));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function payload(array $data): array
    {
        $money = fn (string $key): array => Money::toArray(is_int($data[$key] ?? null) ? $data[$key] : 0);

        /** @var array{revenue: int, cost: int, profit: int, margin_percent: int, invoice_count: int, returned_revenue: int, returned_cost: int}|null $profit */
        $profit = $data['profit'] ?? null;

        /** @var list<array{method: string, label: string, settles_now: bool, count: int, amount: int}> $payments */
        $payments = $data['payments'] ?? [];

        /** @var list<array{account_id: int|null, name: string, amount: int}> $accounts */
        $accounts = $data['accounts'] ?? [];

        return [
            'invoice_count' => $data['invoice_count'],
            'void_count' => $data['void_count'],
            'return_count' => $data['return_count'],

            'gross' => $money('gross'),
            'discount' => $money('discount'),
            'vat' => $money('vat'),
            'shipping' => $money('shipping'),
            'rounding' => $money('rounding'),
            'net' => $money('net'),
            'refunded' => $money('refunded'),
            'credit_extended' => $money('credit_extended'),
            'expected_cash' => $money('expected_cash'),

            'payments' => array_map(fn (array $row): array => [
                ...$row,
                'amount' => Money::toArray($row['amount']),
            ], $payments),

            'accounts' => array_map(fn (array $row): array => [
                ...$row,
                'amount' => Money::toArray($row['amount']),
            ], $accounts),

            'profit' => $profit === null ? null : [
                'revenue' => Money::toArray($profit['revenue']),
                'cost' => Money::toArray($profit['cost']),
                'profit' => Money::toArray($profit['profit']),
                'margin_percent' => $profit['margin_percent'],
                'returned_revenue' => Money::toArray($profit['returned_revenue']),
            ],
        ];
    }
}
