@extends('auth.layout')

@section('title', 'ورود')
@section('heading', 'ورود')

@section('subheading')
    {{-- One login page for every shop now (ADR 0017), so there is no shop to name here —
         the tenant is what authenticating produces, not context this page already has. --}}
    @if (session('status'))
        <p class="auth__status">{{ session('status') }}</p>
    @endif
@endsection

@section('form')
    <form method="POST" action="{{ route('login.store') }}" novalidate>
        @csrf

        <div class="auth__fields">
            <div class="auth__field">
                <label for="mobile" class="auth__sr">شماره موبایل</label>
                <input id="mobile" name="mobile" class="auth__input auth__input--ltr" placeholder="شماره موبایل"
                       value="{{ old('mobile') }}" required autofocus inputmode="numeric"
                       autocomplete="username" maxlength="11"
                       @error('mobile') aria-invalid="true" @enderror>
            </div>

            <div class="auth__field">
                <label for="password" class="auth__sr">رمز عبور</label>
                <input id="password" name="password" type="password" class="auth__input auth__input--ltr"
                       placeholder="رمز عبور" required autocomplete="current-password"
                       @error('password') aria-invalid="true" @enderror>
                <button type="button" class="auth__reveal" data-reveal="password" aria-label="نمایش رمز عبور">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z" stroke="currentColor" stroke-width="1.8"/><circle cx="12" cy="12" r="3" stroke="currentColor" stroke-width="1.8"/></svg>
                </button>
            </div>

            {{--
                The security code: the drawing, a refresh control, and the field, in one
                row — the shape of the reference the owner picked.

                The drawing is server-generated SVG, not a third-party widget: reCAPTCHA
                and hCaptcha are served from hosts that are slow or blocked from Iran, and
                the one screen a shopkeeper cannot get past without is the last place to
                put a foreign network dependency. See `App\Support\SecurityCode`.
            --}}
            <div class="auth__field auth__code">
                <button type="button" class="auth__code-refresh" data-security-refresh
                        aria-label="کد امنیتی تازه">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                         stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M3 12a9 9 0 0 1 15-6.7L21 8"/><path d="M21 3v5h-5"/>
                        <path d="M21 12a9 9 0 0 1-15 6.7L3 16"/><path d="M3 21v-5h5"/>
                    </svg>
                </button>

                <span class="auth__code-image" data-security-image>{!! $securityCode !!}</span>

                <label for="security_code" class="auth__sr">کد امنیتی</label>
                <input id="security_code" name="security_code" class="auth__input auth__input--ltr"
                       placeholder="کد امنیتی" required autocomplete="off" inputmode="latin"
                       maxlength="5" @error('security_code') aria-invalid="true" @enderror>
            </div>
        </div>

        <div class="auth__row">
            <label class="auth__check">
                <input type="checkbox" name="remember" value="1" {{ old('remember') ? 'checked' : '' }}>
                <span>مرا به خاطر بسپار</span>
            </label>
            <a href="{{ route('password.request') }}" class="auth__link">فراموشی رمز عبور</a>
        </div>

        <button type="submit" class="btn btn--primary auth__submit">ورود به حساب کاربری</button>

        {{--
            `route()`, not a hand-built URL.

            Before ADR 0017 this page was served on the SHOP's hostname and registration
            lived on the central domain, so the link had to be absolute to the apex. Both
            sit on `app.<apex>` now, and that hand-built URL kept aiming at the landing
            host, where `/register` does not exist — it rendered fine and 404'd on click.
            The route table still resolves the apex from config, never a literal (rule 1b).
        --}}
        <p class="auth__alt">
            حساب ندارید؟
            <a href="{{ route('register') }}">ثبت‌نام</a>
        </p>
    </form>
@endsection
