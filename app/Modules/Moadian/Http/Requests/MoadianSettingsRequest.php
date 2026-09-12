<?php

declare(strict_types=1);

namespace App\Modules\Moadian\Http\Requests;

use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The شناسه حافظه مالیاتی, the economic code, the private key and the shop's own switch.
 *
 * ## A blank key means "unchanged", not "empty"
 *
 * This is the contract `MoadianSetting`'s docblock writes down, and it is the whole reason
 * the field is `nullable` rather than `required`: the controller never echoes the stored key
 * back to the browser, so the form always renders that box empty. Treating an empty submit
 * as "clear it" would wipe a working shop's credentials every time somebody saved a change
 * to the economic code beside it.
 *
 * ## Why `is_enabled` is validated but the platform switch is not here
 *
 * A shop may turn its own participation on; whether anything is actually submitted is
 * `config('moadian.enabled')`, which is ours and not theirs (ADR 0011). The screen shows
 * both states apart, so a shop that has switched on and sees nothing happening gets an
 * answer rather than a mystery.
 */
final class MoadianSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && $user->can('settings.update');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            /*
            | The tax memory id is issued by سامانه مودیان and is not free-form. Length is
            | bounded rather than pattern-matched: the format is the regulator's to change,
            | and a shop locked out of saving a valid id by our regex is a worse failure
            | than a shop saving a typo it can correct.
            */
            'memory_id' => ['nullable', 'string', 'max:64'],
            'economic_code' => ['nullable', 'string', 'max:64'],
            // See the class docblock: blank is "leave it alone".
            'private_key' => ['nullable', 'string', 'max:8192'],
            'is_enabled' => ['required', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'memory_id' => 'شناسهٔ حافظهٔ مالیاتی',
            'economic_code' => 'کد اقتصادی',
            'private_key' => 'کلید خصوصی',
            'is_enabled' => 'ارسال صورتحساب‌ها',
        ];
    }
}
