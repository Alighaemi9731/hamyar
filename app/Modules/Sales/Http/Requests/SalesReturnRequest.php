<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Requests;

use App\Support\Money;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * What is coming back, and in what condition.
 *
 * The quantity ceiling is **not** validated here. Whether a line still has something
 * returnable depends on every prior return against it, which has to be read inside the
 * same transaction that writes this one — otherwise two people refunding the same
 * charger at the same moment both pass validation. {@see \App\Modules\Sales\Services\RecordReturn}
 * owns that check, under the lock; this class only refuses what is nonsense on its face.
 */
final class SalesReturnRequest extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'unit' => ['required', Rule::in([Money::UNIT_RIAL, Money::UNIT_TOMAN])],
            'reason' => ['nullable', 'string', 'max:500'],

            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.item_id' => ['required', 'integer'],
            // Zero is allowed and means "this line is not coming back". The form lists
            // every line of the invoice, and refusing the untouched ones would make a
            // one-line return out of a four-line invoice fail validation.
            'lines.*.quantity' => ['required', 'integer', 'min:0', 'max:100000'],
            'lines.*.refund_amount' => ['nullable', 'integer', 'min:0', 'max:99999999999'],
            'lines.*.regrade' => ['nullable', 'string', 'max:10'],
            'lines.*.restock' => ['nullable', 'boolean'],
        ];
    }

    /**
     * Something has to actually be coming back.
     *
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($this->lines() === []) {
                    $validator->errors()->add('lines', 'برای هیچ ردیفی تعداد برگشتی وارد نشده است.');
                }
            },
        ];
    }

    /**
     * @return list<array{item_id: int, quantity: int, refund_amount: int|null, regrade: string|null, restock: bool}>
     */
    public function lines(): array
    {
        /** @var array<int, array<string, mixed>> $lines */
        $lines = $this->array('lines');

        $mapped = array_map(function (array $line): array {
            $refund = $line['refund_amount'] ?? null;

            return [
                'item_id' => is_numeric($line['item_id'] ?? null) ? (int) $line['item_id'] : 0,
                'quantity' => is_numeric($line['quantity'] ?? null) ? (int) $line['quantity'] : 1,
                'refund_amount' => is_numeric($refund) ? $this->rial((int) $refund) : null,
                'regrade' => is_string($line['regrade'] ?? null) && $line['regrade'] !== ''
                    ? (string) $line['regrade']
                    : null,
                'restock' => ($line['restock'] ?? false) === true || ($line['restock'] ?? null) === '1',
            ];
        }, $lines);

        // A form that lists every line of the invoice submits the untouched ones as
        // zero. They are dropped here rather than refused: leaving three lines alone is
        // the normal shape of a partial return, not a mistake to correct.
        return array_values(array_filter($mapped, fn (array $line): bool => $line['quantity'] > 0));
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
            'lines.required' => 'هیچ ردیفی برای برگشت انتخاب نشده است.',
            'lines.min' => 'هیچ ردیفی برای برگشت انتخاب نشده است.',
        ];
    }
}
