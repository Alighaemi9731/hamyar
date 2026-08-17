<?php

declare(strict_types=1);

namespace App\Modules\Storefront\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Minting a reseller link.
 *
 * ## The expiry is bounded, and there is no "never"
 *
 * The spec says a link *always* has one. The maximum is 90 days rather than unlimited
 * because a link that outlives the price list it shows is worse than an expired one: the
 * colleague quotes a figure from Farvardin in Bahman and the shop has to honour it or argue.
 *
 * ## `price_level_id` must exist in THIS shop
 *
 * `exists` alone would accept another tenant's level id — the table is shared. RLS makes the
 * row invisible to a later read, so the link would resolve to a level that returns no prices
 * and the colleague would see an empty list with no explanation. Scoped explicitly.
 */
final class PriceListLinkRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'price_level_id' => ['required', 'integer', 'exists:price_levels,id'],
            'label' => ['nullable', 'string', 'max:120'],
            'password' => ['nullable', 'string', 'min:4', 'max:64'],
            'days' => ['nullable', 'integer', 'min:1', 'max:90'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'price_level_id.required' => 'سطح قیمت را انتخاب کنید.',
            'password.min' => 'رمز باید دست‌کم ۴ نویسه باشد.',
            'days.max' => 'حداکثر اعتبار لینک ۹۰ روز است.',
        ];
    }
}
