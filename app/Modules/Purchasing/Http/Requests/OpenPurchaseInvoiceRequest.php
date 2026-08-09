<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Opening a shipment.
 *
 * The supplier is optional: a shop recording opening stock it already owned has nobody
 * to have bought it from, and forcing a party there would have someone invent one.
 */
final class OpenPurchaseInvoiceRequest extends FormRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'party_id' => ['nullable', 'integer', 'exists:parties,id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'warehouse_id.required' => 'انبار مقصد را انتخاب کنید.',
            'warehouse_id.exists' => 'انبار انتخاب‌شده پیدا نشد.',
            'party_id.exists' => 'تأمین‌کننده انتخاب‌شده پیدا نشد.',
        ];
    }
}
