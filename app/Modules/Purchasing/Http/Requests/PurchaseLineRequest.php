<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Http\Requests;

use App\Support\Money;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * One standard line on a shipment: a quantity of something that is not a handset.
 */
final class PurchaseLineRequest extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'product_variant_id' => ['required', 'integer', 'exists:product_variants,id'],
            'quantity' => ['required', 'integer', 'min:1', 'max:100000'],
            'amount' => ['required', 'integer', 'min:0', 'max:99999999999'],
            'unit' => ['required', Rule::in([Money::UNIT_RIAL, Money::UNIT_TOMAN])],
        ];
    }

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
            'product_variant_id.required' => 'کالا را انتخاب کنید.',
            'quantity.min' => 'تعداد باید دست‌کم ۱ باشد.',
            'amount.required' => 'بهای خرید هر واحد را وارد کنید.',
        ];
    }
}
