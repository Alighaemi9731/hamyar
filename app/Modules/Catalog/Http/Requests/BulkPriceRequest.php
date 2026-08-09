<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Requests;

use App\Modules\Catalog\Services\BulkPriceUpdater;
use App\Support\Money;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A bulk price move: "+8% on every Samsung accessory".
 *
 * The same request shape drives the preview and the apply, because
 * {@see BulkPriceUpdater} runs one code path for both — the preview IS the apply,
 * stopped before the write.
 */
final class BulkPriceRequest extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'price_level_id' => ['required', 'integer', 'exists:price_levels,id'],
            'mode' => ['required', Rule::in([
                BulkPriceUpdater::MODE_PERCENT,
                BulkPriceUpdater::MODE_AMOUNT,
                BulkPriceUpdater::MODE_SET,
            ])],
            'value' => ['required', 'integer', 'min:-100', 'max:99999999999'],
            'unit' => ['required', Rule::in([Money::UNIT_RIAL, Money::UNIT_TOMAN])],

            // Which lines are affected. Same filters as the grid, so what was on screen
            // is what moves.
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'brand_id' => ['nullable', 'integer', 'exists:brands,id'],
            'q' => ['nullable', 'string', 'max:120'],

            // Only on apply: the exact rows the operator approved.
            'rows' => ['sometimes', 'array', 'max:2000'],
            'rows.*.variant_id' => ['required', 'integer'],
            'rows.*.name' => ['required', 'string'],
            'rows.*.from' => ['nullable', 'integer'],
            'rows.*.to' => ['required', 'integer', 'min:0'],
        ];
    }

    /**
     * The operand in the unit the calculation needs.
     *
     * A percentage is unitless; an amount or a fixed price is money, so it converts.
     */
    public function operand(): int
    {
        $value = $this->integer('value');

        if ($this->string('mode')->value() === BulkPriceUpdater::MODE_PERCENT) {
            return $value;
        }

        return $this->string('unit')->value() === Money::UNIT_TOMAN
            ? Money::fromToman($value)
            : $value;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'value.required' => 'مقدار تغییر را وارد کنید.',
            'value.min' => 'کاهش بیش از ۱۰۰ درصد معنا ندارد.',
            'rows.max' => 'در هر بار حداکثر ۲۰۰۰ ردیف قابل اعمال است.',
        ];
    }
}
