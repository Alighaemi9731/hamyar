<?php

declare(strict_types=1);

namespace App\Modules\Settings\Http\Requests;

use App\Modules\Messaging\Enums\AutomationKey;
use App\Support\Settings\ShopSettings;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Saving «پیامک».
 *
 * ## The switches travel as a list of what is ON, not as a map
 *
 * The obvious wire format — `automations[invoice.issued] = true` — cannot work here:
 * `AutomationKey` values contain a dot, and a dot is Laravel's path separator in a
 * validation rule, in `data_get()` and in the error bag. `automations.invoice.issued`
 * addresses a key called `issued` inside a key called `invoice`, which is not the field.
 *
 * So the request carries the ticked keys as a plain list and
 * {@see \App\Modules\Settings\Services\ShopSettingsWriter} turns it back into a full map
 * with a real boolean for every case. That also means an unticked automation is stored as
 * an explicit `false` rather than being absent, which matters: `MessagingSettings` reads
 * `=== true`, so absent and false behave identically today — but only one of them says
 * out loud that the shop was asked and answered.
 *
 * `present` rather than `required`: unticking every switch is a legitimate instruction,
 * and the empty list has to reach the server as an instruction rather than as a missing
 * key. This form carries no file, so Inertia sends it as JSON and an empty array survives
 * the trip — the `FormData` hazard CLAUDE.md records applies to multipart forms, which
 * cannot express one.
 *
 * ## The quiet window is validated as a window
 *
 * `MessagingSettings::isQuietAt()` is `$hour < $until || $hour >= $from`. Give it
 * `until: 21, from: 9` and every hour of the day satisfies one side or the other: the
 * shop's swept messages stop entirely, with no error anywhere and nothing on any screen
 * to explain it. Equal hours do the same. So `quiet_from_hour` must be strictly later
 * than `quiet_until_hour`, and the refusal says what the numbers mean rather than
 * repeating the rule.
 */
final class MessagingSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', ShopSettings::class) === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'automations' => ['present', 'array'],
            'automations.*' => [
                'string',
                Rule::in(array_column(AutomationKey::cases(), 'value')),
            ],
            'quiet_until_hour' => ['required', 'integer', 'between:0,23'],
            'quiet_from_hour' => ['required', 'integer', 'between:0,23', 'gt:quiet_until_hour'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'automations.present' => 'فهرست پیامک‌های خودکار ارسال نشد. صفحه را دوباره باز کنید و ذخیره را بزنید.',
            'automations.*.in' => 'یکی از پیامک‌های خودکار انتخاب‌شده شناخته نشد. صفحه را تازه کنید.',
            'quiet_until_hour.between' => 'ساعت شروع ارسال باید عددی بین ۰ تا ۲۳ باشد.',
            'quiet_from_hour.between' => 'ساعت پایان ارسال باید عددی بین ۰ تا ۲۳ باشد.',
            'quiet_from_hour.gt' => 'ساعت پایان ارسال باید بعد از ساعت شروع باشد؛ در غیر این صورت هیچ پیامک زمان‌بندی‌شده‌ای ارسال نخواهد شد.',
        ];
    }
}
