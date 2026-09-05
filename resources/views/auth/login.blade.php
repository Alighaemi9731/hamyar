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
                <input id="password" name="password" type="password"
                       class="auth__input auth__input--ltr auth__input--reveal"
                       placeholder="رمز عبور" required autocomplete="current-password"
                       @error('password') aria-invalid="true" @enderror>
                @include('auth.reveal', ['field' => 'password'])
            </div>

            {{--
                The «کد امنیتی» row: the field, the drawing, a fresh-code button.

                ## The order, and why it is this one

                Field first. In RTL the first child of the row sits at the reading start,
                so a person meets the box they type into before the picture and the button
                — and the tab order follows, which it did not until 2026-09-05: tabbing out
                of the password landed on «کد امنیتی تازه» and only then on the field, so
                the first thing a keyboard reached was the control that throws the code away.

                ## Three boxes, not one

                Each of the three is its own 3.5rem control, matching the fields above it.
                They used to share a single bordered row with the drawing sunk into it as a
                grey lozenge — a box inside a box, at a third height, which read as a
                rendering fault rather than a control.

                The drawing is server-generated SVG, not a third-party widget: reCAPTCHA
                and hCaptcha are served from hosts that are slow or blocked from Iran, and
                the one screen a shopkeeper cannot get past without is the last place to
                put a foreign network dependency. See `App\Support\SecurityCode` — which
                also says why its glyphs are paths and not text.
            --}}
            <div class="auth__code">
                <div class="auth__field auth__code-entry">
                    <label for="security_code" class="auth__sr">کد امنیتی</label>
                    <input id="security_code" name="security_code" class="auth__input auth__input--ltr"
                           placeholder="کد امنیتی" required autocomplete="off" inputmode="latin"
                           maxlength="5" spellcheck="false" autocapitalize="characters"
                           @error('security_code') aria-invalid="true" @enderror>
                </div>

                <span class="auth__code-image" data-security-image>{!! $securityCode !!}</span>

                {{-- The endpoint travels on the attribute rather than as a path in the
                     bundle: `route()` resolves the apex from config, and rule 1b wants no
                     URL of ours written by hand anywhere, script included. --}}
                <button type="button" class="auth__code-refresh"
                        data-security-refresh="{{ route('login.security-code') }}"
                        aria-label="کد امنیتی تازه" title="کد امنیتی تازه">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                         stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M3 12a9 9 0 0 1 15-6.7L21 8"/><path d="M21 3v5h-5"/>
                        <path d="M21 12a9 9 0 0 1-15 6.7L3 16"/><path d="M3 21v-5h5"/>
                    </svg>
                </button>
            </div>

            {{-- The swap is silent on screen, so it is announced. Empty until it happens. --}}
            <p class="auth__sr" role="status" aria-live="polite" data-security-status></p>
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
