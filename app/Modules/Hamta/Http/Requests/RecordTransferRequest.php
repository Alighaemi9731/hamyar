<?php

declare(strict_types=1);

namespace App\Modules\Hamta\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Recording (or reopening) an ownership transfer.
 *
 * ## The activation id is length-checked and nothing more
 *
 * It is a string copied off the customer's SMS. A format rule — a digit count, a prefix —
 * would assert that this product knows what همتا issues, which it does not: there is no API
 * and no published contract to check against. A rejected id would send a salesperson hunting
 * for a bug with a customer standing at the counter, and the shop's record would be worse
 * for it. So: not empty, not absurdly long, stored verbatim.
 */
final class RecordTransferRequest extends FormRequest
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
            // Optional: a shop often watches the transfer complete on the customer's phone
            // before the SMS with the id in it is forwarded to them.
            'activation_id' => ['nullable', 'string', 'max:64'],
            'note' => ['nullable', 'string', 'max:1000'],
            'reopen' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'activation_id.max' => 'شناسهٔ فعال‌سازی بیش از حد بلند است.',
            'note.max' => 'توضیح نباید بیش از ۱۰۰۰ نویسه باشد.',
        ];
    }
}
