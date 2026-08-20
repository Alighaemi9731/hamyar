@extends('auth.layout')

@section('title', 'ورود')
@section('heading', 'ورود')

@section('form')
    <form method="POST" action="{{ route('login.store') }}" novalidate>
        @csrf

        <div class="auth__grid">
            <div class="auth__field">
                <label for="mobile" style="position:absolute;inset-inline-start:-9999px">شماره موبایل</label>
                <input id="mobile" name="mobile" class="auth__input auth__input--ltr" placeholder="شماره موبایل"
                       value="{{ old('mobile') }}" required autofocus inputmode="numeric"
                       autocomplete="username" maxlength="11"
                       @error('mobile') aria-invalid="true" @enderror>
            </div>

            <div class="auth__field">
                <label for="password" style="position:absolute;inset-inline-start:-9999px">رمز عبور</label>
                <input id="password" name="password" type="password" class="auth__input auth__input--ltr"
                       placeholder="رمز عبور" required autocomplete="current-password"
                       @error('password') aria-invalid="true" @enderror>
                <button type="button" class="auth__reveal" data-reveal="password" aria-label="نمایش رمز عبور">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z" stroke="currentColor" stroke-width="1.8"/><circle cx="12" cy="12" r="3" stroke="currentColor" stroke-width="1.8"/></svg>
                </button>
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

        <p class="auth__alt">
            <a href="{{ route('register') }}">ثبت نام</a>
        </p>
    </form>
@endsection
