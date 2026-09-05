@extends('auth.layout')

@section('title', 'ثبت‌نام')
@section('heading', 'ثبت‌نام')

@section('form')
    <form method="POST" action="{{ route('register.store') }}" novalidate>
        @csrf

        <div class="auth__fields auth__fields--two">
            <div class="auth__field">
                <label for="name" class="auth__sr">نام فروشگاه</label>
                <input id="name" name="name" class="auth__input" placeholder="نام فروشگاه"
                       value="{{ old('name') }}" required autocomplete="organization"
                       @error('name') aria-invalid="true" @enderror>
            </div>

            <div class="auth__field">
                <label for="owner_name" class="auth__sr">نام و نام خانوادگی</label>
                <input id="owner_name" name="owner_name" class="auth__input" placeholder="نام و نام خانوادگی"
                       value="{{ old('owner_name') }}" required autocomplete="name"
                       @error('owner_name') aria-invalid="true" @enderror>
            </div>

            <div class="auth__field">
                <label for="owner_mobile" class="auth__sr">شماره موبایل</label>
                {{-- inputmode numeric raises the digit pad; the value is normalised to Latin
                     digits server-side, so a Persian keyboard is accepted too. --}}
                <input id="owner_mobile" name="owner_mobile" class="auth__input auth__input--ltr"
                       placeholder="شماره موبایل" value="{{ old('owner_mobile') }}" required
                       inputmode="numeric" autocomplete="tel" maxlength="11"
                       @error('owner_mobile') aria-invalid="true" @enderror>
            </div>

            <div class="auth__field">
                <label for="owner_email" class="auth__sr">ایمیل (اختیاری)</label>
                <input id="owner_email" name="owner_email" type="email" class="auth__input auth__input--ltr"
                       placeholder="ایمیل (اختیاری)" value="{{ old('owner_email') }}"
                       autocomplete="email" @error('owner_email') aria-invalid="true" @enderror>
            </div>

            <div class="auth__field">
                <label for="password" class="auth__sr">رمز عبور</label>
                <input id="password" name="password" type="password"
                       class="auth__input auth__input--ltr auth__input--reveal"
                       placeholder="رمز عبور" required autocomplete="new-password"
                       @error('password') aria-invalid="true" @enderror>
                @include('auth.reveal', ['field' => 'password'])
            </div>

            <div class="auth__field">
                <label for="password_confirmation" class="auth__sr">تکرار رمز عبور</label>
                <input id="password_confirmation" name="password_confirmation" type="password"
                       class="auth__input auth__input--ltr auth__input--reveal"
                       placeholder="تکرار رمز عبور" required autocomplete="new-password">
                {{-- Its own label, so a screen reader can tell the two eyes apart. --}}
                @include('auth.reveal', ['field' => 'password_confirmation', 'label' => 'تکرار رمز عبور'])
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
