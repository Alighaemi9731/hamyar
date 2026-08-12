<?php

declare(strict_types=1);

namespace App\Modules\CRM\Http\Requests;

use App\Modules\CRM\Enums\PartyKind;
use App\Modules\CRM\Models\Party;
use App\Modules\CRM\Models\PartyContact;
use App\Support\Digits;
use App\Support\Money;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Creating or editing a party.
 *
 * Two fields carry decisions worth knowing:
 *
 * - **`credit_limit` is nullable and that is not the same as zero.** Null means nobody
 *   has decided; zero means "no credit", which is a real answer someone chose.
 * - **`national_id` is unique per shop among live rows.** A duplicate is almost always
 *   the same human entered twice, which splits their balance in half and makes both
 *   statements wrong. The rule mirrors the partial index so the operator gets a field
 *   message instead of a database exception.
 */
final class PartyRequest extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        /** @var Party|null $party */
        $party = $this->route('party');

        return [
            'name' => ['required', 'string', 'max:180'],
            'company_name' => ['nullable', 'string', 'max:180'],
            'kind' => ['required', Rule::enum(PartyKind::class)],

            'national_id' => [
                'nullable', 'string', 'digits:10',
                Rule::unique('parties', 'national_id')
                    ->ignore($party?->getKey())
                    ->whereNull('deleted_at'),
            ],
            'economic_code' => ['nullable', 'string', 'max:20'],

            'price_level_id' => ['nullable', 'integer', 'exists:price_levels,id'],

            'credit_limit' => ['nullable', 'integer', 'min:0', 'max:99999999999999'],
            'opening_balance' => ['nullable', 'integer', 'min:-99999999999999', 'max:99999999999999'],
            'unit' => ['required', Rule::in([Money::UNIT_RIAL, Money::UNIT_TOMAN])],

            'birthday' => ['nullable', 'date'],
            'is_active' => ['boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],

            'contacts' => ['present', 'array', 'max:10'],
            'contacts.*.type' => ['required', Rule::in([
                PartyContact::TYPE_MOBILE,
                PartyContact::TYPE_PHONE,
                PartyContact::TYPE_EMAIL,
            ])],
            'contacts.*.value' => ['required', 'string', 'max:120'],
            'contacts.*.label' => ['nullable', 'string', 'max:60'],
        ];
    }

    /**
     * The party columns, with money converted to integer rial.
     *
     * @return array<string, mixed>
     */
    public function partyAttributes(): array
    {
        $toman = $this->string('unit')->value() === Money::UNIT_TOMAN;

        $creditLimit = $this->input('credit_limit');
        $hasLimit = $creditLimit !== null && $creditLimit !== '';
        $limit = $hasLimit ? $this->integer('credit_limit') : null;
        $opening = $this->integer('opening_balance');

        return [
            'name' => $this->string('name')->trim()->value(),
            'company_name' => $this->filled('company_name') ? $this->string('company_name')->trim()->value() : null,
            'kind' => $this->string('kind')->value(),
            'national_id' => $this->filled('national_id')
                ? Digits::toLatin($this->string('national_id')->value())
                : null,
            'economic_code' => $this->filled('economic_code') ? $this->string('economic_code')->value() : null,
            'price_level_id' => $this->integer('price_level_id') ?: null,
            // Null survives as null: "nobody decided" is a different fact from zero.
            'credit_limit' => $limit === null ? null : ($toman ? Money::fromToman($limit) : $limit),
            'opening_balance' => $toman ? Money::fromToman($opening) : $opening,
            'birthday' => $this->filled('birthday') ? $this->string('birthday')->value() : null,
            'is_active' => $this->boolean('is_active'),
            'notes' => $this->filled('notes') ? $this->string('notes')->value() : null,
        ];
    }

    /**
     * Contact rows, primary-flagged one per type.
     *
     * The first row of each type wins the primary flag. A partial unique index enforces
     * one primary per (party, type), so letting the form send two would be a database
     * error rather than a validation message.
     *
     * @return list<array{type: string, value: string, label: string|null, is_primary: bool}>
     */
    public function contacts(): array
    {
        /** @var list<array{type?: string, value?: string, label?: string|null}> $input */
        $input = $this->input('contacts', []);

        $contacts = [];
        $seenTypes = [];

        foreach ($input as $contact) {
            $value = trim($contact['value'] ?? '');
            $type = $contact['type'] ?? PartyContact::TYPE_MOBILE;

            if ($value === '') {
                continue;
            }

            $contacts[] = [
                'type' => $type,
                // PartyContact normalises digits and strips separators on save; this
                // just avoids sending an obviously padded string.
                'value' => $value,
                'label' => $contact['label'] ?? null,
                'is_primary' => ! isset($seenTypes[$type]),
            ];

            $seenTypes[$type] = true;
        }

        return $contacts;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'نام طرف حساب را بنویسید.',
            'kind.required' => 'نوع طرف حساب را انتخاب کنید.',
            'national_id.digits' => 'کد ملی باید ۱۰ رقم باشد.',
            'national_id.unique' => 'این کد ملی قبلاً برای طرف حساب دیگری ثبت شده است.',
            'contacts.*.value.required' => 'شماره یا نشانی تماس را بنویسید.',
            'credit_limit.min' => 'سقف اعتبار نمی‌تواند منفی باشد.',
        ];
    }
}
