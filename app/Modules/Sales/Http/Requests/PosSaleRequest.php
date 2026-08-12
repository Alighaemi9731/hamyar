<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Requests;

use App\Modules\Sales\Enums\PaymentMethod;
use App\Support\Money;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * One whole basket, submitted at once.
 *
 * ## Why the entire sale arrives in a single request
 *
 * The alternative — a request per scanned line — was considered and rejected. A shop's
 * connection is not a datacentre's, and a POS that pauses for a round trip on every scan
 * loses to the paper notebook it is replacing. So the browser holds the basket, scanning
 * costs one lookup, and the sale posts once.
 *
 * The cost of that choice is this class: everything the browser has been holding has to
 * be re-checked here, because it has been out of our sight. Prices and discounts are
 * accepted (they are negotiated at the counter); ids are re-resolved, VAT and cost are
 * recomputed server-side, and stock is re-checked under a lock.
 *
 * ## Amounts arrive in one unit for the whole payload
 *
 * Every other money form in the app carries `unit` beside a single amount. A basket has
 * a dozen amounts across two nested arrays and the POS renders all of them in the same
 * unit, so repeating it per field would be noise that could disagree with itself. One
 * declaration, applied to everything.
 */
final class PosSaleRequest extends FormRequest
{
    /** A basket bigger than this is a data-entry accident, not a sale. */
    private const MAX_LINES = 200;

    private const MAX_PAYMENTS = 20;

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'party_id' => ['nullable', 'integer', 'exists:parties,id'],
            'salesperson_id' => ['nullable', 'integer', 'exists:users,id'],
            'unit' => ['required', Rule::in([Money::UNIT_RIAL, Money::UNIT_TOMAN])],

            // `park` keeps the basket as a draft for a customer who has gone to fetch
            // money; `quote` issues a numbered پیش‌فاکتور to hand them; `finalise` sells
            // it. Nothing else may be asked for.
            'action' => ['required', Rule::in(['park', 'quote', 'finalise'])],

            'vat_applied' => ['required', 'boolean'],
            'discount_amount' => ['required', 'integer', 'min:0', 'max:99999999999'],
            'shipping_amount' => ['required', 'integer', 'min:0', 'max:99999999999'],
            'notes' => ['nullable', 'string', 'max:2000'],

            'lines' => ['required', 'array', 'min:1', 'max:'.self::MAX_LINES],
            'lines.*.unit_id' => ['nullable', 'integer', 'exists:product_units,id'],
            'lines.*.variant_id' => ['nullable', 'integer', 'exists:product_variants,id'],
            'lines.*.description' => ['nullable', 'string', 'max:255'],
            'lines.*.quantity' => ['required', 'integer', 'min:1', 'max:100000'],
            'lines.*.unit_price' => ['required', 'integer', 'min:0', 'max:99999999999'],
            'lines.*.discount_amount' => ['required', 'integer', 'min:0', 'max:99999999999'],
            'lines.*.warranty_months' => ['nullable', 'integer', 'min:0', 'max:120'],

            'payments' => ['present', 'array', 'max:'.self::MAX_PAYMENTS],
            'payments.*.method' => ['required', Rule::enum(PaymentMethod::class)],
            'payments.*.amount' => ['required', 'integer', 'min:0', 'max:99999999999'],
            'payments.*.tendered_amount' => ['nullable', 'integer', 'min:0', 'max:99999999999'],
            'payments.*.account_id' => ['nullable', 'integer', 'exists:accounts,id'],
            'payments.*.reference' => ['nullable', 'string', 'max:120'],

            /*
            | معاوضه — the customer's old phone, taken in part-payment. Absent on almost
            | every sale, so every field is nullable and the whole block is optional.
            */
            'trade_in' => ['nullable', 'array'],
            'trade_in.device_name' => ['required_with:trade_in', 'string', 'max:180'],
            'trade_in.product_variant_id' => ['required_with:trade_in', 'integer', 'exists:product_variants,id'],
            'trade_in.imei1' => ['nullable', 'string', 'max:30'],
            'trade_in.grade' => ['nullable', 'string', 'max:10'],
            'trade_in.agreed_price' => ['required_with:trade_in', 'integer', 'min:1', 'max:99999999999'],
            // Checked in `after()` rather than with `accepted`, which fails on an absent
            // value and so refused every ordinary sale that carries no trade-in at all.
            'trade_in.hamta_ack' => ['nullable', 'boolean'],
        ];
    }

    /**
     * The checks that need to see more than one field at a time.
     *
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                /** @var array<int, array<string, mixed>> $lines */
                $lines = $this->array('lines');

                foreach ($lines as $index => $line) {
                    $hasUnit = ($line['unit_id'] ?? null) !== null;
                    $hasVariant = ($line['variant_id'] ?? null) !== null;

                    // The database enforces this too (`sales_invoice_items_one_subject`).
                    // Caught here so the operator gets a Persian sentence instead of a
                    // constraint violation.
                    if (! $hasUnit && ! $hasVariant) {
                        $validator->errors()->add(
                            "lines.{$index}.variant_id",
                            'این ردیف به هیچ کالا یا دستگاهی وصل نیست.'
                        );
                    }

                    // A handset is one device. Silently forcing the quantity to 1 would
                    // hide a mistake that the customer discovers at the door.
                    if ($hasUnit && $this->intFrom($line['quantity'] ?? 1, 1) !== 1) {
                        $validator->errors()->add(
                            "lines.{$index}.quantity",
                            'هر دستگاه سریال‌دار یک ردیف جداگانه است؛ تعداد آن نمی‌تواند بیشتر از ۱ باشد.'
                        );
                    }
                }

                /** @var array<int, array<string, mixed>> $payments */
                $payments = $this->array('payments');

                // The HAMTA tick, only when there is a trade-in to tick it for.
                if (is_array($this->input('trade_in')) && ($this->input('trade_in.hamta_ack') ?? false) !== true) {
                    $validator->errors()->add('trade_in.hamta_ack', 'تأیید انتقال مالکیت همتا الزامی است.');
                }

                foreach ($payments as $index => $payment) {
                    $method = PaymentMethod::tryFrom($this->stringFrom($payment['method'] ?? null) ?? '');

                    if (! $method instanceof PaymentMethod) {
                        continue;
                    }

                    if ($method->needsAccount() && ($payment['account_id'] ?? null) === null) {
                        $validator->errors()->add(
                            "payments.{$index}.account_id",
                            "پرداخت {$method->labelFa()} باید به یک صندوق یا حساب بنشیند."
                        );
                    }

                    // Tendered below settled would mint change out of nothing. There is a
                    // CHECK constraint behind this as well.
                    $tendered = $payment['tendered_amount'] ?? null;

                    if ($tendered !== null && $this->intFrom($tendered) < $this->intFrom($payment['amount'] ?? 0)) {
                        $validator->errors()->add(
                            "payments.{$index}.tendered_amount",
                            'مبلغ دریافتی از مبلغ این پرداخت کمتر است.'
                        );
                    }
                }
            },
        ];
    }

    /**
     * The lines, with every amount converted to rial.
     *
     * @return list<array{unit_id: int|null, variant_id: int|null, description: string|null, quantity: int, unit_price: int, discount_amount: int, warranty_months: int|null}>
     */
    public function lines(): array
    {
        /** @var array<int, array<string, mixed>> $lines */
        $lines = $this->array('lines');

        return array_values(array_map(fn (array $line): array => [
            'unit_id' => $this->nullableIntFrom($line['unit_id'] ?? null),
            'variant_id' => $this->nullableIntFrom($line['variant_id'] ?? null),
            'description' => $this->stringFrom($line['description'] ?? null),
            'quantity' => $this->intFrom($line['quantity'] ?? 1, 1),
            'unit_price' => $this->rial($this->intFrom($line['unit_price'] ?? 0)),
            'discount_amount' => $this->rial($this->intFrom($line['discount_amount'] ?? 0)),
            'warranty_months' => $this->nullableIntFrom($line['warranty_months'] ?? null),
        ], $lines));
    }

    /**
     * @return list<array{method: string, amount: int, tendered_amount: int|null, account_id: int|null, reference: string|null}>
     */
    public function payments(): array
    {
        /** @var array<int, array<string, mixed>> $payments */
        $payments = $this->array('payments');

        return array_values(array_map(function (array $payment): array {
            $tendered = $this->nullableIntFrom($payment['tendered_amount'] ?? null);

            return [
                'method' => $this->stringFrom($payment['method'] ?? null) ?? '',
                'amount' => $this->rial($this->intFrom($payment['amount'] ?? 0)),
                'tendered_amount' => $tendered === null ? null : $this->rial($tendered),
                'account_id' => $this->nullableIntFrom($payment['account_id'] ?? null),
                'reference' => $this->stringFrom($payment['reference'] ?? null),
            ];
        }, $payments));
    }

    public function invoiceDiscount(): int
    {
        return $this->rial($this->integer('discount_amount'));
    }

    public function shipping(): int
    {
        return $this->rial($this->integer('shipping_amount'));
    }

    public function shouldFinalise(): bool
    {
        return $this->string('action')->value() === 'finalise';
    }

    public function isQuote(): bool
    {
        return $this->string('action')->value() === 'quote';
    }

    /**
     * The trade-in, in rial, or null when the customer is not swapping anything.
     *
     * @return array{device_name: string, product_variant_id: int, imei1: string|null, grade: string|null, agreed_price: int, hamta_ack: bool}|null
     */
    public function tradeIn(): ?array
    {
        if (! $this->has('trade_in') || ! is_array($this->input('trade_in'))) {
            return null;
        }

        /** @var array<string, mixed> $tradeIn */
        $tradeIn = $this->input('trade_in');

        return [
            'device_name' => $this->stringFrom($tradeIn['device_name'] ?? null) ?? '',
            'product_variant_id' => $this->intFrom($tradeIn['product_variant_id'] ?? null),
            'imei1' => $this->stringFrom($tradeIn['imei1'] ?? null),
            'grade' => $this->stringFrom($tradeIn['grade'] ?? null),
            'agreed_price' => $this->rial($this->intFrom($tradeIn['agreed_price'] ?? 0)),
            'hamta_ack' => ($tradeIn['hamta_ack'] ?? false) === true,
        ];
    }

    /**
     * Coerce one submitted value to an integer.
     *
     * The validator has already refused anything non-numeric; these exist so the
     * conversion is written once, with the fallback visible, rather than as a cast on
     * every field that the analyser cannot verify.
     */
    private function intFrom(mixed $value, int $default = 0): int
    {
        return is_numeric($value) ? (int) $value : $default;
    }

    private function nullableIntFrom(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private function stringFrom(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function rial(int $amount): int
    {
        return $this->string('unit')->value() === Money::UNIT_TOMAN
            ? Money::fromToman($amount)
            : $amount;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'branch_id.required' => 'شعبه فروش مشخص نیست.',
            'lines.required' => 'فاکتور خالی است؛ دست‌کم یک کالا اسکن کنید.',
            'lines.min' => 'فاکتور خالی است؛ دست‌کم یک کالا اسکن کنید.',
            'lines.*.unit_price.required' => 'قیمت این ردیف را وارد کنید.',
            'trade_in.product_variant_id.required_with' => 'مدل دستگاه معاوضه‌ای را از فهرست کالاها انتخاب کنید.',
            'trade_in.agreed_price.required_with' => 'مبلغ توافق‌شده معاوضه را وارد کنید.',
            'trade_in.agreed_price.min' => 'مبلغ توافق‌شده معاوضه باید بیشتر از صفر باشد.',
        ];
    }
}
