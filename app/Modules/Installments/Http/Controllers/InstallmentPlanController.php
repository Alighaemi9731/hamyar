<?php

declare(strict_types=1);

namespace App\Modules\Installments\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Installments\Http\Requests\InstallmentPlanRequest;
use App\Modules\Installments\Models\InstallmentPlan;
use App\Modules\Installments\Models\InstallmentRow;
use App\Modules\Installments\Services\CreateInstallmentPlan;
use App\Modules\Installments\Services\InstallmentScheduler;
use App\Modules\Sales\Models\SalesInvoice;
use App\Support\Digits;
use App\Support\Jalali;
use App\Support\Money;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use RuntimeException;

/**
 * The instalment wizard, and the contract it prints.
 *
 * ## `preview` exists so nobody signs a schedule they have not seen
 *
 * The wizard shows every row — date and rial — before anything is written, because the
 * question a customer asks is not "what is the total" but "what am I paying on the
 * fifteenth of each month". Computing it server-side rather than mirroring the
 * arithmetic in the browser is affordable here in a way it is not on the POS: a plan is
 * written once per sale, not a hundred times a day, and one round trip per keystroke of
 * a four-field form is not a latency anybody notices.
 */
final class InstallmentPlanController extends Controller
{
    public function create(Request $request, SalesInvoice $invoice): Response
    {
        $this->authorize('update', $invoice);

        if (! $invoice->isFinal()) {
            abort(404);
        }

        $invoice->load(['party:id,name', 'installmentPlan']);

        if ($invoice->installmentPlan !== null) {
            return $this->show($request, $invoice->installmentPlan);
        }

        return Inertia::render('Installments::Plans/Create', [
            'invoice' => [
                'id' => $invoice->id,
                'number' => $invoice->number,
                'party_name' => $invoice->party?->name,
                'total' => Money::toArray($invoice->total),
                'paid_total' => Money::toArray($invoice->paid_total),
                // What the wizard actually divides. The down payment has already been
                // taken at the till, so this is what is left.
                'financed' => Money::toArray($invoice->outstanding()),
            ],
            'defaults' => [
                'count' => 6,
                'profit_percent' => 0,
                'interval_months' => 1,
                // A month from today, which is what a shop writing a contract this
                // afternoon almost always means.
                'first_due' => Jalali::format(Jalali::addMonths(now(), 1), Jalali::DATE, false),
            ],
        ]);
    }

    /**
     * The schedule this form would produce, without writing anything.
     */
    public function preview(Request $request, SalesInvoice $invoice, InstallmentScheduler $scheduler): JsonResponse
    {
        $this->authorize('update', $invoice);

        $request->merge(['first_due' => Digits::toLatin($request->string('first_due')->value())]);

        $validated = $request->validate([
            'count' => ['required', 'integer', 'min:1', 'max:'.InstallmentScheduler::MAX_INSTALLMENTS],
            'profit_percent' => ['required', 'integer', 'min:0', 'max:100'],
            'interval_months' => ['required', 'integer', 'min:1', 'max:12'],
            // The same shape rule as the submit — see InstallmentPlanRequest.
            'first_due' => ['required', 'string', 'max:20', 'regex:/^\d{4}\/\d{1,2}\/\d{1,2}$/'],
        ]);

        try {
            $schedule = $scheduler->schedule(
                $invoice->outstanding(),
                (int) $validated['count'],
                (int) $validated['profit_percent'],
                Jalali::startOfDay((string) $validated['first_due']),
                (int) $validated['interval_months'],
            );
        } catch (InvalidArgumentException $exception) {
            // A form still being filled in, not a failure. Reported as a message the
            // wizard shows in place of the table rather than as a 422.
            return response()->json(['error' => $exception->getMessage()]);
        }

        return response()->json([
            'principal' => Money::toArray($schedule['principal']),
            'profit_amount' => Money::toArray($schedule['profit_amount']),
            'total_payable' => Money::toArray($schedule['total_payable']),
            'rows' => array_map(fn (array $row): array => [
                'sequence' => $row['sequence'],
                'due_at' => $row['due_at']->toIso8601String(),
                'due_at_jalali' => Jalali::format($row['due_at'], Jalali::DATE, false),
                'amount' => Money::toArray($row['amount']),
            ], $schedule['rows']),
        ]);
    }

    public function store(InstallmentPlanRequest $request, SalesInvoice $invoice, CreateInstallmentPlan $creator): RedirectResponse
    {
        $this->authorize('update', $invoice);

        try {
            $plan = $creator->fromInvoice(
                invoice: $invoice,
                count: $request->integer('count'),
                profitPercent: $request->integer('profit_percent'),
                firstDueAt: Jalali::startOfDay($request->string('first_due')->value()),
                intervalMonths: $request->integer('interval_months'),
                guarantorPartyId: $request->integer('guarantor_party_id') ?: null,
                notes: $request->string('notes')->value() ?: null,
                actorId: $request->user()?->id,
            );
        } catch (RuntimeException|InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['count' => $exception->getMessage()]);
        }

        return redirect()
            ->route('installments.plans.show', $plan)
            ->with('success', "قرارداد اقساطی {$plan->number} ثبت شد.");
    }

    public function show(Request $request, InstallmentPlan $plan): Response
    {
        $this->authorize('view', $plan);

        $plan->load(['rows', 'party:id,name', 'guarantor:id,name', 'invoice:id,number', 'branch:id,name']);

        return Inertia::render('Installments::Plans/Show', [
            'plan' => $this->payload($plan),
        ]);
    }

    /**
     * The signed contract, on paper.
     */
    public function print(Request $request, InstallmentPlan $plan, TenantContext $context): Response
    {
        $this->authorize('view', $plan);

        $plan->load(['rows', 'party.contacts', 'guarantor.contacts', 'invoice:id,number', 'branch:id,name']);

        return Inertia::render('Installments::Plans/Print', [
            'plan' => $this->payload($plan),
            'shop' => [
                // The shop's own name, never a hostname (golden rule 1b): the apex
                // domain is not chosen, and a signed contract is the worst place to
                // hardcode one.
                'name' => $context->tenant()?->name,
                'branch' => $plan->branch->name,
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(InstallmentPlan $plan): array
    {
        return [
            'id' => $plan->id,
            'number' => $plan->number,
            'status' => $plan->status,
            'party' => ['id' => $plan->party_id, 'name' => $plan->party->name],
            'guarantor' => $plan->guarantor === null ? null : [
                'id' => $plan->guarantor->id,
                'name' => $plan->guarantor->name,
            ],
            'invoice' => $plan->invoice === null ? null : [
                'id' => $plan->invoice->id,
                'number' => $plan->invoice->number,
            ],
            'down_payment' => Money::toArray($plan->down_payment),
            'principal' => Money::toArray($plan->principal),
            'profit_percent' => $plan->profit_percent,
            'profit_amount' => Money::toArray($plan->profit_amount),
            'total_payable' => Money::toArray($plan->total_payable),
            'contract_total' => Money::toArray($plan->contractTotal()),
            'installment_count' => $plan->installment_count,
            'interval_months' => $plan->interval_months,
            'first_due_at' => $plan->first_due_at->toIso8601String(),
            'notes' => $plan->notes,
            'rows' => $plan->rows->map(fn (InstallmentRow $row): array => [
                'id' => $row->id,
                'sequence' => $row->sequence,
                'due_at' => $row->due_at->toIso8601String(),
                'amount' => Money::toArray($row->amount),
                'status' => $row->status,
            ])->all(),
        ];
    }
}
