<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services;

use App\Modules\CRM\Models\Account;
use App\Modules\CRM\Services\LedgerService;
use App\Modules\Inventory\Enums\MovementType;
use App\Modules\Inventory\Enums\UnitStatus;
use App\Modules\Inventory\Models\ProductUnit;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\StockLedger;
use App\Modules\Inventory\Services\UnitStateMachine;
use App\Modules\Sales\Enums\InvoiceStatus;
use App\Modules\Sales\Enums\PaymentMethod;
use App\Modules\Sales\Events\InvoiceFinalised;
use App\Modules\Sales\Exceptions\UnitNoLongerAvailable;
use App\Modules\Sales\Models\InvoicePayment;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesInvoiceItem;
use App\Support\Counters\CounterService;
use App\Support\Settings\CommissionSettings;
use App\Support\Settings\ShopSettings;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use RuntimeException;

/**
 * Turning a basket into a sale. One transaction, seven things, all or nothing.
 *
 * This is the most concurrency-sensitive code in the product, because the failure it
 * guards against is the one a shop cannot recover from: two salespeople at two tills
 * selling the same handset within a second of each other. One of them has to lose, and
 * they have to lose *cleanly* — a specific Persian error naming the device, while the
 * customer is still standing there — not a 500, and never a silent double-sale.
 *
 * ## How the race is actually won
 *
 * Two layers, and the order matters:
 *
 * 1. **`SELECT … FOR UPDATE` on the units**, taken before anything is written. The
 *    second transaction blocks here until the first commits, then sees `sold` and
 *    raises {@see UnitNoLongerAvailable}. This is the layer that produces the good
 *    error message.
 * 2. **`UnitStateMachine`**, which refuses `sold → sold` regardless, so any future path
 *    that forgets the lock still cannot double-sell — it just fails less kindly.
 *
 * There is deliberately no unique index on `sales_invoice_items.product_unit_id`. It
 * looks like the obvious third layer and it is a trap: a handset sold, returned and
 * sold again legitimately appears on two invoices, and an index cannot tell that apart
 * from a double-sale because the invoice's status lives in another table.
 *
 * ## Why the number is allocated early
 *
 * It is tempting to take it last, so the counter's row lock — which every till in the
 * shop contends for — is held for as little of the transaction as possible. That was
 * the first shape of this method, and it was wrong: the passport note and every ledger
 * description quote the invoice number, so allocating it afterwards recorded an empty
 * one in both. The lock is held until commit either way; the saving was small and the
 * damage was silent.
 *
 * ## The event is dispatched after commit
 *
 * A listener that texts the customer from inside the transaction texts them about a
 * sale that may still roll back.
 */
final class FinaliseInvoice
{
    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly StockLedger $stock,
        private readonly UnitStateMachine $units,
        private readonly LedgerService $ledger,
        private readonly CounterService $counters,
        private readonly TenantContext $context,
        private readonly TradeInIntake $tradeIns,
        private readonly ShopSettings $settings,
    ) {}

    /**
     * @throws UnitNoLongerAvailable when a handset on this invoice was sold underneath it
     * @throws RuntimeException on any other refusal — all of them before a number is taken
     */
    public function finalise(SalesInvoice $invoice, ?int $actorId = null): SalesInvoice
    {
        $this->guardFinalisable($invoice);

        $tenantId = $this->context->idOrFail();
        $at = CarbonImmutable::now();

        /** @var SalesInvoice $finalised */
        $finalised = $this->connection->transaction(function () use ($invoice, $tenantId, $at, $actorId): SalesInvoice {
            $invoice->load(['items', 'payments']);

            // 1 — Lock the handsets first. Everything else in this transaction is
            // cheap; this is the line that decides who wins the race.
            $lockedUnits = $this->lockUnits($invoice);

            // 2 — The number, before anything that quotes it.
            $number = $this->counters->nextFormatted(
                $tenantId,
                'sales_invoice',
                'INV',
                $invoice->branch_id,
            );

            $invoice->forceFill(['number' => $number])->save();

            // 3 — Cost snapshots, before any movement changes what the average is.
            $this->snapshotCosts($invoice, $lockedUnits);

            // 4 — Stock. Serialized devices change state and write NO quantity
            // movement; a handset counted in both registers is counted twice.
            $warehouse = $this->warehouseFor($invoice);

            foreach ($invoice->items as $item) {
                $this->releaseStock($invoice, $item, $lockedUnits, $warehouse, $at, $actorId);
            }

            // 5 — The customer's old phone, if there is one. Inside this transaction on
            // purpose: the shop acquires it at the moment it sells the other handset,
            // and a trade-in surviving a rolled back sale is a device on the shelf that
            // nobody paid for.
            $this->receiveTradeIn($invoice, $actorId);

            // 6 — Money.
            $this->assertPaymentsAddUp($invoice);
            $this->postToLedger($invoice, $at, $actorId);

            $invoice->update([
                'status' => InvoiceStatus::Final,
                'issued_at' => $at,
                'paid_total' => $this->paidTotal($invoice),
                // 7 — What the salesperson earned. After the cost snapshots, because it
                // is a share of the margin they produced, and margin is not known until
                // cost is.
                ...$this->accrueCommission($invoice),
            ]);

            return $invoice;
        });

        // Outside the transaction, on purpose — see the class docblock.
        InvoiceFinalised::dispatch($finalised);

        return $finalised;
    }

    private function guardFinalisable(SalesInvoice $invoice): void
    {
        if (! $invoice->isDraft()) {
            throw new RuntimeException("فاکتور {$invoice->number} پیش‌نویس نیست و دوباره نهایی نمی‌شود.");
        }

        if ($invoice->isQuote()) {
            throw new RuntimeException('پیش‌فاکتور را باید اول به فاکتور تبدیل کرد.');
        }

        if ($invoice->items()->count() === 0) {
            throw new RuntimeException('فاکتور بدون ردیف نهایی نمی‌شود.');
        }
    }

    /**
     * Take a row lock on every handset this invoice sells, and refuse if any has moved.
     *
     * @return array<int, ProductUnit> keyed by unit id
     */
    private function lockUnits(SalesInvoice $invoice): array
    {
        /** @var list<int> $unitIds */
        $unitIds = $invoice->items
            ->filter(fn (SalesInvoiceItem $item): bool => $item->product_unit_id !== null)
            ->map(fn (SalesInvoiceItem $item): int => (int) $item->product_unit_id)
            ->values()
            ->all();

        if ($unitIds === []) {
            return [];
        }

        $units = ProductUnit::query()
            ->with('variant.product:id,name')
            ->whereKey($unitIds)
            // The whole guarantee. A second finalisation of the same handset blocks
            // here until the first commits, and then sees `sold`.
            ->lockForUpdate()
            ->get();

        $locked = [];

        foreach ($units as $unit) {
            // Reserved is fine — it was held for this customer. Anything else means
            // somebody got there first, or the device is on a repair bench.
            if ($unit->status !== UnitStatus::InStock && $unit->status !== UnitStatus::Reserved) {
                throw new UnitNoLongerAvailable(
                    unitId: $unit->id,
                    imei: $unit->imei1,
                    productName: $unit->variant->product->name,
                    currentStatus: $unit->status->value,
                );
            }

            $locked[$unit->id] = $unit;
        }

        // A unit that vanished between basket and finalisation — deleted, or another
        // shop's id typed in. Refused rather than silently skipped.
        foreach ($unitIds as $id) {
            if (! isset($locked[$id])) {
                throw new RuntimeException('یکی از دستگاه‌های این فاکتور دیگر در سیستم نیست.');
            }
        }

        return $locked;
    }

    /**
     * Write what each line actually cost, once, forever.
     *
     * @param  array<int, ProductUnit>  $units
     */
    private function snapshotCosts(SalesInvoice $invoice, array $units): void
    {
        $warehouseId = $this->warehouseFor($invoice)->id;

        foreach ($invoice->items as $item) {
            if ($item->product_unit_id !== null) {
                // Specific identity: this device's own purchase cost, including its
                // share of the freight that brought it in.
                $item->update(['cost_snapshot' => $units[$item->product_unit_id]->cost]);

                continue;
            }

            if ($item->product_variant_id === null) {
                continue;
            }

            $item->update([
                'cost_snapshot' => $this->stock->weightedAverageCost($item->product_variant_id, $warehouseId),
            ]);
        }
    }

    /**
     * @param  array<int, ProductUnit>  $units
     */
    private function releaseStock(
        SalesInvoice $invoice,
        SalesInvoiceItem $item,
        array $units,
        Warehouse $warehouse,
        CarbonImmutable $at,
        ?int $actorId,
    ): void {
        if ($item->product_unit_id !== null) {
            // No stock movement, deliberately: a serialized device never entered the
            // quantity ledger, so it must not leave through it either.
            $this->units->transition(
                $units[$item->product_unit_id],
                UnitStatus::Sold,
                reference: $invoice,
                note: "فروش با فاکتور {$invoice->number}",
                actorId: $actorId,
            );

            return;
        }

        if ($item->product_variant_id === null) {
            return;
        }

        $this->stock->record(
            $item->product_variant_id,
            $warehouse->id,
            -$item->quantity,
            MovementType::Sale,
            reference: $invoice,
            unitCost: $item->cost_snapshot,
            occurredAt: $at,
            actorId: $actorId,
        );
    }

    /**
     * Turn a معاوضه row into a real used handset on the shelf.
     */
    private function receiveTradeIn(SalesInvoice $invoice, ?int $actorId): void
    {
        $tradeIn = $invoice->tradeIn()->first();

        if ($tradeIn === null) {
            return;
        }

        // Idempotent: a retried finalisation must not mint the customer's phone twice.
        if ($tradeIn->product_unit_id !== null) {
            return;
        }

        $this->tradeIns->receive($tradeIn, $invoice, $actorId);
    }

    /**
     * The payments must not exceed the invoice, and a credit line must have somebody
     * to owe it.
     */
    private function assertPaymentsAddUp(SalesInvoice $invoice): void
    {
        $paid = $this->paidTotal($invoice);

        if ($paid > $invoice->total) {
            // One legitimate way to overpay: the customer's old phone is worth more than
            // the new one, and the shop owes them the difference. Every other way is a
            // typo, and letting a mistyped cash figure through would put money in the
            // drawer that is not there.
            $fromTradeIn = $invoice->payments
                ->filter(fn (InvoicePayment $payment): bool => $payment->method === PaymentMethod::TradeIn)
                ->sum(fn (InvoicePayment $payment): int => $payment->amount);

            $overpaid = $paid - $invoice->total;

            if ($fromTradeIn < $overpaid || $invoice->party_id === null) {
                throw new RuntimeException('مجموع پرداخت‌ها از مبلغ فاکتور بیشتر است.');
            }
        }

        $onCredit = $invoice->payments
            ->filter(fn (InvoicePayment $payment): bool => $payment->method === PaymentMethod::Credit)
            ->isNotEmpty();

        // An unpaid balance is itself credit, whether or not anybody ticked the box.
        if (($onCredit || $paid < $invoice->total) && $invoice->party_id === null) {
            throw new RuntimeException('فروش نسیه بدون طرف حساب ممکن نیست — مشتری را انتخاب کنید.');
        }
    }

    /**
     * Revenue in, money where it landed, and whatever is left owing on the customer.
     */
    private function postToLedger(SalesInvoice $invoice, CarbonImmutable $at, ?int $actorId): void
    {
        $lines = [];

        foreach ($invoice->payments as $payment) {
            if (! $payment->method->settlesImmediately() || $payment->account_id === null) {
                continue;
            }

            $lines[] = [
                'account_id' => $payment->account_id,
                'branch_id' => $invoice->branch_id,
                'debit' => $payment->amount,
                'description' => "فروش فاکتور {$invoice->number}",
            ];
        }

        // A trade-in settles the invoice without money moving: what arrives is a handset,
        // so the debit lands on inventory. Without this the entry would not balance and
        // the shop's stock would be worth less on paper than on the shelf.
        $tradedIn = (int) $invoice->payments
            ->filter(fn (InvoicePayment $payment): bool => $payment->method === PaymentMethod::TradeIn)
            ->sum(fn (InvoicePayment $payment): int => $payment->amount);

        if ($tradedIn > 0) {
            $inventory = Account::query()->where('type', Account::TYPE_INVENTORY)->first();

            if (! $inventory instanceof Account) {
                throw new RuntimeException('حساب موجودی کالا تعریف نشده است؛ دستگاه معاوضه‌ای جایی برای ثبت ندارد.');
            }

            $lines[] = [
                'account_id' => $inventory->id,
                'branch_id' => $invoice->branch_id,
                'debit' => $tradedIn,
                'description' => "معاوضه در فاکتور {$invoice->number}",
            ];
        }

        $settled = array_sum(array_column($lines, 'debit'));
        $owed = $invoice->total - $settled;

        // Everything not settled in cash is owed by the customer — a credit line, an
        // uncovered balance, or a cheque that has not cleared yet.
        if ($owed > 0 && $invoice->party_id !== null) {
            $lines[] = [
                'party_id' => $invoice->party_id,
                'branch_id' => $invoice->branch_id,
                'debit' => $owed,
                'description' => "فروش فاکتور {$invoice->number}",
            ];
        }

        // The trade-in was worth more than the new phone. The shop owes the difference,
        // and says so on the customer's account rather than quietly keeping it — paying
        // it out is a Treasury act (Phase 7).
        if ($owed < 0 && $invoice->party_id !== null) {
            $lines[] = [
                'party_id' => $invoice->party_id,
                'branch_id' => $invoice->branch_id,
                'credit' => -$owed,
                'description' => "مابه‌التفاوت معاوضه فاکتور {$invoice->number}",
            ];
        }

        if ($lines === []) {
            return;
        }

        $sales = Account::query()->where('type', Account::TYPE_SALES)->first();

        if (! $sales instanceof Account) {
            throw new RuntimeException('حساب فروش تعریف نشده است؛ بدون آن درآمد جایی ثبت نمی‌شود.');
        }

        // The credit side: whatever the debits add up to, less anything already credited
        // above. Computed from the lines rather than from `$invoice->total` so the entry
        // balances by construction — an over-value trade-in makes the two differ.
        $debits = (int) array_sum(array_column($lines, 'debit'));
        $credits = (int) array_sum(array_column($lines, 'credit'));

        $lines[] = [
            'account_id' => $sales->id,
            'branch_id' => $invoice->branch_id,
            'credit' => $debits - $credits,
            'description' => "فروش فاکتور {$invoice->number}",
        ];

        /** @var list<array{party_id?: int|null, account_id?: int|null, debit?: int, credit?: int, description?: string|null, branch_id?: int|null}> $lines */
        $this->ledger->post($lines, reference: $invoice, occurredAt: $at, actorId: $actorId);
    }

    /**
     * The salesperson's share of what this sale made.
     *
     * Approved at DECISION GATE 3: a percentage of **margin**, not of turnover, so that
     * a discount costs the person who gave it. See {@see CommissionSettings}.
     *
     * Margin is revenue net of VAT less cost — the same figure {@see ProfitEngine} uses,
     * deliberately. Tax collected is the state's money briefly held, and paying
     * commission on it would pay a salesperson a share of somebody else's money.
     *
     * The rate is read from the invoice's own snapshot where it has one, so a rate
     * changed next month does not restate what was earned this one; a shop that has set
     * no rate accrues nothing at all.
     *
     * @return array{commission_amount: int, commission_rate: int}
     */
    private function accrueCommission(SalesInvoice $invoice): array
    {
        // Nobody to pay. A walk-in sale rung up by an owner who is not on commission is
        // the ordinary case, not an omission to correct.
        if ($invoice->salesperson_id === null) {
            return ['commission_amount' => 0, 'commission_rate' => 0];
        }

        $snapshot = $invoice->settings_snapshot ?? [];
        $snapshotRate = $snapshot['commission_rate'] ?? null;

        $commission = is_int($snapshotRate)
            ? new CommissionSettings($snapshotRate)
            : $this->settings->commission();

        if (! $commission->isEnabled()) {
            return ['commission_amount' => 0, 'commission_rate' => 0];
        }

        $margin = 0;

        foreach ($invoice->items as $item) {
            // Per line, then summed — not on the invoice's net margin. They are the same
            // number today, and they stop being the same the day a shop wants a
            // different rate for accessories than for handsets.
            $margin += ($item->line_total - $item->vat_amount) - ($item->cost_snapshot * $item->quantity);
        }

        return [
            'commission_amount' => $commission->on($margin),
            'commission_rate' => $commission->rate,
        ];
    }

    private function paidTotal(SalesInvoice $invoice): int
    {
        return (int) $invoice->payments
            ->filter(fn (InvoicePayment $payment): bool => $payment->method !== PaymentMethod::Credit)
            ->sum(fn (InvoicePayment $payment): int => $payment->amount);
    }

    /**
     * Which warehouse the goods leave from.
     *
     * The branch's sellable default. A shop with a repair bench marked non-sellable
     * must not have a sale quietly consume the parts committed to a job.
     */
    private function warehouseFor(SalesInvoice $invoice): Warehouse
    {
        $warehouse = Warehouse::query()
            ->where('branch_id', $invoice->branch_id)
            ->where('is_active', true)
            ->where('is_sellable', true)
            ->orderByDesc('is_default')
            ->first();

        if (! $warehouse instanceof Warehouse) {
            throw new RuntimeException('این شعبه انبار قابل فروشی ندارد.');
        }

        return $warehouse;
    }
}
