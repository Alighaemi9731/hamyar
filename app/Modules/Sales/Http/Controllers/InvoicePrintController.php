<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Sales\Models\InvoicePayment;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesInvoiceItem;
use App\Modules\Sales\Services\PublicInvoiceLink;
use App\Support\Money;
use App\Support\QrRenderer;
use App\Support\Settings\PrintSettings;
use App\Support\Settings\ShopSettings;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The paper an invoice becomes.
 *
 * Three sizes, one payload. The differences between a thermal receipt and an A4
 * official invoice are presentational — what is shown, how densely — not a difference
 * in what the document *is*, so shipping three payloads would give three chances for
 * them to disagree about the total.
 *
 * Reads the invoice's own `settings_snapshot` where it has one, so a reprint of last
 * month's invoice prints the way it printed then, not the way the shop's settings have
 * since changed.
 */
final class InvoicePrintController extends Controller
{
    public function __construct(
        private readonly ShopSettings $settings,
        private readonly PublicInvoiceLink $links,
        private readonly QrRenderer $qr,
    ) {}

    public function show(SalesInvoice $invoice, string $paper): Response
    {
        $this->authorize('view', $invoice);

        // A path segment, so it is validated rather than trusted into a component name.
        validator(['paper' => $paper], ['paper' => [Rule::in(['thermal80', 'a5', 'a4'])]])->validate();

        $invoice->load([
            'items.unit:id,imei1,serial',
            'payments',
            'party:id,name,company_name',
            'branch:id,name,address,phone',
            'salesperson:id,name',
        ]);

        $template = $this->template($invoice);
        $publicUrl = $template->showQr ? $this->links->for($invoice) : null;

        return Inertia::render('Sales::Invoices/Print', [
            'paper' => $paper,
            'template' => [
                'logo_url' => $template->logoUrl,
                'footer_terms' => $template->footerTerms,
                'public_url' => $publicUrl,
                // Rendered server-side so what a camera reads is exactly what we meant
                // to encode — the same reasoning as the Code 128 labels in Catalog.
                'qr_svg' => $publicUrl === null ? null : $this->qr->svg($publicUrl),
            ],
            'invoice' => [
                'id' => $invoice->id,
                'number' => $invoice->number,
                'status' => $invoice->status->value,
                'issued_at' => $invoice->issued_at?->toIso8601String(),
                'notes' => $invoice->notes,

                'subtotal' => Money::toArray($invoice->subtotal),
                'discount_amount' => Money::toArray($invoice->discount_amount),
                'vat_amount' => Money::toArray($invoice->vat_amount),
                'shipping_amount' => Money::toArray($invoice->shipping_amount),
                // Signed: it is shown as its own line so the arithmetic on the paper
                // adds up in front of the customer.
                'rounding_adjustment' => $invoice->rounding_adjustment,
                'total' => Money::toArray($invoice->total),
                'paid_total' => Money::toArray($invoice->paid_total),
                'outstanding' => Money::toArray($invoice->outstanding()),
            ],
            'party' => $invoice->party === null ? null : [
                'name' => $invoice->party->name,
                'company_name' => $invoice->party->company_name,
            ],
            'branch' => [
                'name' => $invoice->branch->name,
                'address' => $invoice->branch->address,
                'phone' => $invoice->branch->phone,
            ],
            'salesperson' => $invoice->salesperson?->name,
            'items' => $invoice->items->map(fn (SalesInvoiceItem $item): array => [
                'id' => $item->id,
                'description' => $item->description,
                // The IMEI belongs on the paper: it is the customer's warranty claim
                // and the shop's proof of which device left the counter.
                'imei' => $item->unit === null ? null : ($item->unit->imei1 ?? $item->unit->serial),
                'quantity' => $item->quantity,
                'unit_price' => Money::toArray($item->unit_price),
                'discount_amount' => Money::toArray($item->discount_amount),
                'vat_amount' => Money::toArray($item->vat_amount),
                'line_total' => Money::toArray($item->line_total),
                'warranty_months' => $item->warranty_months,
            ])->values()->all(),
            'payments' => $invoice->payments->map(fn (InvoicePayment $payment): array => [
                'id' => $payment->id,
                'method' => $payment->method->value,
                'method_label' => $payment->method->labelFa(),
                'amount' => Money::toArray($payment->amount),
                'reference' => $payment->reference,
            ])->values()->all(),
        ]);
    }

    /**
     * The template this invoice prints with.
     *
     * The invoice's own snapshot wins whenever it has one: a reprint of a year-old
     * invoice has to carry the terms that were in force on the day, because those are
     * the ones that govern the argument being had about it. Only an invoice issued
     * before this feature existed falls through to the shop's current settings.
     */
    private function template(SalesInvoice $invoice): PrintSettings
    {
        $snapshot = $invoice->settings_snapshot ?? [];

        if (! array_key_exists('print_footer_terms', $snapshot)) {
            return $this->settings->print();
        }

        $logo = $snapshot['print_logo_url'] ?? null;
        $terms = $snapshot['print_footer_terms'] ?? null;

        return new PrintSettings(
            logoUrl: is_string($logo) && $logo !== '' ? $logo : null,
            footerTerms: is_string($terms) && $terms !== '' ? $terms : null,
            showQr: ($snapshot['print_show_qr'] ?? true) !== false,
        );
    }
}
