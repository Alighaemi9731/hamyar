<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services;

use App\Modules\Inventory\Enums\UnitCondition;
use App\Modules\Inventory\Enums\UnitStatus;
use App\Modules\Inventory\Events\UnitAcquired;
use App\Modules\Inventory\Models\ProductUnit;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\UnitStateMachine;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\TradeIn;
use App\Support\Imei;
use RuntimeException;

/**
 * معاوضه — the shop buying a phone across the same counter it is selling one.
 *
 * ## Two transactions on one piece of paper
 *
 * A trade-in is not a discount. The customer buys a handset at its price and sells the
 * shop their old one at an agreed price; the second settles part of the first. Treating
 * it as a discount would compute VAT on a reduced base, understate the sale, and — worst
 * — leave the shop holding a phone that no register knows about.
 *
 * So it produces a real `product_unit`, with a cost, an owner it was acquired from, and
 * the first line of its passport. From that moment it is an ordinary used handset: it can
 * be graded, repaired, transferred and sold, and its IMEI answers "bought from whom".
 *
 * ## Why the caller must name a catalogue variant
 *
 * The device is typed in free text at the counter — «آیفون ۱۳ سفید» — and it is tempting
 * to create a catalogue product from that string. That is how a catalogue fills with
 * fourteen spellings of the same phone within a month, none of which can be reported on.
 * So the intake asks which existing serialized line this is, and keeps the typed
 * description beside it for what the variant does not capture.
 *
 * ## It arrives at `in_stock`, unlike a returned handset
 *
 * The difference is who looked at it. A return comes back from a customer who may have
 * dropped it, so it waits in `returned` for someone to grade it. A trade-in was
 * inspected and haggled over *at the counter* — the agreed price IS the grading — so it
 * goes straight onto the shelf.
 */
final class TradeInIntake
{
    public function __construct(private readonly UnitStateMachine $units) {}

    /**
     * Take the customer's handset into stock.
     *
     * Called from inside {@see FinaliseInvoice}'s transaction: the shop acquires the
     * phone at the moment it sells the other one, and a trade-in that survived a rolled
     * back sale would be a handset on the shelf that nobody paid for.
     */
    public function receive(TradeIn $tradeIn, SalesInvoice $invoice, ?int $actorId = null): ProductUnit
    {
        $this->guard($tradeIn);

        $warehouse = $this->warehouseFor($invoice);

        $unit = ProductUnit::query()->create([
            'product_variant_id' => $tradeIn->product_variant_id,
            'warehouse_id' => $warehouse->id,
            // Normalised on the way in, so a number typed on a Persian keypad at the
            // counter and one scanned off the box land in the column identically.
            'imei1' => $tradeIn->imei1 === null ? null : Imei::normalise($tradeIn->imei1),
            'condition' => UnitCondition::Used,
            'grade' => $tradeIn->grade,
            // What the shop agreed to pay. This is the cost every later profit figure on
            // this device rests on — sell it for more and the margin is real.
            'cost' => $tradeIn->agreed_price,
            'acquired_from_party_id' => $tradeIn->party_id ?? $invoice->party_id,
            'status' => UnitStatus::InStock,
        ]);

        // The first line of the passport. Without it the device's history starts
        // mid-story and "bought from whom" has no answer (golden rule 4).
        //
        // Metered, on the default: a trade-in is a device entering the shop, which is what
        // `inventory.units` counts. Exempting it because it arrived over the counter
        // rather than on a purchase invoice would be a loophole in the shape of a door.
        $this->units->recordAcquisition(
            $unit,
            reference: $invoice,
            note: "معاوضه در فاکتور {$invoice->number}",
            actorId: $actorId,
        );

        $tradeIn->forceFill(['product_unit_id' => $unit->id])->save();

        // After the passport row, so a listener reading the device's history sees one.
        // HAMTA flags it: a traded-in phone still has the customer's name in the registry.
        UnitAcquired::dispatch($unit);

        return $unit;
    }

    private function guard(TradeIn $tradeIn): void
    {
        if ($tradeIn->agreed_price <= 0) {
            throw new RuntimeException('مبلغ توافق‌شده معاوضه باید بیشتر از صفر باشد.');
        }

        if ($tradeIn->product_variant_id === null) {
            throw new RuntimeException('مدل دستگاه معاوضه‌ای را از فهرست کالاها انتخاب کنید.');
        }

        if ($tradeIn->imei1 !== null && ! Imei::isValid($tradeIn->imei1)) {
            // A mistyped IMEI accepted here is invisible until the phone is sold or
            // warranty-claimed, by which point the paperwork trail is broken.
            throw new RuntimeException('شماره IMEI دستگاه معاوضه‌ای معتبر نیست.');
        }

        if (! $tradeIn->hamta_ack) {
            // The shop carries the liability when a stolen handset is traded in, so this
            // is a box somebody had to tick on purpose, not a default.
            throw new RuntimeException('تأیید انتقال مالکیت همتا برای ثبت معاوضه الزامی است.');
        }
    }

    private function warehouseFor(SalesInvoice $invoice): Warehouse
    {
        $warehouse = Warehouse::query()
            ->where('branch_id', $invoice->branch_id)
            ->where('is_active', true)
            ->where('is_sellable', true)
            ->orderByDesc('is_default')
            ->first();

        if (! $warehouse instanceof Warehouse) {
            throw new RuntimeException('این شعبه انبار قابل فروشی ندارد؛ دستگاه معاوضه‌ای جایی برای رفتن ندارد.');
        }

        return $warehouse;
    }
}
