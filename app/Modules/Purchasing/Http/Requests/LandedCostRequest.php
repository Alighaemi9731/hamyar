<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Http\Requests;

use App\Modules\Purchasing\Services\LandedCostAllocator;
use App\Support\Money;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Freight, customs or courier on a shipment.
 *
 * `allocation` decides how the charge is spread across the lines, and the two methods
 * genuinely differ: freight usually goes by quantity (a heavy box costs the same
 * whatever is in it), customs by value.
 */
final class LandedCostRequest extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', Rule::in(['freight', 'customs', 'courier', 'other'])],
            'amount' => ['required', 'integer', 'min:1', 'max:99999999999'],
            'unit' => ['required', Rule::in([Money::UNIT_RIAL, Money::UNIT_TOMAN])],
            'allocation' => ['required', Rule::in([
                LandedCostAllocator::BY_VALUE,
                LandedCostAllocator::BY_QUANTITY,
            ])],
            'description' => ['nullable', 'string', 'max:200'],
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
            'amount.min' => 'مبلغ هزینه باید بیشتر از صفر باشد.',
            'allocation.required' => 'روش سرشکن‌کردن هزینه را انتخاب کنید.',
        ];
    }
}
