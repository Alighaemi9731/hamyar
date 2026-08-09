<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Requests;

use App\Support\Money;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Setting one price.
 *
 * The unit travels with the amount. Iranian shops quote in toman and store rial
 * (golden rule 2), and a client that silently multiplies by ten is one refactor away
 * from a factor-of-ten pricing error nobody notices until a customer does. Making the
 * unit explicit means the conversion happens once, on the server, where it is tested.
 */
final class PriceRequest extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'price_level_id' => ['required', 'integer', 'exists:price_levels,id'],
            'amount' => ['required', 'integer', 'min:0', 'max:99999999999'],
            'unit' => ['required', Rule::in([Money::UNIT_RIAL, Money::UNIT_TOMAN])],
            // A scheduled increase: the row is written now and does not apply until
            // its date arrives (see PriceResolver).
            'effective_from' => ['nullable', 'date'],
        ];
    }

    /**
     * The amount in integer rial, whatever unit it was typed in.
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
            'amount.required' => 'مبلغ را وارد کنید.',
            'amount.min' => 'قیمت نمی‌تواند منفی باشد.',
            'price_level_id.exists' => 'سطح قیمت انتخاب‌شده پیدا نشد.',
        ];
    }
}
