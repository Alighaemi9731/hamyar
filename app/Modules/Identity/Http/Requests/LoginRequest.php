<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Requests;

use App\Support\Digits;
use App\Support\SecurityCode;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class LoginRequest extends FormRequest
{
    private const MAX_ATTEMPTS = 5;

    private const DECAY_SECONDS = 60;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'mobile' => Digits::toLatin(trim($this->string('mobile')->value())),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'mobile' => ['required', 'string'],
            'password' => ['required', 'string'],
            'remember' => ['boolean'],
            /*
            | The security code is checked in `withValidator` rather than by a rule,
            | because checking it CONSUMES it: `SecurityCode::check()` pulls the code out
            | of the session so one drawing answers one attempt. A rule that runs on every
            | validation pass would burn the code before the other fields were read, and
            | a valid sign-in would fail on its own captcha.
            */
            'security_code' => ['required', 'string'],
        ];
    }

    /**
     * The security code, checked once, before anything expensive.
     *
     * Failing here rather than in `rules()` keeps one drawing to one attempt — see the
     * note on the rule. The message names the field and what to do, not «کد اشتباه است»:
     * the code is regenerated on the redirect, so "try the new one" is the whole
     * instruction.
     */
    public function withValidator(\Illuminate\Validation\Validator $validator): void
    {
        $validator->after(function (\Illuminate\Validation\Validator $validator): void {
            if ($validator->errors()->has('security_code')) {
                return;
            }

            if (! app(SecurityCode::class)->check($this->string('security_code')->value())) {
                $validator->errors()->add('security_code', 'کد امنیتی درست نیست. کد تازه‌ای نشان داده شد؛ همان را وارد کنید.');
            }
        });
    }

    /**
     * @return array{mobile: string, password: string}
     */
    public function credentials(): array
    {
        return [
            'mobile' => (string) $this->string('mobile'),
            'password' => (string) $this->string('password'),
        ];
    }

    /**
     * Throttle key: tenant + mobile + IP.
     *
     * Including the tenant means one shop being attacked cannot lock out the same
     * mobile number at a different shop. Including the IP means a single attacker
     * cannot lock a legitimate user out of their own account by guessing at their
     * number from elsewhere — the common denial-of-service side effect of naive login
     * throttling.
     */
    public function throttleKey(): string
    {
        $tenantId = app(TenantContext::class)->id() ?? 'central';

        return Str::transliterate("login|{$tenantId}|".$this->string('mobile').'|'.$this->ip());
    }

    public function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), self::MAX_ATTEMPTS)) {
            return;
        }

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'mobile' => "تلاش‌های ناموفق زیاد بوده است. لطفاً {$seconds} ثانیه دیگر دوباره تلاش کنید.",
        ]);
    }

    public function recordFailedAttempt(): void
    {
        RateLimiter::hit($this->throttleKey(), self::DECAY_SECONDS);
    }

    public function clearRateLimit(): void
    {
        RateLimiter::clear($this->throttleKey());
    }
}
