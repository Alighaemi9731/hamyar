<?php

declare(strict_types=1);

namespace App\Modules\Installments\Http\Requests;

use App\Modules\Installments\Services\InstallmentScheduler;
use App\Support\Digits;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The four numbers a shop actually decides, plus the optional ضامن.
 *
 * The amount is deliberately absent: it is whatever the invoice still owes, and letting
 * the form name it would allow a schedule that does not match the sale it is written
 * against.
 */
final class InstallmentPlanRequest extends FormRequest
{
    /**
     * Persian digits in, Latin digits validated.
     *
     * A date typed on a Persian keypad (۱۴۰۵/۰۶/۱۵) has to satisfy the same rule as one
     * typed in Latin — normalising before validation rather than after is what makes the
     * regex above a single rule instead of two.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('first_due') && is_string($this->input('first_due'))) {
            $this->merge(['first_due' => Digits::toLatin($this->string('first_due')->value())]);
        }
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'count' => ['required', 'integer', 'min:1', 'max:'.InstallmentScheduler::MAX_INSTALLMENTS],
            'profit_percent' => ['required', 'integer', 'min:0', 'max:100'],
            'interval_months' => ['required', 'integer', 'min:1', 'max:12'],
            // A Jalali string; parsed to a UTC instant in the controller (golden rule 5).
            // Shape-checked here so a typo reaches a Persian sentence rather than the
            // date library's English assertion message.
            'first_due' => ['required', 'string', 'max:20', 'regex:/^\d{4}\/\d{1,2}\/\d{1,2}$/'],
            'guarantor_party_id' => ['nullable', 'integer', 'exists:parties,id'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'count.required' => 'تعداد اقساط را وارد کنید.',
            'count.min' => 'تعداد اقساط باید دست‌کم ۱ باشد.',
            'profit_percent.max' => 'درصد سود نمی‌تواند بیشتر از ۱۰۰ باشد.',
            'first_due.required' => 'تاریخ سررسید اولین قسط را انتخاب کنید.',
            'first_due.regex' => 'تاریخ سررسید معتبر نیست؛ قالب درست مثل ۱۴۰۵/۰۶/۱۵ است.',
        ];
    }
}
