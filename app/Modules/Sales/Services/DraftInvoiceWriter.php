<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services;

use App\Modules\Inventory\Enums\UnitStatus;
use App\Modules\Inventory\Models\ProductUnit;
use App\Modules\Sales\Enums\PaymentMethod;
use App\Modules\Sales\Models\SalesInvoice;
use App\Support\Settings\ShopSettings;
use Illuminate\Database\ConnectionInterface;
use RuntimeException;

/**
 * Turning a basket into a draft.
 *
 * ## The draft is replaced, never patched
 *
 * Every write sends the whole basket and this deletes what was there. It is tempting to
 * diff — keep line ids stable, update what changed — and it is the wrong shape for a
 * till. The POS holds the basket in the browser so scanning stays instant; by the time
 * it posts, "which line is which" is a question only the browser can answer, and a diff
 * would make the server trust the browser's answer about rows it can no longer see.
 * Replacing is one statement, has no merge cases, and cannot leave an orphan line.
 *
 * This only ever touches drafts. A final invoice is stock, money and a numbered tax
 * document, and {@see FinaliseInvoice} is the one door between the two.
 *
 * ## What the client may decide, and what it may not
 *
 * The browser sends the **price** and the **discount**, because both are negotiated at
 * the counter and the shop is allowed to sell a phone for what it can get for it. It
 * does not send the **VAT rate**, the **cost**, or the **line total**: tax is a
 * statutory figure the shop does not get to choose per sale, cost is the number the
 * whole profit engine rests on, and the total is arithmetic. All three are computed
 * here or at finalisation, from settings and from the ledger.
 *
 * ## Cash tendered and cash applied
 *
 * A customer hands over 500,000 toman for a 482,000 invoice. The payment row settles
 * 482,000 — that is what the drawer keeps and what the ledger posts — and stores 500,000
 * beside it so the receipt can say what change was given. The clamp happens here rather
 * than in the browser, so a mistyped tender cannot post a payment larger than the sale.
 */
final class DraftInvoiceWriter
{
    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly InvoiceTotals $totals,
        private readonly ShopSettings $settings,
    ) {}

    /**
     * Write a basket onto a draft invoice and recompute every figure.
     *
     * @param  list<array{unit_id?: int|null, variant_id?: int|null, is_service?: bool, description?: string|null, quantity?: int, unit_price?: int, discount_amount?: int, warranty_months?: int|null}>  $lines
     * @param  list<array{method: string, amount: int, tendered_amount?: int|null, account_id?: int|null, reference?: string|null}>  $payments
     */
    public function write(SalesInvoice $invoice, array $lines, array $payments, ?int $actorId = null): SalesInvoice
    {
        if (! $invoice->isDraft()) {
            throw new RuntimeException('فاکتور نهایی‌شده ویرایش نمی‌شود؛ برای اصلاح آن، برگشت از فروش ثبت کنید.');
        }

        /** @var SalesInvoice $written */
        $written = $this->connection->transaction(function () use ($invoice, $lines, $payments, $actorId): SalesInvoice {
            $this->writeLines($invoice, $lines);

            // Totals before payments, deliberately: the cash clamp needs to know what is
            // due, and what is due is not known until the lines have been priced, the
            // invoice discount distributed and the grand total rounded.
            $this->totals->recalculate($invoice);

            $this->writePayments($invoice, $payments, $actorId);

            // `paid_total` is a convenience for list screens; finalisation asserts it
            // against the rows before it commits. Kept in step here so a parked draft
            // shows the right outstanding figure on the invoice list.
            $invoice->forceFill([
                'paid_total' => (int) $invoice->payments()
                    ->where('method', '!=', PaymentMethod::Credit->value)
                    ->sum('amount'),
            ])->save();

            return $invoice->refresh();
        });

        return $written;
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    private function writeLines(SalesInvoice $invoice, array $lines): void
    {
        $invoice->items()->delete();

        $vat = $this->settings->vat();
        $vatRate = $vat->rateWhen($this->vatApplies($invoice, $vat->enabled));

        $units = $this->lockSellableUnits($lines);

        foreach ($lines as $line) {
            $unitId = $this->intOrNull($line['unit_id'] ?? null);
            $variantId = $this->intOrNull($line['variant_id'] ?? null);

            // A service line sells work rather than goods — a repair charge, or a part
            // already consumed off the shelf that must not be deducted twice. It carries
            // no stock subject by design, and the database enforces that pairing.
            $isService = ($line['is_service'] ?? false) === true;

            if (! $isService && $unitId === null && $variantId === null) {
                throw new RuntimeException('هر ردیف فاکتور باید یک کالا یا یک دستگاه مشخص داشته باشد.');
            }

            $unit = $unitId === null ? null : $units[$unitId];

            $invoice->items()->create([
                'product_variant_id' => $isService ? null : ($variantId ?? $unit?->product_variant_id),
                'product_unit_id' => $isService ? null : $unitId,
                'is_service' => $isService,
                // Copied at sale time, never looked up later: a product renamed next
                // year must not rewrite what this invoice says it sold.
                'description' => $this->description($line, $unit),
                // A handset is one device. A quantity of two on a serialized line would
                // deduct one thing twice and hand the customer one phone.
                'quantity' => $unitId !== null ? 1 : max(1, $this->intOrZero($line['quantity'] ?? 1)),
                'unit_price' => max(0, $this->intOrZero($line['unit_price'] ?? 0)),
                'discount_amount' => max(0, $this->intOrZero($line['discount_amount'] ?? 0)),
                'vat_rate' => $vatRate,
                // Both filled by InvoiceTotals immediately after; cost is filled at
                // finalisation, from the ledger, and never by the browser.
                'vat_amount' => 0,
                'line_total' => 0,
                'cost_snapshot' => 0,
                'warranty_months' => $this->intOrNull($line['warranty_months'] ?? null),
            ]);
        }
    }

    /**
     * Take the handsets on this basket under a row lock and refuse the unavailable ones.
     *
     * This is **not** the double-sell guard — {@see FinaliseInvoice} owns that, inside
     * the transaction that actually sells. This is the earlier, kinder check: it stops a
     * salesperson building a whole basket around a phone that was sold ten minutes ago
     * and only finding out when they press the button with the customer watching.
     *
     * @param  list<array<string, mixed>>  $lines
     * @return array<int, ProductUnit>
     */
    private function lockSellableUnits(array $lines): array
    {
        $ids = [];

        foreach ($lines as $line) {
            $id = $this->intOrNull($line['unit_id'] ?? null);

            if ($id !== null) {
                // The same handset twice in one basket. Caught here rather than by the
                // state machine, which would report the second line as "already sold" and
                // send somebody looking for a sale that does not exist.
                if (in_array($id, $ids, true)) {
                    throw new RuntimeException('یک دستگاه دو بار در این فاکتور آمده است.');
                }

                $ids[] = $id;
            }
        }

        if ($ids === []) {
            return [];
        }

        $units = ProductUnit::query()
            ->with('variant.product:id,name')
            ->whereKey($ids)
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        $locked = [];

        foreach ($ids as $id) {
            $unit = $units->get($id);

            if (! $unit instanceof ProductUnit) {
                throw new RuntimeException('یکی از دستگاه‌های این فاکتور در سیستم نیست.');
            }

            // Reserved is allowed: it was held for this customer, and finalisation
            // accepts it for the same reason.
            if ($unit->status !== UnitStatus::InStock && $unit->status !== UnitStatus::Reserved) {
                throw new RuntimeException(
                    "دستگاه {$unit->variant->product->name} دیگر قابل فروش نیست ({$unit->status->labelFa()})."
                );
            }

            $locked[$id] = $unit;
        }

        return $locked;
    }

    /**
     * @param  list<array<string, mixed>>  $payments
     */
    private function writePayments(SalesInvoice $invoice, array $payments, ?int $actorId): void
    {
        $invoice->payments()->delete();

        $applied = 0;

        // A معاوضه settles first, and is never clamped.
        //
        // Both halves of that matter. **First**, because at a real counter the old phone
        // is valued and agreed before anybody counts cash — applying it last let a
        // mistyped cash figure consume the whole invoice and stack the trade-in on top.
        // **Unclamped**, because the agreed price is an exact figure the customer was
        // told: capping it at the invoice total would silently pay them less than agreed
        // when their phone is worth more than the one they are buying, which is a real
        // and ordinary sale.
        $ordered = [
            ...array_filter($payments, fn (array $payment): bool => ($payment['method'] ?? '') === PaymentMethod::TradeIn->value),
            ...array_filter($payments, fn (array $payment): bool => ($payment['method'] ?? '') !== PaymentMethod::TradeIn->value),
        ];

        foreach ($ordered as $payment) {
            $method = PaymentMethod::tryFrom($this->stringOrNull($payment['method'] ?? null) ?? '');

            if (! $method instanceof PaymentMethod) {
                throw new RuntimeException('روش پرداخت نامعتبر است.');
            }

            // Tendered is only ever what the cashier explicitly recorded as handed over.
            // Inferring it from an over-large `amount` conflates two different claims —
            // "this settles X" and "the customer gave me X" — and would turn a slipped
            // zero in the amount field into a receipt promising 360,000,000 in change.
            $tendered = max(0, $this->intOrZero($payment['tendered_amount'] ?? 0));
            $requested = max(0, $this->intOrZero($payment['amount'] ?? 0));

            // Never settle more than is left owing — a customer handing over a round
            // note is the ordinary case, and the difference is change, not revenue. The
            // trade-in is exempt, per the ordering note above.
            $amount = $method === PaymentMethod::TradeIn
                ? $requested
                : min($requested, max(0, $invoice->total - $applied));

            // A zero-value tender is not a payment. Dropped rather than refused: the POS
            // keeps an empty row in the payment box while the operator decides, and
            // rejecting the submit for it would be pedantry at the counter.
            if ($amount === 0) {
                continue;
            }

            $accountId = $this->intOrNull($payment['account_id'] ?? null);

            if ($method->needsAccount() && $accountId === null) {
                throw new RuntimeException("برای پرداخت {$method->labelFa()} باید صندوق یا حساب مقصد را انتخاب کنید.");
            }

            // Dropped for a method that does not settle into an account. The POS
            // pre-fills every new payment row with the default cash box and then hides
            // the field when the operator picks چک — so the id rode along on a cheque
            // that puts nothing in the drawer, and the Z-report's by-account panel added
            // 45,000,000 of post-dated cheques to a till holding 30,000,000 in notes.
            // Cleared here rather than only in the browser: the server decides what a
            // payment row means.
            if (! $method->needsAccount()) {
                $accountId = null;
            }

            $invoice->payments()->create([
                'method' => $method,
                'account_id' => $accountId,
                'amount' => $amount,
                // Only worth keeping when it differs; a null reads as "exact".
                'tendered_amount' => $tendered > $amount ? $tendered : null,
                'reference' => $this->stringOrNull($payment['reference'] ?? null),
                'actor_id' => $actorId,
            ]);

            $applied += $amount;
        }
    }

    /**
     * Whether this invoice charges VAT.
     *
     * The invoice's own snapshot wins when it has one — a draft parked before the shop
     * registered for VAT keeps its answer — and the shop's current setting is the
     * default for a basket that has not been asked yet.
     */
    private function vatApplies(SalesInvoice $invoice, bool $default): bool
    {
        $snapshot = $invoice->settings_snapshot ?? [];
        $applied = $snapshot['vat_enabled'] ?? null;

        return is_bool($applied) ? $applied : $default;
    }

    /**
     * @param  array<string, mixed>  $line
     */
    private function description(array $line, ?ProductUnit $unit): string
    {
        $given = $this->stringOrNull($line['description'] ?? null);

        if ($given !== null) {
            return $given;
        }

        if ($unit instanceof ProductUnit) {
            // The product name only. The IMEI is NOT put in here: every screen and print
            // layout renders it from `product_unit_id`, so embedding it duplicated it on
            // the paper — and it then travelled into the public invoice page, which is
            // the one place a serial number must never appear (PublicInvoiceController).
            return $unit->variant->product->name;
        }

        return 'کالا';
    }

    private function intOrNull(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private function intOrZero(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
