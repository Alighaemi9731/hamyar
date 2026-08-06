<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Requests;

use App\Modules\Platform\Models\Domain;
use App\Modules\Platform\Services\TenantProvisioner;
use App\Support\Digits;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

final class OnboardTenantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Persian keyboards produce Persian digits, and Iranian mobile numbers get typed
     * with them constantly. Normalising before validation means «۰۹۱۲۱۲۳۴۵۶۷» and
     * "09121234567" behave identically instead of the first failing a regex.
     */
    protected function prepareForValidation(): void
    {
        $subdomain = mb_strtolower(trim($this->string('subdomain')->value()));

        $this->merge([
            'subdomain' => $subdomain,
            // Uniqueness is checked against the full hostname, because that is the
            // column the middleware actually resolves on and the only value that is
            // globally unique. Validating the bare slug would let a future custom
            // domain collide with an existing subdomain.
            'hostname' => Domain::hostnameFor($subdomain),
            'owner_mobile' => Digits::toLatin(trim($this->string('owner_mobile')->value())),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:120'],

            'subdomain' => [
                'required', 'string', 'min:3', 'max:30',
                'regex:/^[a-z0-9](?:[a-z0-9-]{1,28}[a-z0-9])$/',
                'not_regex:/--/',
                Rule::notIn(TenantProvisioner::RESERVED_SUBDOMAINS),
                Rule::unique('tenants', 'slug'),
            ],

            // Derived in prepareForValidation(); never accepted from the client.
            'hostname' => ['required', 'string', Rule::unique('domains', 'hostname')],

            'owner_name' => ['required', 'string', 'min:2', 'max:120'],

            // Iranian mobile format: 09xxxxxxxxx.
            'owner_mobile' => ['required', 'string', 'regex:/^09\d{9}$/'],

            'owner_email' => ['nullable', 'email:rfc', 'max:190'],

            'password' => ['required', 'confirmed', Password::defaults()],

            'accept_terms' => ['accepted'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'subdomain.regex' => 'نشانی فروشگاه فقط می‌تواند شامل حروف انگلیسی، عدد و خط تیره باشد.',
            'subdomain.not_in' => 'این نشانی رزرو شده است. لطفاً نشانی دیگری انتخاب کنید.',
            'subdomain.unique' => 'این نشانی قبلاً گرفته شده است.',
            'hostname.unique' => 'این نشانی قبلاً گرفته شده است.',
            'owner_mobile.regex' => 'شماره موبایل باید با ۰۹ شروع شود و ۱۱ رقم باشد.',
            'accept_terms.accepted' => 'برای ادامه باید قوانین را بپذیرید.',
        ];
    }

    /**
     * @return array{name: string, subdomain: string, owner_name: string, owner_mobile: string, owner_email: string|null, password: string}
     */
    public function provisioningData(): array
    {
        return [
            'name' => (string) $this->string('name'),
            'subdomain' => (string) $this->string('subdomain'),
            'owner_name' => (string) $this->string('owner_name'),
            'owner_mobile' => (string) $this->string('owner_mobile'),
            'owner_email' => $this->filled('owner_email') ? (string) $this->string('owner_email') : null,
            'password' => (string) $this->string('password'),
        ];
    }
}
