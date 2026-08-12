<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\CRM\Services\LedgerService;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Models\ProductUnit;
use App\Modules\Inventory\Services\BranchAccess;
use App\Modules\Sales\Enums\InvoiceStatus;
use App\Modules\Sales\Exceptions\UnitNoLongerAvailable;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesReturn;
use App\Modules\Sales\Models\SalesReturnItem;
use App\Modules\Sales\Services\FinaliseInvoice;
use App\Modules\Sales\Services\ProfitEngine;
use App\Modules\Sales\Services\VoidInvoice;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * Everything about a sale that is not the act of making it.
 *
 * The list is what a shop opens to find yesterday's invoice — by number, by customer, by
 * the phone's IMEI — so all three are searchable from one box. The detail page is what
 * gets reprinted, returned against, and argued over.
 *
 * Profit lives behind its own permission and is withheld entirely rather than zeroed: a
 * Salesperson who can see a margin of `۰` on every line learns that the field exists and
 * that they are not trusted with it, which is worse than not showing it (Gate 1).
 */
final class InvoiceController extends Controller
{
    private const PER_PAGE = 25;

    public function __construct(private readonly BranchAccess $branches) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', SalesInvoice::class);

        $term = trim($request->string('q')->value());

        /** @var User $user The `tenant.user` middleware guarantees a tenant user here. */
        $user = $request->user();

        $invoices = SalesInvoice::query()
            ->with(['party:id,name', 'branch:id,name', 'salesperson:id,name'])
            ->where('type', SalesInvoice::TYPE_INVOICE)
            ->tap(fn ($query) => $this->branches->constrain($query, $user))
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')->value()))
            ->when($term !== '', fn ($query) => $query->where(function ($q) use ($term): void {
                $q->where('number', 'ilike', "%{$term}%")
                    ->orWhereHas('party', fn ($p) => $p->where('name', 'ilike', "%{$term}%"))
                    // The IMEI, because a shop far more often has the phone in front of
                    // them than the invoice number. This is the lookup that answers
                    // "who did we sell this to" (golden rule 4).
                    //
                    // A sub-select rather than `whereHas('items.unit', …)`: the scope
                    // lives on ProductUnit, and reaching it through two levels of
                    // relation hands the closure an untyped builder that neither the
                    // reader nor the analyser can see `matchingCode` on.
                    ->orWhereHas('items', fn ($items) => $items->whereIn(
                        'product_unit_id',
                        ProductUnit::query()->matchingCode($term)->select('id'),
                    ));
            }))
            ->orderByDesc('issued_at')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return Inertia::render('Sales::Invoices/Index', [
            'invoices' => [
                'rows' => array_map(fn (SalesInvoice $invoice): array => [
                    'id' => $invoice->id,
                    'number' => $invoice->number,
                    'status' => $invoice->status->value,
                    'status_label' => $invoice->status->labelFa(),
                    'issued_at' => $invoice->issued_at?->toIso8601String(),
                    'created_at' => $invoice->created_at?->toIso8601String(),
                    'party_name' => $invoice->party?->name,
                    'branch_name' => $invoice->branch->name,
                    'salesperson_name' => $invoice->salesperson?->name,
                    'total' => Money::toArray($invoice->total),
                    'outstanding' => Money::toArray($invoice->outstanding()),
                ], $invoices->items()),
                'links' => $invoices->linkCollection()->toArray(),
                'total' => $invoices->total(),
            ],
            'filters' => [
                'q' => $term,
                'status' => $request->string('status')->value() ?: null,
            ],
            'statuses' => array_map(fn (InvoiceStatus $status): array => [
                'value' => $status->value,
                'label' => $status->labelFa(),
            ], InvoiceStatus::cases()),
            'can' => [
                'create' => $request->user()?->can('sales.create') ?? false,
            ],
        ]);
    }

    public function show(Request $request, SalesInvoice $invoice, ProfitEngine $profit, LedgerService $ledger): Response
    {
        $this->authorize('view', $invoice);

        $invoice->load([
            'items.unit:id,imei1,serial,product_variant_id',
            'payments.account:id,name,type',
            'party',
            'branch:id,name',
            'salesperson:id,name',
            'returns.items',
            'tradeIn',
            'installmentPlan:id,number,sales_invoice_id',
        ]);

        $user = $request->user();
        $showProfit = $user?->can('sales.view_profit') ?? false;

        return Inertia::render('Sales::Invoices/Show', [
            'invoice' => $this->payload($invoice),
            // Withheld entirely for staff without the permission — not zeroed.
            'profit' => $showProfit ? $this->profitPayload($profit->forInvoice($invoice)) : null,
            // Gated behind `sales.view_profit`, NOT shown to the salesperson who earned
            // it — which looks wrong until you do the arithmetic. Commission is a known
            // percentage of margin, so telling somebody their commission tells them the
            // margin, and Gate 1 was explicit that a Salesperson is blind to cost and
            // profit. A shop that wants its sellers to see their own numbers grants them
            // the permission; that is the same per-tenant override Gate 1 allowed.
            'commission' => $showProfit && $invoice->commission_rate > 0 ? [
                'amount' => Money::toArray($invoice->commission_amount),
                'rate' => $invoice->commission_rate,
                'salesperson' => $invoice->salesperson?->name,
            ] : null,
            'party_balance' => $invoice->party !== null && ($user?->can('crm.view_balance') ?? false)
                ? Money::toArray($ledger->partyBalance($invoice->party))
                : null,
            'can' => [
                'void' => $user?->can('sales.void') ?? false,
                'return' => $user?->can('sales.return') ?? false,
                'create' => $user?->can('sales.create') ?? false,
            ],
        ]);
    }

    /**
     * Issue a draft that was parked earlier.
     *
     * The same door as the till's one-shot sale — {@see FinaliseInvoice} — because a
     * second way to issue an invoice is a second place for the double-sell guard to be
     * forgotten.
     */
    public function finalise(Request $request, SalesInvoice $invoice, FinaliseInvoice $finaliser): RedirectResponse
    {
        $this->authorize('update', $invoice);

        try {
            $finaliser->finalise($invoice, $request->user()?->id);
        } catch (UnitNoLongerAvailable|RuntimeException $exception) {
            // The draft survives here, unlike the till's path: somebody deliberately
            // parked this basket, and the operator can swap the sold handset for another
            // rather than rebuild the sale from nothing.
            throw ValidationException::withMessages(['invoice' => $exception->getMessage()]);
        }

        return redirect()
            ->route('sales.invoices.show', $invoice)
            ->with('success', "فاکتور {$invoice->number} ثبت شد.");
    }

    public function void(Request $request, SalesInvoice $invoice, VoidInvoice $voider): RedirectResponse
    {
        $this->authorize('void', $invoice);

        $validated = $request->validate([
            // Mandatory, and stored. An invoice cancelled for no recorded reason is one
            // nobody can defend when it is asked about a year later.
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ], [
            'reason.required' => 'دلیل ابطال را بنویسید.',
            'reason.min' => 'دلیل ابطال را کامل‌تر بنویسید.',
        ]);

        try {
            $voider->void($invoice, $validated['reason'], $request->user()?->id);
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['reason' => $exception->getMessage()]);
        }

        return redirect()
            ->route('sales.invoices.show', $invoice)
            ->with('success', "فاکتور {$invoice->number} ابطال شد.");
    }

    /**
     * Throw away a parked basket.
     *
     * Only ever a draft — it has no number, no stock effect and no ledger entry, so
     * there is nothing to preserve and nothing for a gap to appear in. A final invoice
     * is voided instead, and keeps its number forever.
     */
    public function destroy(Request $request, SalesInvoice $invoice): RedirectResponse
    {
        $this->authorize('update', $invoice);

        if (! $invoice->isDraft()) {
            throw ValidationException::withMessages([
                'invoice' => 'فاکتور نهایی‌شده حذف نمی‌شود؛ باید ابطال شود.',
            ]);
        }

        $invoice->delete();

        return redirect()
            ->route('sales.invoices.index')
            ->with('success', 'پیش‌نویس حذف شد.');
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(SalesInvoice $invoice): array
    {
        return [
            'id' => $invoice->id,
            'number' => $invoice->number,
            'type' => $invoice->type,
            'status' => $invoice->status->value,
            'status_label' => $invoice->status->labelFa(),
            'issued_at' => $invoice->issued_at?->toIso8601String(),
            'voided_at' => $invoice->voided_at?->toIso8601String(),
            'void_reason' => $invoice->void_reason,
            'notes' => $invoice->notes,
            'branch_name' => $invoice->branch->name,
            'salesperson_name' => $invoice->salesperson?->name,
            'party' => $invoice->party === null ? null : [
                'id' => $invoice->party->id,
                'name' => $invoice->party->name,
                'mobile' => $invoice->party->primaryMobile(),
            ],
            'items' => $invoice->items->map(fn ($item): array => [
                'id' => $item->id,
                'description' => $item->description,
                'imei' => $this->unitCode($item->unit),
                'quantity' => $item->quantity,
                'unit_price' => Money::toArray($item->unit_price),
                'discount_amount' => Money::toArray($item->discount_amount),
                'vat_amount' => Money::toArray($item->vat_amount),
                'line_total' => Money::toArray($item->line_total),
                'warranty_months' => $item->warranty_months,
                'returned_quantity' => $this->returnedQuantity($invoice, $item->id),
            ])->all(),
            'payments' => $invoice->payments->map(fn ($payment): array => [
                'id' => $payment->id,
                'method' => $payment->method->value,
                'method_label' => $payment->method->labelFa(),
                'account_name' => $payment->account?->name,
                'amount' => Money::toArray($payment->amount),
                'tendered_amount' => $payment->tendered_amount === null
                    ? null
                    : Money::toArray($payment->tendered_amount),
                'change' => Money::toArray($payment->change()),
                'reference' => $payment->reference,
                'received_at' => $payment->received_at->toIso8601String(),
            ])->all(),
            'totals' => [
                'subtotal' => Money::toArray($invoice->subtotal),
                'discount_amount' => Money::toArray($invoice->discount_amount),
                'vat_amount' => Money::toArray($invoice->vat_amount),
                'shipping_amount' => Money::toArray($invoice->shipping_amount),
                'rounding_adjustment' => Money::toArray($invoice->rounding_adjustment),
                'total' => Money::toArray($invoice->total),
                'paid_total' => Money::toArray($invoice->paid_total),
                'outstanding' => Money::toArray($invoice->outstanding()),
            ],
            'returns' => $invoice->returns->map(fn ($return): array => [
                'id' => $return->id,
                'number' => $return->number,
                'total' => Money::toArray($return->total),
                'reason' => $return->reason,
                'returned_at' => $return->returned_at->toIso8601String(),
            ])->all(),
            'installment_plan' => $invoice->installmentPlan === null ? null : [
                'id' => $invoice->installmentPlan->id,
                'number' => $invoice->installmentPlan->number,
            ],
            'trade_in' => $invoice->tradeIn === null ? null : [
                'device_name' => $invoice->tradeIn->device_name,
                'imei1' => $invoice->tradeIn->imei1,
                'agreed_price' => Money::toArray($invoice->tradeIn->agreed_price),
                'grade' => $invoice->tradeIn->grade,
            ],
        ];
    }

    /**
     * The code printed on the box — IMEI first, serial for a device that has none.
     */
    private function unitCode(?ProductUnit $unit): ?string
    {
        if (! $unit instanceof ProductUnit) {
            return null;
        }

        return $unit->imei1 ?? $unit->serial;
    }

    /**
     * How much of one line has already come back.
     *
     * Computed from the return rows rather than stored on the line: a second partial
     * return has to know what the first one took, and a stored counter is one more
     * number that can disagree with the documents beneath it (golden rule 3).
     */
    private function returnedQuantity(SalesInvoice $invoice, int $itemId): int
    {
        return $invoice->returns
            ->flatMap(fn (SalesReturn $return) => $return->items)
            ->where('sales_invoice_item_id', $itemId)
            ->sum(fn (SalesReturnItem $item): int => $item->quantity);
    }

    /**
     * @param  array{revenue: int, cost: int, profit: int, margin_percent: int, lines: list<array{id: int, description: string, revenue: int, cost: int, profit: int}>}  $profit
     * @return array<string, mixed>
     */
    private function profitPayload(array $profit): array
    {
        return [
            'revenue' => Money::toArray($profit['revenue']),
            'cost' => Money::toArray($profit['cost']),
            'profit' => Money::toArray($profit['profit']),
            'margin_percent' => $profit['margin_percent'],
            'lines' => array_map(fn (array $line): array => [
                'id' => $line['id'],
                'description' => $line['description'],
                'revenue' => Money::toArray($line['revenue']),
                'cost' => Money::toArray($line['cost']),
                'profit' => Money::toArray($line['profit']),
            ], $profit['lines']),
        ];
    }
}
