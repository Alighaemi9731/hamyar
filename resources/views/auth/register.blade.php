@extends('auth.layout')

@section('title', 'ثبت‌نام')
@section('heading', 'ثبت‌نام')

@section('form')
    <form method="POST" action="{{ route('register.store') }}" novalidate>
        @csrf

        <div class="auth__grid auth__grid--two">
            <div class="auth__field">
                <label for="name" class="sr-only" style="position:absolute;inset-inline-start:-9999px">نام فروشگاه</label>
                <input id="name" name="name" class="auth__input" placeholder="نام فروشگاه"
                       value="{{ old('name') }}" required autocomplete="organization"
                       @error('name') aria-invalid="true" @enderror>
            </div>

            <div class="auth__field">
                <label for="owner_name" style="position:absolute;inset-inline-start:-9999px">نام و نام خانوادگی</label>
                <input id="owner_name" name="owner_name" class="auth__input" placeholder="نام و نام خانوادگی"
                       value="{{ old('owner_name') }}" required autocomplete="name"
                       @error('owner_name') aria-invalid="true" @enderror>
            </div>

            <div class="auth__field">
                <label for="owner_mobile" style="position:absolute;inset-inline-start:-9999px">شماره موبایل</label>
                {{-- inputmode numeric raises the digit pad; the value is normalised to Latin
                     digits server-side, so a Persian keyboard is accepted too. --}}
                <input id="owner_mobile" name="owner_mobile" class="auth__input auth__input--ltr"
                       placeholder="شماره موبایل" value="{{ old('owner_mobile') }}" required
                       inputmode="numeric" autocomplete="tel" maxlength="11"
                       @error('owner_mobile') aria-invalid="true" @enderror>
            </div>

            <div class="auth__field">
                <label for="owner_email" style="position:absolute;inset-inline-start:-9999px">ایمیل (اختیاری)</label>
                <input id="owner_email" name="owner_email" type="email" class="auth__input auth__input--ltr"
                       placeholder="ایمیل (اختیاری)" value="{{ old('owner_email') }}"
                       autocomplete="email" @error('owner_email') aria-invalid="true" @enderror>
            </div>

            <div class="auth__field">
                <label for="password" style="position:absolute;inset-inline-start:-9999px">رمز عبور</label>
                <input id="password" name="password" type="password" class="auth__input auth__input--ltr"
                       placeholder="رمز عبور" required autocomplete="new-password"
                       @error('password') aria-invalid="true" @enderror>
                <button type="button" class="auth__reveal" data-reveal="password" aria-label="نمایش رمز عبور">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z" stroke="currentColor" stroke-width="1.8"/><circle cx="12" cy="12" r="3" stroke="currentColor" stroke-width="1.8"/></svg>
                </button>
            </div>

            <div class="auth__field">
                <label for="password_confirmation" style="position:absolute;inset-inline-start:-9999px">تکرار رمز عبور</label>
                <input id="password_confirmation" name="password_confirmation" type="password"
                       class="auth__input auth__input--ltr" placeholder="تکرار رمز عبور" required autocomplete="new-password">
                <button type="button" class="auth__reveal" data-reveal="password_confirmation" aria-label="نمایش رمز عبور">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z" stroke="currentColor" stroke-width="1.8"/><circle cx="12" cy="12" r="3" stroke="currentColor" stroke-width="1.8"/></svg>
                </button>
            </div>
        </div>

        <label class="auth__check">
            <input type="checkbox" name="accept_terms" value="1" {{ old('accept_terms') ? 'checked' : '' }} required>
            <span>
                <a href="{{ route('legal.terms') }}" target="_blank" rel="noopener">شرایط و قوانین</a>
                استفاده و
                <a href="{{ route('legal.privacy') }}" target="_blank" rel="noopener">حریم خصوصی</a>
                را مطالعه کرده و قبول دارم.
            </span>
        </label>

        <button type="submit" class="btn btn--primary auth__submit">ثبت‌نام</button>

        <p class="auth__alt">
            حساب دارید؟
            <a href="{{ route('login') }}">ورود به حساب کاربری</a>
        </p>
    </form>
@endsection
