<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Sales\Http\Requests\SalesReturnRequest;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesInvoiceItem;
use App\Modules\Sales\Models\SalesReturn;
use App\Modules\Sales\Models\SalesReturnItem;
use App\Modules\Sales\Services\RecordReturn;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * برگشت از فروش.
 *
 * The screen shows every line of the original invoice with what is still returnable on
 * it — not what was sold. A shop taking back the second of two chargers needs to see
 * that one already came back, and a form that offers the full quantity again is a form
 * that refunds the same charger twice.
 */
final class SalesReturnController extends Controller
{
    public function create(Request $request, SalesInvoice $invoice): Response
    {
        $this->authorize('return', $invoice);

        if (! $invoice->isFinal()) {
            abort(404);
        }

        $invoice->load(['items.unit:id,imei1,serial', 'returns.items', 'party:id,name']);

        return Inertia::render('Sales::Returns/Create', [
            'invoice' => [
                'id' => $invoice->id,
                'number' => $invoice->number,
                'issued_at' => $invoice->issued_at?->toIso8601String(),
                'party_name' => $invoice->party?->name,
                'total' => Money::toArray($invoice->total),
            ],
            'items' => $invoice->items->map(function (SalesInvoiceItem $item) use ($invoice): array {
                $returned = $invoice->returns
                    ->flatMap(fn (SalesReturn $return) => $return->items)
                    ->where('sales_invoice_item_id', $item->id)
                    ->sum(fn (SalesReturnItem $row): int => $row->quantity);

                $unit = $item->unit;

                return [
                    'id' => $item->id,
                    'description' => $item->description,
                    'imei' => $unit === null ? null : ($unit->imei1 ?? $unit->serial),
                    'is_serialized' => $item->product_unit_id !== null,
                    'quantity' => $item->quantity,
                    'returned_quantity' => $returned,
                    // What the form may actually offer. Zero means this line is done.
                    'returnable_quantity' => max(0, $item->quantity - $returned),
                    'line_total' => Money::toArray($item->line_total),
                    /*
                     * Per-unit, so the form can propose a refund for a partial quantity
                     * without the page re-deriving the division.
                     *
                     * ## Why this ceils, and why it used to crash
                     *
                     * It was a bare `intdiv($item->line_total, $item->quantity)`. A line of
                     * two at 10,652,010 rial — a whole number of toman, an ordinary
                     * discounted line — divides to 5,326,005, which is nine-tenths of a
                     * toman. `Money::toArray()` refuses to render that rather than silently
                     * round it, so **this screen answered 500 for any invoice holding such a
                     * line**. The existing tests missed it because their fixtures divide
                     * cleanly.
                     *
                     * `ceilToToman`, not floor. ADR 0009's amendment states the direction
                     * once for the whole family: a rounding of a derived figure goes the way
                     * that does not flatter the party doing the rounding. VAT floors because
                     * the shop is charging it; a refund is the shop *paying*, so rounding it
                     * down would flatter the shop by up to nine rial a unit. It rounds up.
                     *
                     * The over-payment that ceiling implies never reaches a whole-line
                     * return: the form uses `line_total` when a line comes back complete,
                     * so the exact figure is refunded to the rial and this per-unit number
                     * is only ever used for a genuine partial return.
                     */
                    'unit_refund' => Money::toArray(
                        Money::ceilToToman(intdiv($item->line_total, max(1, $item->quantity)))
                    ),
                ];
            })->all(),
            'grades' => $this->gradeOptions(),
        ]);
    }

    public function store(SalesReturnRequest $request, SalesInvoice $invoice, RecordReturn $returns): RedirectResponse
    {
        $this->authorize('return', $invoice);

        try {
            $return = $returns->record(
                $invoice,
                $request->lines(),
                $request->string('reason')->value() ?: null,
                $request->user()?->id,
            );
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['lines' => $exception->getMessage()]);
        }

        return redirect()
            ->route('sales.invoices.show', $invoice)
            ->with('success', "برگشت از فروش {$return->number} ثبت شد.");
    }

    /**
     * The grades a returned device can come back as.
     *
     * Deliberately the same vocabulary Inventory already uses for used stock, so a
     * handset that came back and one bought second-hand are described by the same
     * words on the same shelf.
     *
     * @return list<array{value: string, label: string}>
     */
    private function gradeOptions(): array
    {
        return [
            ['value' => 'A', 'label' => 'درجه A — بدون خط و خش'],
            ['value' => 'B', 'label' => 'درجه B — خط و خش جزئی'],
            ['value' => 'C', 'label' => 'درجه C — خط و خش محسوس'],
            ['value' => 'D', 'label' => 'درجه D — نیاز به تعمیر'],
        ];
    }
}
