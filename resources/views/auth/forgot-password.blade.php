@extends('auth.layout')

@section('title', __('auth.forgot.title'))
@section('heading', __('auth.forgot.title'))

@section('subheading')
    <p class="auth__hint">{{ __('auth.forgot.hint') }}</p>

    {{--
        Deliberately the SAME confirmation whether or not the number has an account.

        `PasswordResetController::store()` carries the reasoning: an unresolved number and
        a resolved one with no active account take visibly different code paths and end at
        one flash, because anything else makes this form an oracle for "does this person
        work at this shop?" — and since ADR 0017 the answer would be about the whole
        platform rather than about one shop.

        Read from the session and from nowhere else, so no URL can make the page say it.
    --}}
    @if (session('success'))
        <p class="auth__status">{{ session('success') }}</p>
    @endif
@endsection

@section('form')
    <form method="POST" action="{{ route('password.email') }}" novalidate>
        @csrf

        <div class="auth__fields">
            <div class="auth__field">
                <label for="identifier" class="auth__sr">{{ __('auth.forgot.mobile') }}</label>
                {{-- `inputmode="numeric"` raises the digit pad; the value is normalised to
                     Latin digits server-side by `Digits::toLatin`, so a Persian keyboard
                     is accepted too. --}}
                <input id="identifier" name="identifier" class="auth__input auth__input--ltr"
                       placeholder="{{ __('auth.forgot.mobile') }}" value="{{ old('identifier') }}"
                       required autofocus inputmode="numeric" autocomplete="username" maxlength="11"
                       @error('identifier') aria-invalid="true" @enderror>
            </div>
        </div>

        <button type="submit" class="btn btn--primary auth__submit">{{ __('auth.forgot.submit') }}</button>

        <p class="auth__alt">
            <a href="{{ route('login') }}">{{ __('auth.forgot.back') }}</a>
        </p>
    </form>
@endsection
