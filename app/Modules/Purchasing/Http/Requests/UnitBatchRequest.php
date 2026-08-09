<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Http\Requests;

use App\Modules\Inventory\Enums\UnitCondition;
use App\Support\Money;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Committing a pasted batch of IMEIs to a shipment.
 *
 * Note what is NOT validated here: which IMEIs are acceptable. The blob arrives raw
 * and is parsed by the server (see `PurchaseInvoiceDraft::addUnitLines()`), because a
 * request that carried per-line verdicts would let a client decide that a duplicate
 * handset is fine.
 */
final class UnitBatchRequest extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'imeis' => ['required', 'string', 'max:20000'],
            'product_variant_id' => ['required', 'integer', 'exists:product_variants,id'],
            'amount' => ['required', 'integer', 'min:0', 'max:99999999999'],
            'unit' => ['required', Rule::in([Money::UNIT_RIAL, Money::UNIT_TOMAN])],
            'condition' => ['required', Rule::enum(UnitCondition::class)],
            'grade' => ['nullable', 'string', 'max:2'],
            // The operator saying, in as many words, to leave the bad rows behind.
            'skip_rejected' => ['boolean'],
        ];
    }

    /**
     * The per-device cost in integer rial.
     */
    public function rial(): int
    {
        $amount = $this->integer('amount');

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
            'imeis.required' => 'شماره‌های IMEI را بچسبانید یا اسکن کنید.',
            'product_variant_id.required' => 'مدل دستگاه را انتخاب کنید.',
            'product_variant_id.exists' => 'مدل انتخاب‌شده پیدا نشد.',
            'amount.required' => 'بهای خرید هر دستگاه را وارد کنید.',
        ];
    }
}
