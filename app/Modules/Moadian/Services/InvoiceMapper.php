<?php

declare(strict_types=1);

namespace App\Modules\Moadian\Services;

use App\Modules\Moadian\Models\MoadianInvoice;
use App\Modules\Moadian\Models\MoadianSetting;
use App\Modules\Moadian\Support\MoadianLine;
use App\Modules\Moadian\Support\MoadianPayload;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesInvoiceItem;

/**
 * A sales invoice, turned into the authority's payload. Nothing else.
 *
 * ## Pure, and that is the whole design
 *
 * No HTTP, no clock, no queue, no driver. Hand it an invoice and a settings row and it
 * returns a value — which is why it can be unit-tested against fixture invoices, and why
 * the spec asks for it separately from any network call. A mapping bug is a wrong tax
 * filing discovered at audit; it is the one part of this module that must be right before
 * a real provider ever exists.
 *
 * ## It reproduces the invoice. It never recomputes it.
 *
 * [ADR 0009](../../../../docs/adr/0009-invoice-rounding.md), amendment: per-line VAT was
 * **floored to a whole toman at issue** and the stored figure is the figure. Recomputing at
 * the current rate would round once per invoice instead of once per line, disagree with the
 * paper the customer holds, and — because the difference accrues in the shop's favour —
 * disagree in the direction a tax authority notices.
 *
 * `rounding_adjustment` travels for the same reason: rule 3 says the paper adds up, and a
 * payload whose lines do not reconcile to its total is a rejection waiting to happen.
 *
 * ## The buyer may be a walk-in, and that is legal
 *
 * A great many counter sales have no party at all. The buyer block is then the authority's
 * anonymous-consumer shape rather than an error — refusing to map a walk-in would make the
 * module unusable in the shops it is for.
 */
final class InvoiceMapper
{
    public function map(
        SalesInvoice $invoice,
        ?MoadianSetting $settings = null,
        string $type = MoadianInvoice::TYPE_MAIN,
        ?string $referencesSubmission = null,
    ): MoadianPayload {
        $lines = [];

        foreach ($invoice->items as $item) {
            $lines[] = $this->line($item);
        }

        return new MoadianPayload(
            // The shop's own invoice number. A rejection three days later has to be
            // traceable to a document somebody can pick up.
            reference: $invoice->number ?? ('DRAFT-'.idOfModel($invoice)),
            type: $type,
            issuedAt: (int) ($invoice->issued_at?->getTimestamp() ?? 0),
            seller: $this->seller($settings),
            buyer: $this->buyer($invoice),
            lines: $lines,
            subtotal: $invoice->subtotal,
            discountTotal: $invoice->discount_amount,
            vatTotal: $invoice->vat_amount,
            total: $invoice->total,
            roundingAdjustment: $invoice->rounding_adjustment,
            referencesSubmission: $referencesSubmission,
        );
    }

    private function line(SalesInvoiceItem $item): MoadianLine
    {
        $unit = $item->unit;
        $identifier = $unit === null ? null : ($unit->imei1 ?? $unit->serial);

        return new MoadianLine(
            description: $item->description,
            quantity: $item->quantity,
            unitPrice: $item->unit_price,
            discount: $item->discount_amount,
            vatRate: $item->vat_rate,
            // Stored, never derived. See the class docblock.
            vatAmount: $item->vat_amount,
            total: $item->line_total,
            // A handset's IMEI is the identifier the authority wants against a serialized
            // line; a charger has none and sends null rather than an empty string.
            identifier: $identifier,
            taxCode: null,
        );
    }

    /**
     * @return array<string, scalar|null>
     */
    private function seller(?MoadianSetting $settings): array
    {
        return [
            'memory_id' => $settings?->memory_id,
            'economic_code' => $settings?->economic_code,
        ];
    }

    /**
     * @return array<string, scalar|null>
     */
    private function buyer(SalesInvoice $invoice): array
    {
        $party = $invoice->party;

        if ($party === null) {
            // مصرف‌کننده نهایی — a walk-in. Legal, common, and not an error.
            return ['type' => 'consumer', 'name' => null, 'national_id' => null, 'economic_code' => null];
        }

        return [
            // A party carrying an economic code is trading as a business; one with only a
            // national id is a person. The distinction changes which fields the authority
            // requires, so it is stated here rather than left for a driver to guess.
            'type' => $party->economic_code === null || $party->economic_code === '' ? 'person' : 'business',
            'name' => $party->name,
            'national_id' => $party->national_id,
            'economic_code' => $party->economic_code,
        ];
    }
}
