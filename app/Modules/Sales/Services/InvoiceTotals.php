<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services;

use App\Modules\Sales\Enums\RoundingDirection;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesInvoiceItem;
use App\Support\Money;

/**
 * The arithmetic of an invoice, in one place.
 *
 * Every figure here is integer rial and every division is `intdiv` (golden rule 2).
 * There is no float anywhere in this class, which matters more than it sounds: a
 * rounding difference of one rial between the screen, the printed invoice and the
 * ledger is the kind of thing a shop notices at the end of the month and cannot
 * explain.
 *
 * ## Three decisions worth knowing
 *
 * **An invoice-level discount is distributed across the lines.** It would be simpler to
 * subtract it from the total and leave the lines alone — and it would make per-line
 * profit a lie, because the margin on each item would ignore the discount the customer
 * actually got. Distribution is by line value, with the remainder going to the largest
 * line so the parts sum to the whole exactly.
 *
 * **VAT is computed per line, after that distribution.** Tax follows the discounted
 * price, not the list price; computing it on the total instead would produce a
 * different figure whenever the lines carry different rates.
 *
 * **Rounding the grand total is a shop setting, not arithmetic.** Iranian counters
 * routinely round the last few hundred rial away rather than hand over coins nobody
 * uses. That is a commercial decision — see {@see TotalRounder} — and it is applied
 * once, at the very end, never to a line.
 *
 * ## Every figure on an invoice is a whole number of toman
 *
 * Storage is rial (golden rule 2) but the shop and the customer transact in toman, and
 * `Money` refuses to render a rial amount that is not a whole number of them rather
 * than silently round one. That refusal found a real bug here: a 10% VAT on
 * 888,199,999 rial is 88,819,999 — nine-tenths of a toman — and the invoice could not
 * be displayed at all.
 *
 * So both derived figures, the distributed discount share and the per-line VAT, are
 * floored to a whole toman, with the residue carried to the largest line so the parts
 * still sum to the whole exactly. VAT floors rather than rounds: never charging a
 * customer more tax than the exact calculation is the safer side to be wrong on.
 */
final class InvoiceTotals
{
    public function __construct(private readonly TotalRounder $rounder) {}

    /**
     * Recompute every stored figure from the lines, and save.
     *
     * Called after any change to a draft. Deliberately writes the results rather than
     * deriving them on read: an invoice must keep saying what it said (see
     * {@see SalesInvoice}), and the lines that produced it are stored beside it so the
     * arithmetic stays checkable.
     */
    public function recalculate(SalesInvoice $invoice): SalesInvoice
    {
        $invoice->load('items');

        /** @var list<SalesInvoiceItem> $items */
        $items = $invoice->items->all();

        // 1 — Each line's own value, before any invoice-level discount.
        $gross = 0;

        foreach ($items as $item) {
            $lineGross = max(0, ($item->unit_price * $item->quantity) - $item->discount_amount);
            $gross += $lineGross;
        }

        // 2 — Spread the invoice discount across the lines by value.
        $shares = $this->distribute($invoice->discount_amount, $items);

        // 3 — VAT on what each line actually costs the customer.
        $vatTotal = 0;
        $netTotal = 0;

        foreach ($items as $item) {
            $lineGross = max(0, ($item->unit_price * $item->quantity) - $item->discount_amount);
            $lineNet = max(0, $lineGross - ($shares[$item->id] ?? 0));

            // Floored to a whole toman: the exact figure is routinely a fraction of one,
            // which the shop cannot charge, the customer cannot pay and `Money` will not
            // render.
            $vat = $this->wholeToman(intdiv($lineNet * $item->vat_rate, 100));

            $item->forceFill([
                'vat_amount' => $vat,
                // `line_total` includes VAT: it is what this line adds to the amount
                // the customer hands over, which is the only figure they can check.
                'line_total' => $lineNet + $vat,
            ])->save();

            $netTotal += $lineNet;
            $vatTotal += $vat;
        }

        $total = $netTotal + $vatTotal + $invoice->shipping_amount;

        // 4 — Round once, at the very end, and record what it moved.
        $rounded = $this->rounder->apply(
            max(0, $total),
            $this->roundingStep($invoice),
            $this->roundingDirection($invoice),
        );

        $invoice->forceFill([
            'subtotal' => $gross,
            'vat_amount' => $vatTotal,
            'rounding_adjustment' => $rounded['adjustment'],
            'total' => $rounded['total'],
        ])->save();

        return $invoice;
    }

    /**
     * The rounding step this invoice was issued under.
     *
     * Read from the invoice's own settings snapshot when it has one, so a reissued
     * print of an old invoice rounds the way it did on the day — not the way the shop
     * has since decided to.
     */
    private function roundingStep(SalesInvoice $invoice): int
    {
        $snapshot = $invoice->settings_snapshot ?? [];
        $step = $snapshot['rounding_step'] ?? null;

        return is_int($step) ? $step : TotalRounder::DEFAULT_STEP;
    }

    private function roundingDirection(SalesInvoice $invoice): RoundingDirection
    {
        $snapshot = $invoice->settings_snapshot ?? [];
        $direction = $snapshot['rounding_direction'] ?? null;

        return is_string($direction)
            ? (RoundingDirection::tryFrom($direction) ?? TotalRounder::DEFAULT_DIRECTION)
            : TotalRounder::DEFAULT_DIRECTION;
    }

    /**
     * Split an invoice-level discount across lines, by value.
     *
     * The remainder goes to the largest line — the same rule
     * `LandedCostAllocator` uses, and for the same reason: the parts must sum to the
     * whole **exactly**, and putting the odd rial on the biggest line is the least
     * visible place for it. Spreading it evenly would leave a residue; dropping it
     * would mean the discount the customer was promised is not the discount the lines
     * record.
     *
     * @param  list<SalesInvoiceItem>  $items
     * @return array<int, int> line id => discount share, in rial
     */
    private function distribute(int $discount, array $items): array
    {
        $shares = [];

        foreach ($items as $item) {
            $shares[$item->id] = 0;
        }

        if ($discount <= 0 || $items === []) {
            return $shares;
        }

        $weights = [];
        $totalWeight = 0;

        foreach ($items as $item) {
            $weight = max(0, ($item->unit_price * $item->quantity) - $item->discount_amount);
            $weights[$item->id] = $weight;
            $totalWeight += $weight;
        }

        // Nothing to weight against — a basket of zero-priced lines. Refusing to
        // divide by zero beats inventing a distribution.
        if ($totalWeight === 0) {
            return $shares;
        }

        $allocated = 0;
        $largestId = (int) array_key_first($weights);

        foreach ($weights as $id => $weight) {
            // Whole toman here too, so the discounted line price stays payable.
            $share = $this->wholeToman(intdiv($discount * $weight, $totalWeight));
            $shares[$id] = $share;
            $allocated += $share;

            if ($weight > $weights[$largestId]) {
                $largestId = $id;
            }
        }

        // Whatever the flooring left behind, on the biggest line — so the discount the
        // customer was promised is exactly the discount the lines record.
        $shares[$largestId] += $discount - $allocated;

        return $shares;
    }

    /**
     * Floor an amount to a whole number of toman.
     *
     * The unit the shop and the customer actually transact in. Storage stays rial;
     * this only stops a derived figure landing on a fraction of a toman that nobody
     * can pay and `Money` will not print.
     */
    private function wholeToman(int $rial): int
    {
        return intdiv($rial, Money::RIAL_PER_TOMAN) * Money::RIAL_PER_TOMAN;
    }
}
