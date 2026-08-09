<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Purchasing\Models\PurchaseInvoice;
use App\Modules\Purchasing\Services\ReturnPurchase;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Sending part of a received shipment back.
 *
 * Posted from the invoice screen rather than given its own wizard: a return always
 * starts from "this shipment had a problem", and making the operator find the document
 * again from a blank form is how the wrong invoice gets credited.
 */
final class PurchaseReturnController extends Controller
{
    public function store(Request $request, PurchaseInvoice $invoice, ReturnPurchase $returns): RedirectResponse
    {
        $this->authorize('return', $invoice);

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.unit_id' => ['nullable', 'integer', 'exists:product_units,id'],
            'lines.*.variant_id' => ['nullable', 'integer', 'exists:product_variants,id'],
            'lines.*.quantity' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'lines.*.unit_cost' => ['nullable', 'integer', 'min:0'],
        ], [
            'lines.required' => 'دست‌کم یک ردیف برای برگشت انتخاب کنید.',
        ]);

        try {
            /** @var list<array{variant_id?: int, unit_id?: int, quantity?: int, unit_cost?: int}> $lines */
            $lines = $validated['lines'];

            $return = $returns->return($invoice, $lines, $validated['reason'] ?? null, $request->user()?->id);
        } catch (RuntimeException $exception) {
            return back()->withErrors(['return' => $exception->getMessage()]);
        }

        return back()->with('success', "برگشت از خرید {$return->number} ثبت شد.");
    }
}
