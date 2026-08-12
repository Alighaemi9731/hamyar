<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Sales\Enums\InvoiceStatus;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesInvoiceItem;
use App\Support\Money;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The invoice a customer opens by scanning the QR on their receipt.
 *
 * ## No login, and therefore a deliberately narrow document
 *
 * The link is signed — {@see SalesInvoice::publicUrl()} — so it cannot be guessed or
 * walked by incrementing an id. But a signature only proves the link came from us, and
 * a receipt is a piece of paper that gets left on counters and photographed. So this
 * page shows what is already printed on the customer's own receipt and nothing more:
 *
 * - **No cost, no margin.** Obvious, and the reason this is a separate controller rather
 *   than the authenticated one with a flag: a `$user->can(...)` that defaults open is one
 *   refactor away from publishing every shop's buying prices.
 * - **No customer identity beyond the name already on the paper.** No phone number, no
 *   national id, no balance, no other invoices.
 * - **No IMEI.** It is on the paper the customer is holding, but a serial number is what
 *   a stolen-handset registry check keys on, and a signed link that leaks is a signed
 *   link somebody else can read.
 *
 * ## A voided invoice says so, loudly
 *
 * It is not hidden. A customer holding a receipt for a cancelled sale needs to be told
 * that is what they are holding — a 404 would read as "the shop lost your record".
 *
 * ## The id is looked up here rather than route-model-bound
 *
 * `SubstituteBindings` lives in the `web` group, which runs **before** a route's own
 * middleware — so a bound `{invoice}` is resolved, and 404s when missing, before
 * `signed` has looked at anything. The signature would still protect real invoices, but
 * the difference between 404 and 403 would answer "has this shop issued invoice number
 * 4,000 yet?" to anybody who asked, with no signature at all. Counting a competitor's
 * sales should not be a GET request.
 *
 * Resolving it by hand puts the lookup after the signature check, where it belongs.
 */
final class PublicInvoiceController extends Controller
{
    public function show(Request $request, int $invoice): Response
    {
        // Scoped by RLS to the shop the hostname resolved to, so a signature minted for
        // one shop cannot address another's row even if the ids line up.
        $sale = SalesInvoice::query()
            ->with(['items', 'party:id,name,company_name', 'branch:id,name,address,phone'])
            ->find($invoice);

        abort_if(! $sale instanceof SalesInvoice, 404);

        // A draft has no number and never left the counter; there is nothing for a
        // customer to have scanned.
        abort_if($sale->status === InvoiceStatus::Draft, 404);
        abort_if($sale->type !== SalesInvoice::TYPE_INVOICE, 404);

        $invoice = $sale;

        return Inertia::render('Sales::Invoices/Public', [
            'invoice' => [
                'number' => $invoice->number,
                'status' => $invoice->status->value,
                'issued_at' => $invoice->issued_at?->toIso8601String(),
                'is_void' => $invoice->status === InvoiceStatus::Void,
                'subtotal' => Money::toArray($invoice->subtotal),
                'discount_amount' => Money::toArray($invoice->discount_amount),
                'vat_amount' => Money::toArray($invoice->vat_amount),
                'shipping_amount' => Money::toArray($invoice->shipping_amount),
                'rounding_adjustment' => Money::toArray($invoice->rounding_adjustment),
                'total' => Money::toArray($invoice->total),
                'paid_total' => Money::toArray($invoice->paid_total),
                'outstanding' => Money::toArray($invoice->outstanding()),
            ],
            'shop' => [
                'name' => $invoice->branch->name,
                'address' => $invoice->branch->address,
                'phone' => $invoice->branch->phone,
            ],
            // A company name where there is one, otherwise the person's. Resolved from a
            // local so the null-check reads once rather than twice.
            'customer' => $this->customerName($invoice),
            'items' => $invoice->items->map(fn (SalesInvoiceItem $item): array => [
                'id' => $item->id,
                'description' => $item->description,
                'quantity' => $item->quantity,
                'unit_price' => Money::toArray($item->unit_price),
                'line_total' => Money::toArray($item->line_total),
                'warranty_months' => $item->warranty_months,
            ])->values()->all(),
        ]);
    }

    private function customerName(SalesInvoice $invoice): ?string
    {
        $party = $invoice->party;

        if ($party === null) {
            return null;
        }

        return $party->company_name ?? $party->name;
    }
}
