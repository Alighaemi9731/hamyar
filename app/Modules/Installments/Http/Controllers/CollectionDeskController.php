<?php

declare(strict_types=1);

namespace App\Modules\Installments\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\CRM\Models\Account;
use App\Modules\Installments\Models\InstallmentPlan;
use App\Modules\Installments\Models\InstallmentRow;
use App\Modules\Installments\Services\CollectInstallment;
use App\Modules\Installments\Services\InstallmentMaths;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * The collection desk — who owes what today, and taking it.
 *
 * ## Overdue first, then due, then the rest
 *
 * The same shape as the cheque calendar and for the same reason: a shop chasing payments
 * has a short list of people to ring this morning, and a screen sorted by contract number
 * makes them build that list by hand every day.
 *
 * The late fee shown against a row is computed live rather than stored. It is a
 * calculation against the clock until somebody collects — see
 * `docs/specs/installment-collection.md` §5 on why it becomes a ledger fact only at the
 * moment money changes hands.
 */
final class CollectionDeskController extends Controller
{
    public function index(Request $request, InstallmentMaths $maths, CollectInstallment $collect): Response
    {
        $this->authorize('viewAny', InstallmentPlan::class);

        $today = CarbonImmutable::now();

        $rows = InstallmentRow::query()
            ->with(['plan.party:id,name'])
            ->whereIn('status', [InstallmentRow::STATUS_PENDING, InstallmentRow::STATUS_OVERDUE])
            ->orderBy('due_at')
            ->limit(200)
            ->get();

        $overdue = [];
        $due = [];

        foreach ($rows as $row) {
            $plan = $row->plan;

            if (! $plan instanceof InstallmentPlan) {
                continue;
            }

            $entry = [
                'id' => $row->id,
                'plan_id' => $plan->id,
                'plan_number' => $plan->number,
                'party_name' => $plan->party?->name,
                'sequence' => $row->sequence,
                'of' => $plan->installment_count,
                'due_at' => $row->due_at->toIso8601String(),
                'amount' => Money::toArray($row->amount),
                'outstanding' => Money::toArray($collect->outstandingOn($row)),
                'late_fee' => Money::toArray($maths->lateFeeOn($row, $today)),
                'days_late' => max(0, (int) $row->due_at->startOfDay()->diffInDays($today->startOfDay(), false)),
            ];

            $row->due_at->startOfDay()->lessThan($today->startOfDay())
                ? $overdue[] = $entry
                : $due[] = $entry;
        }

        return Inertia::render('Installments::Collections/Index', [
            'overdue' => $overdue,
            'due' => array_slice($due, 0, 40),
            'totals' => [
                'overdue' => Money::toArray(array_sum(array_column(array_column($overdue, 'outstanding'), 'value'))),
                'fees' => Money::toArray(array_sum(array_column(array_column($overdue, 'late_fee'), 'value'))),
            ],
            'accounts' => Account::query()
                ->whereIn('type', Account::moneyHoldingTypes())
                ->where('is_active', true)
                ->orderByDesc('is_default')
                ->get(['id', 'name'])
                ->map(fn (Account $a): array => ['id' => $a->id, 'name' => $a->name])
                ->all(),
        ]);
    }

    public function collect(Request $request, InstallmentRow $row, CollectInstallment $collector): RedirectResponse
    {
        $this->authorize('update', $row->plan ?? InstallmentPlan::class);

        $data = $request->validate([
            'account_id' => ['required', 'integer', 'exists:accounts,id'],
            'amount' => ['required', 'integer', 'min:1'],
            'method' => ['nullable', 'string', 'max:30'],
            'reference' => ['nullable', 'string', 'max:160'],
        ]);

        /** @var Account $account */
        $account = Account::query()->findOrFail($data['account_id']);

        try {
            $collector->collect(
                $row, $account, (int) $data['amount'],
                actorId: $request->user()?->id,
                method: $data['method'] ?? 'cash',
                reference: $data['reference'] ?? null,
            );
        } catch (RuntimeException $exception) {
            return back()->withErrors(['collect' => $exception->getMessage()]);
        }

        return back()->with('status', 'قسط دریافت شد.');
    }
}
