<?php

declare(strict_types=1);

namespace App\Modules\Storefront\Http\Requests;

use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The public page's settings.
 *
 * `slug` is unique across ALL tenants, not per tenant — it is a public path segment, and two
 * shops claiming `mobile-iran` would be one shop's page answering for the other's.
 */
final class StorefrontSettingsRequest extends FormRequest
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
            'is_enabled' => ['boolean'],
            'slug' => [
                'nullable', 'string', 'max:60',
                // Lowercase latin, digits, dashes: it goes in a URL that a shopkeeper reads
                // aloud down the phone.
                'regex:/^[a-z0-9\-]+$/',
                Rule::unique('storefront_settings', 'slug')->ignore(app(TenantContext::class)->id(), 'tenant_id'),
            ],
            'display_name' => ['nullable', 'string', 'max:120'],
            'about' => ['nullable', 'string', 'max:2000'],
            'address' => ['nullable', 'string', 'max:500'],
            'phone' => ['nullable', 'string', 'max:30'],
            'whatsapp' => ['nullable', 'string', 'max:30'],
            'working_hours' => ['nullable', 'string', 'max:200'],
            'shows_out_of_stock' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'slug.regex' => 'نشانی فقط می‌تواند حروف کوچک لاتین، رقم و خط تیره باشد.',
            'slug.unique' => 'این نشانی قبلاً گرفته شده است.',
        ];
    }
}
