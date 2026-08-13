<?php

declare(strict_types=1);

namespace App\Modules\Repairs\Http\Requests;

use App\Modules\Sales\Enums\PaymentMethod;
use App\Support\Money;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Handing the device back and settling the bill.
 *
 * Payments are validated to the same shape the till posts, deliberately — the settlement
 * runs through Sales' own writer, so anything this accepts has to be something that
 * writer already understands.
 */
final class TicketDeliveryRequest extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'unit' => ['required', Rule::in([Money::UNIT_RIAL, Money::UNIT_TOMAN])],
            'warranty_days' => ['required', 'integer', 'min:0', 'max:3650'],

            // Optional arrays: a `FormData` body cannot express an empty one, so a
            // delivery with no extra labour simply omits the key (CLAUDE.md convention).
            'labour' => ['nullable', 'array', 'max:20'],
            'labour.*.description' => ['required', 'string', 'max:160'],
            'labour.*.amount' => ['required', 'integer', 'min:0', 'max:99999999999'],
            'labour.*.quantity' => ['nullable', 'integer', 'min:1', 'max:1000'],

            'payments' => ['nullable', 'array', 'max:20'],
            'payments.*.method' => ['required', Rule::enum(PaymentMethod::class)],
            'payments.*.amount' => ['required', 'integer', 'min:0', 'max:99999999999'],
            'payments.*.tendered_amount' => ['nullable', 'integer', 'min:0', 'max:99999999999'],
            'payments.*.account_id' => ['nullable', 'integer', 'exists:accounts,id'],
            'payments.*.reference' => ['nullable', 'string', 'max:120'],

            // Drawn on a canvas and posted as a PNG. Small by nature; a large one means
            // somebody attached a photo to the wrong field.
            'signature' => ['nullable', 'file', 'mimetypes:image/png', 'max:2048'],
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                /** @var array<int, array<string, mixed>> $payments */
                $payments = $this->array('payments');

                foreach ($payments as $index => $payment) {
                    $method = PaymentMethod::tryFrom(is_string($payment['method'] ?? null) ? $payment['method'] : '');

                    if ($method instanceof PaymentMethod && $method->needsAccount() && ($payment['account_id'] ?? null) === null) {
                        $validator->errors()->add(
                            "payments.{$index}.account_id",
                            "پرداخت {$method->labelFa()} باید به یک صندوق یا حساب بنشیند."
                        );
                    }
                }
            },
        ];
    }

    /**
     * @return list<array{description: string, amount: int, quantity?: int}>
     */
    public function labour(): array
    {
        /** @var array<int, array<string, mixed>> $rows */
        $rows = $this->array('labour');

        return array_values(array_map(fn (array $row): array => [
            'description' => is_string($row['description'] ?? null) ? $row['description'] : 'دستمزد',
            'amount' => $this->rial(is_numeric($row['amount'] ?? null) ? (int) $row['amount'] : 0),
            'quantity' => is_numeric($row['quantity'] ?? null) ? (int) $row['quantity'] : 1,
        ], $rows));
    }

    /**
     * @return list<array{method: string, amount: int, tendered_amount: int|null, account_id: int|null, reference: string|null}>
     */
    public function payments(): array
    {
        /** @var array<int, array<string, mixed>> $rows */
        $rows = $this->array('payments');

        return array_values(array_map(function (array $row): array {
            $tendered = $row['tendered_amount'] ?? null;

            return [
                'method' => is_string($row['method'] ?? null) ? $row['method'] : '',
                'amount' => $this->rial(is_numeric($row['amount'] ?? null) ? (int) $row['amount'] : 0),
                'tendered_amount' => is_numeric($tendered) ? $this->rial((int) $tendered) : null,
                'account_id' => is_numeric($row['account_id'] ?? null) ? (int) $row['account_id'] : null,
                'reference' => is_string($row['reference'] ?? null) && trim($row['reference']) !== ''
                    ? trim($row['reference'])
                    : null,
            ];
        }, $rows));
    }

    private function rial(int $amount): int
    {
        return $this->string('unit')->value() === Money::UNIT_TOMAN
            ? Money::fromToman($amount)
            : $amount;
    }
}
