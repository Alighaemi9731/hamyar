<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Requests;

use App\Modules\Catalog\Enums\ProductType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Creating or editing a catalogue line.
 *
 * `type` is the fork in the road for the whole system — a serialized product grows
 * `product_units` with IMEIs, a standard one is a quantity — so it is validated
 * against the enum rather than accepted as a string.
 */
final class ProductRequest extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:180'],
            'sku' => ['nullable', 'string', 'max:64'],
            'type' => ['required', Rule::enum(ProductType::class)],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'brand_id' => ['nullable', 'integer', 'exists:brands,id'],
            'description' => ['nullable', 'string', 'max:2000'],
            // Per product, not global: a shop wants warning at 2 chargers and not at
            // 2 flagship handsets, which is normal stock for those.
            'low_stock_threshold' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'is_active' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'نام کالا را بنویسید.',
            'type.required' => 'نوع کالا را انتخاب کنید: عادی یا سریال‌دار.',
            'category_id.exists' => 'دسته انتخاب‌شده پیدا نشد.',
            'brand_id.exists' => 'برند انتخاب‌شده پیدا نشد.',
            'low_stock_threshold.min' => 'حد هشدار موجودی نمی‌تواند منفی باشد.',
        ];
    }
}
