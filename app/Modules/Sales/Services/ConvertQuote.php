<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services;

use App\Modules\Sales\Enums\InvoiceStatus;
use App\Modules\Sales\Models\SalesInvoice;
use Illuminate\Database\ConnectionInterface;
use RuntimeException;

/**
 * پیش‌فاکتور → فاکتور.
 *
 * ## The quote survives its own conversion
 *
 * See the migration for the reasoning: the customer is holding a piece of paper with
 * `QUO-000014` on it. Re-numbering that row would destroy the document they can see.
 * So this copies the lines into a fresh draft invoice and links the two.
 *
 * ## Prices are copied, stock is not checked
 *
 * A quote is a promise about price, not a reservation of goods — nothing was held, and
 * the handset on it may well have been sold to somebody else in the intervening week.
 * That is deliberately **not** caught here: the conversion produces a draft, the draft
 * opens in the till, and finalisation is where stock is checked, under a lock, with a
 * Persian error naming the device. Refusing at conversion would be a second, weaker copy
 * of that guard, and it would refuse baskets that only need one line swapped.
 *
 * What a quote does not carry over is **payments**. Nobody paid anything against a
 * quote; copying an empty payment list is a no-op, and copying a non-empty one would be
 * inventing money.
 */
final class ConvertQuote
{
    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly InvoiceTotals $totals,
    ) {}

    public function convert(SalesInvoice $quote, ?int $actorId = null): SalesInvoice
    {
        $this->guardConvertible($quote);

        /** @var SalesInvoice $invoice */
        $invoice = $this->connection->transaction(function () use ($quote, $actorId): SalesInvoice {
            $quote->load('items');

            $invoice = SalesInvoice::query()->create([
                'branch_id' => $quote->branch_id,
                'party_id' => $quote->party_id,
                'salesperson_id' => $actorId ?? $quote->salesperson_id,
                'type' => SalesInvoice::TYPE_INVOICE,
                'status' => InvoiceStatus::Draft,
                'discount_amount' => $quote->discount_amount,
                'shipping_amount' => $quote->shipping_amount,
                // The quote's own snapshot, not today's settings: the customer was
                // quoted a total that rounded and taxed a particular way, and the
                // invoice that honours the quote has to agree with the paper.
                'settings_snapshot' => $quote->settings_snapshot,
                'notes' => $quote->notes,
            ]);

            foreach ($quote->items as $item) {
                $invoice->items()->create([
                    'product_variant_id' => $item->product_variant_id,
                    'product_unit_id' => $item->product_unit_id,
                    'description' => $item->description,
                    'quantity' => $item->quantity,
                    'unit_price' => $item->unit_price,
                    'discount_amount' => $item->discount_amount,
                    'vat_rate' => $item->vat_rate,
                    'vat_amount' => $item->vat_amount,
                    'line_total' => $item->line_total,
                    // Not copied: the quote never had one, and cost is written at
                    // finalisation from the ledger — never carried forward from a
                    // document that did not move any stock.
                    'cost_snapshot' => 0,
                    'warranty_months' => $item->warranty_months,
                ]);
            }

            // Recomputed rather than copied line for line. The stored totals are what
            // the invoice will be finalised against, and deriving them from the copied
            // rows is the only way the two can be guaranteed to agree — a copied
            // `total` beside recopied lines is two numbers that can drift apart.
            //
            // It lands on the same figure because the quote's own settings snapshot came
            // across with it: the same rounding step and VAT rate that produced the
            // number on the paper the customer is holding.
            $this->totals->recalculate($invoice);

            $quote->forceFill(['converted_to_id' => $invoice->id])->save();

            return $invoice->refresh();
        });

        return $invoice;
    }

    private function guardConvertible(SalesInvoice $quote): void
    {
        if (! $quote->isQuote()) {
            throw new RuntimeException('این سند پیش‌فاکتور نیست.');
        }

        if ($quote->converted_to_id !== null) {
            throw new RuntimeException('این پیش‌فاکتور قبلاً به فاکتور تبدیل شده است.');
        }

        if ($quote->items()->count() === 0) {
            throw new RuntimeException('پیش‌فاکتور بدون ردیف تبدیل نمی‌شود.');
        }
    }
}
