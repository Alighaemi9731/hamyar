<?php

declare(strict_types=1);

namespace App\Modules\Repairs\Http\Requests;

use App\Modules\Repairs\Models\RepairTicket;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Taking a device in.
 *
 * ## The IMEI is validated loosely on purpose
 *
 * Fifteen digits copied off a cracked screen by a clerk with a customer waiting is
 * wrong often enough to matter. A Luhn check here would refuse the intake and send a
 * paying customer away over a typo; the number is **evidence**, not a key, so it is
 * normalised and stored as given. Contrast the trade-in form, where the IMEI becomes a
 * `product_unit` the shop will later sell and a wrong digit is a device that cannot be
 * found — that one is Luhn-checked.
 *
 * ## The passcode is write-only from here on
 *
 * It arrives once, in this request, and is never sent back to a browser by any code path
 * except the audited reveal endpoint.
 */
final class TicketIntakeRequest extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'party_id' => ['nullable', 'integer', 'exists:parties,id'],
            'unit' => ['required', Rule::in([Money::UNIT_RIAL, Money::UNIT_TOMAN])],

            'device_brand' => ['nullable', 'string', 'max:80'],
            'device_model' => ['required', 'string', 'max:120'],
            // Length only. See the class docblock for why this is not Luhn-checked.
            'device_imei' => ['nullable', 'string', 'max:20'],
            'device_colour' => ['nullable', 'string', 'max:40'],

            'reported_issue' => ['required', 'string', 'max:2000'],

            'technician_id' => ['nullable', 'integer', 'exists:users,id'],
            'priority' => ['required', 'integer', Rule::in([
                RepairTicket::PRIORITY_URGENT,
                RepairTicket::PRIORITY_NORMAL,
                RepairTicket::PRIORITY_LOW,
            ])],
            'promised_at' => ['nullable', 'date'],

            'estimate_amount' => ['required', 'integer', 'min:0', 'max:99999999999'],
            'prepaid_amount' => ['required', 'integer', 'min:0', 'max:99999999999'],

            // Short: an unlock code or a pattern sequence, never a sentence. A generous
            // limit here would invite somebody to paste something else into it.
            'device_passcode' => ['nullable', 'string', 'max:64'],

            'accessories' => ['present', 'array', 'max:20'],
            'accessories.*' => ['string', 'max:40'],

            'checklist' => ['present', 'array', 'max:50'],
            'checklist.*.item_key' => ['required', 'string', 'max:80'],
            'checklist.*.label' => ['required', 'string', 'max:160'],
            'checklist.*.answer' => ['required', 'string', 'max:40'],
            'checklist.*.note' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array{branch_id: int, party_id: int|null, device_brand: string|null, device_model: string, device_imei: string|null, device_colour: string|null, reported_issue: string, technician_id: int|null, priority: int, promised_at: CarbonImmutable|null, estimate_amount: int, prepaid_amount: int, device_passcode: string|null, accessories: list<string>, checklist: list<array{item_key: string, label: string, answer: string, note: string|null}>}
     */
    public function intake(): array
    {
        /** @var array<int, array<string, mixed>> $checklist */
        $checklist = $this->array('checklist');

        /** @var array<int, mixed> $accessories */
        $accessories = $this->array('accessories');

        $promised = $this->string('promised_at')->value();

        return [
            'branch_id' => $this->integer('branch_id'),
            'party_id' => $this->integer('party_id') ?: null,
            'device_brand' => $this->stringOrNull('device_brand'),
            'device_model' => $this->string('device_model')->value(),
            'device_imei' => $this->stringOrNull('device_imei'),
            'device_colour' => $this->stringOrNull('device_colour'),
            'reported_issue' => $this->string('reported_issue')->value(),
            'technician_id' => $this->integer('technician_id') ?: null,
            'priority' => $this->integer('priority'),
            'promised_at' => $promised === '' ? null : CarbonImmutable::parse($promised),
            'estimate_amount' => $this->rial($this->integer('estimate_amount')),
            'prepaid_amount' => $this->rial($this->integer('prepaid_amount')),
            'device_passcode' => $this->stringOrNull('device_passcode'),
            'accessories' => array_values(array_filter(array_map(
                fn (mixed $item): string => is_string($item) ? trim($item) : '',
                $accessories,
            ), fn (string $item): bool => $item !== '')),
            'checklist' => array_values(array_map(fn (array $item): array => [
                'item_key' => $this->textFrom($item, 'item_key'),
                'label' => $this->textFrom($item, 'label'),
                'answer' => $this->textFrom($item, 'answer'),
                'note' => $this->textFrom($item, 'note') ?: null,
            ], $checklist)),
        ];
    }

    /**
     * One checklist field, coerced once.
     *
     * The validator has already refused anything that is not a string; this exists so
     * the conversion is written in one place rather than as three casts the analyser
     * cannot verify.
     *
     * @param  array<string, mixed>  $item
     */
    private function textFrom(array $item, string $key): string
    {
        $value = $item[$key] ?? null;

        return is_string($value) ? trim($value) : '';
    }

    private function stringOrNull(string $key): ?string
    {
        $value = trim($this->string($key)->value());

        return $value === '' ? null : $value;
    }

    private function rial(int $amount): int
    {
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
            'device_model' => 'مدل دستگاه را وارد کنید.',
            'device_model.required' => 'مدل دستگاه را وارد کنید.',
            'reported_issue.required' => 'ایراد اعلام‌شده توسط مشتری را بنویسید.',
        ];
    }
}
