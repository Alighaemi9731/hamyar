<?php

declare(strict_types=1);

namespace App\Modules\Cheques\Http\Requests;

use App\Modules\Cheques\Enums\ChequeDirection;
use App\Modules\CRM\Models\Account;
use App\Support\Digits;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * What a shopkeeper types off the paper in their hand.
 *
 * The fields are the ones actually printed on an Iranian cheque — serial, sayad id, bank,
 * account holder, amount and due date — plus who it is with. Everything else about a
 * cheque's life is a transition, not a field.
 *
 * ## Why `sayad_id` is optional but shape-checked
 *
 * Every cheque issued since 1400 carries a ۱۶-رقمی صیاد identifier, and a shop that records
 * it can later answer «این چک را کجا خرج کردیم» from the number alone. Older paper has none,
 * so requiring it would refuse a legitimate cheque; validating its shape when present is
 * what stops a mistyped one being recorded as fact.
 */
final class ChequeRequest extends FormRequest
{
    /**
     * Persian digits in, Latin digits validated.
     *
     * The whole product's numeric input arrives in Persian digits — a serial, an amount and
     * a due date typed on a Persian keypad have to satisfy the same rules as Latin ones.
     * Normalising before validation is what keeps each rule below a single rule.
     */
    protected function prepareForValidation(): void
    {
        foreach (['serial', 'sayad_id', 'amount'] as $field) {
            if ($this->has($field) && is_string($this->input($field))) {
                $this->merge([$field => Digits::toLatin($this->string($field)->value())]);
            }
        }
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'direction' => ['required', Rule::enum(ChequeDirection::class)],

            // The counterparty. Required in both directions: a cheque with no party is one
            // whose exposure cannot be counted against anybody, which is the whole reason
            // `ChequeExposure` exists.
            'party_id' => ['required', 'integer', 'exists:parties,id'],

            'amount' => ['required', 'integer', 'min:1', 'max:99999999999999'],

            'serial' => ['required', 'string', 'max:40'],
            'sayad_id' => ['nullable', 'string', 'digits:16'],
            'bank_name' => ['required', 'string', 'max:60'],
            'branch_name' => ['nullable', 'string', 'max:60'],
            'account_holder' => ['nullable', 'string', 'max:120'],

            /*
            | UTC instants, because that is what `<JDatePicker>` emits and what golden rule 5
            | stores. The picker renders and parses Jalali for the shopkeeper; the wire
            | format is never a Jalali string, so nothing downstream has to know which
            | calendar the person at the keyboard was using.
            */
            'due_date' => ['required', 'date'],
            'received_at' => ['nullable', 'date'],

            // Only meaningful for an issued cheque — the bank it is drawn on. Validated as
            // a real bank account here so `RegisterCheque` never has to guess.
            'account_id' => [
                Rule::requiredIf(fn (): bool => $this->input('direction') === ChequeDirection::Issued->value),
                'nullable',
                'integer',
                Rule::exists('accounts', 'id')->where('type', Account::TYPE_BANK)->where('is_active', true),
            ],

            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'party_id.required' => 'طرف حساب چک را انتخاب کنید.',
            'amount.required' => 'مبلغ چک را وارد کنید.',
            'amount.min' => 'مبلغ چک باید بیشتر از صفر باشد.',
            'serial.required' => 'شمارهٔ سریال چک را وارد کنید.',
            'sayad_id.digits' => 'شناسهٔ صیاد باید دقیقاً ۱۶ رقم باشد.',
            'bank_name.required' => 'نام بانک را وارد کنید.',
            'due_date.required' => 'تاریخ سررسید چک را وارد کنید.',
            'due_date.date' => 'تاریخ سررسید معتبر نیست.',
            'received_at.date' => 'تاریخ دریافت معتبر نیست.',
            'account_id.required' => 'برای چک صادره، حساب بانکی مبدأ را انتخاب کنید.',
            'account_id.exists' => 'حساب انتخاب‌شده یک حساب بانکی فعال نیست.',
        ];
    }

    /**
     * The columns `RegisterCheque` writes, with both dates already converted to UTC.
     *
     * Conversion happens here rather than in the service because this is the boundary the
     * Jalali string arrives at — golden rule 5 says store UTC, and the further that string
     * travels the more places have to remember to convert it.
     *
     * **Not named `attributes()`**, which is Laravel's own hook for validation *labels*:
     * overriding it with a data payload would silently replace every field name in every
     * message on this form with a column value.
     *
     * @return array<string, mixed>
     */
    public function columns(): array
    {
        /** @var array<string, mixed> $data */
        $data = $this->safe()->except(['direction', 'account_id', 'due_date', 'received_at']);

        $data['due_date'] = CarbonImmutable::parse($this->string('due_date')->value());

        $received = $this->string('received_at')->value();

        if ($received !== '') {
            $data['received_at'] = CarbonImmutable::parse($received);
        }

        return $data;
    }
}
